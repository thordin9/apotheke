<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\R2Storage;
use Apotheke\Core\Storage\Storage;
use Generator;
use Throwable;

/**
 * Backups kept by the installation: the one made before each restore. They live in the data storage under
 * `backups/` (DATA_DIR/backups on local disk; on object storage the installation's `backups/` prefix), one folder
 * per backup:
 *
 * - `backups/<id>/part-00001` … the zip in parts of PART_BYTES, so a download reads one part at a time;
 * - `backups/<id>/manifest.json`, written last: a backup is listed only once all its parts are stored.
 *
 * The id is the UTC time and a random suffix (`20261112-093000-1a2b3c`), so ids sort oldest first. After each
 * new backup the oldest beyond `backup.keep` are deleted (manifest first, so a half-deleted one is never listed);
 * exports made for the hosting side are not counted and not deleted.
 *
 * @phpstan-type Manifest array{id:string,created_at:string,kind:string,size:int,sha256:string,parts:int,by:?string}
 */
final class StoredBackups
{
    public const PREFIX = 'backups/';

    /** Bytes per stored part: one object read stays well inside object storage's response limit. */
    public const PART_BYTES = 8 * 1024 * 1024;

    public const KIND_PRE_RESTORE = 'pre-restore';

    /** A backup the hosting side asked for (OpsBackups): kept until the hosting side removes it, never pruned here. */
    public const KIND_EXPORT = 'export';

    public const FAILED = 'The current catalog could not be backed up before the restore, so nothing was restored.';

    private const ID = '/^\d{8}-\d{6}-[a-f0-9]{6}$/D';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly Storage $store,
        private readonly Backups $backups,
        private readonly Clock $clock,
        private readonly int $keep,
        private readonly int $partBytes = self::PART_BYTES,
    ) {
    }

    /** How many stored backups are kept (backup.keep). */
    public function keep(): int
    {
        return $this->keep;
    }

    /**
     * Back up the whole catalog with Backups::create() and keep it; then delete the oldest beyond keep().
     *
     * @return Manifest
     * @throws UserError FAILED when the backup could not be made or stored (nothing is kept then)
     */
    public function store(string $kind, ?string $by): array
    {
        $tmp = $this->backups->tmpDir() . '/stored-' . bin2hex(random_bytes(8)) . '.zip';
        $id = gmdate('Ymd-His', strtotime($this->clock->now() . ' UTC') ?: time()) . '-' . bin2hex(random_bytes(3));
        $parts = 0;
        try {
            $this->backups->create($tmp);
            $size = (int)filesize($tmp);
            $fh = fopen($tmp, 'rb');
            if ($fh === false) {
                throw new \RuntimeException('backup unreadable');
            }
            try {
                while (!feof($fh)) {
                    $bytes = (string)fread($fh, max(1, $this->partBytes));
                    if ($bytes === '' && $parts > 0) {
                        break;
                    }
                    $this->store->write(self::part($id, ++$parts), $bytes);
                }
            } finally {
                fclose($fh);
            }
            $manifest = ['id' => $id, 'created_at' => $this->clock->now(), 'kind' => $kind, 'size' => $size,
                'sha256' => (string)hash_file('sha256', $tmp), 'parts' => $parts, 'by' => $by];
            $this->store->write(self::manifestKey($id), json_encode($manifest, self::JSON));
        } catch (Throwable $e) {
            for ($n = 1; $n <= $parts; $n++) {
                $this->forget(self::part($id, $n));
            }
            $this->removeFolder($id);
            throw new UserError(self::FAILED, 0, $e);
        } finally {
            @unlink($tmp);
        }
        $this->prune();

        return $manifest;
    }

    /** @return list<Manifest> the stored backups, newest first */
    public function all(): array
    {
        $out = [];
        $ids = $this->ids();
        rsort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $m = $this->get($id);
            if ($m !== null) {
                $out[] = $m;
            }
        }

        return $out;
    }

    /** @return Manifest|null */
    public function get(string $id): ?array
    {
        if (preg_match(self::ID, $id) !== 1) {
            return null;
        }
        $raw = $this->store->read(self::manifestKey($id));
        if ($raw === null) {
            return null;
        }
        try {
            $m = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($m) || ($m['id'] ?? null) !== $id || !is_int($m['size'] ?? null) || !is_int($m['parts'] ?? null) || $m['parts'] < 1
            || !is_string($m['created_at'] ?? null) || !is_string($m['kind'] ?? null) || !is_string($m['sha256'] ?? null)) {
            return null;
        }

        return ['id' => $id, 'created_at' => $m['created_at'], 'kind' => $m['kind'], 'size' => $m['size'], 'sha256' => $m['sha256'],
            'parts' => $m['parts'], 'by' => is_string($m['by'] ?? null) ? $m['by'] : null];
    }

    /**
     * The zip's bytes, one part at a time.
     *
     * @param Manifest $m
     * @return Generator<int, string>
     */
    public function bytes(array $m): Generator
    {
        for ($n = 1; $n <= $m['parts']; $n++) {
            $part = $this->store->read(self::part($m['id'], $n));
            if ($part === null) {
                throw new UserError('This backup is no longer complete.', 410);
            }
            yield $part;
        }
    }

    /** Delete the oldest backups beyond keep(). Exports (KIND_EXPORT) are neither counted nor deleted. */
    public function prune(): void
    {
        $ids = array_values(array_filter($this->ids(), fn (string $id): bool => ($m = $this->get($id)) !== null && $m['kind'] !== self::KIND_EXPORT));
        sort($ids, SORT_STRING);
        foreach (array_slice($ids, 0, max(0, count($ids) - $this->keep)) as $id) {
            $m = $this->get($id);
            $this->forget(self::manifestKey($id));
            for ($n = 1; $n <= ($m['parts'] ?? 0); $n++) {
                $this->forget(self::part($id, $n));
            }
            $this->removeFolder($id);
        }
    }

    /** On local disk the backup's folder goes too, once it is empty. */
    private function removeFolder(string $id): void
    {
        if ($this->store instanceof LocalStorage) {
            @rmdir($this->store->pathFor(self::PREFIX . $id));
        }
    }

    /** @return list<string> the ids that have a folder (or object prefix) under backups/ */
    private function ids(): array
    {
        $names = [];
        if ($this->store instanceof R2Storage) {
            $cursor = '';
            do {
                $page = $this->store->list(rtrim(self::PREFIX, '/'), $cursor, 1000);
                foreach ($page['objects'] as $key) {
                    if (str_starts_with($key, self::PREFIX)) {
                        $names[] = explode('/', substr($key, strlen(self::PREFIX)))[0];
                    }
                }
                $cursor = (string)$page['cursor'];
            } while ($page['cursor'] !== null);
        } elseif ($this->store instanceof LocalStorage) {
            $dir = $this->store->pathFor(rtrim(self::PREFIX, '/'));
            $names = is_dir($dir) ? (scandir($dir) ?: []) : [];
        }

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => preg_match(self::ID, $n) === 1)));
    }

    private function forget(string $key): void
    {
        try {
            $this->store->delete($key);
        } catch (Throwable) {
            // Best effort: the next prune tries again.
        }
    }

    private static function part(string $id, int $n): string
    {
        return self::PREFIX . $id . '/part-' . sprintf('%05d', $n);
    }

    private static function manifestKey(string $id): string
    {
        return self::PREFIX . $id . '/manifest.json';
    }
}
