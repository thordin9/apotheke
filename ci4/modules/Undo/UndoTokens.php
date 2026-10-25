<?php

declare(strict_types=1);

namespace Apotheke\Undo;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Database\BaseConnection;
use Closure;

/**
 * Undo tokens (legacy src/undo.php): a snapshot of the affected items before
 * an edit, move or delete, kept for the `undo_seconds` setting. A deleted
 * item's photo and attachment files stay on disk until its token expires;
 * pruning releases them unless something references them again. Putting the
 * items back is the caller's (Catalog), since it saves items.
 */
final class UndoTokens
{
    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param int $seconds the undo_seconds setting (0 = no undo)
     * @param Closure(string): void $deleteImage remove a served photo, its thumbnail and original
     * @param Closure(string): void $deleteAttachment remove an attachment file
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly int $seconds,
        private readonly Closure $deleteImage,
        private readonly Closure $deleteAttachment,
    ) {
    }

    public function enabled(): bool
    {
        return $this->seconds > 0;
    }

    /**
     * Everything needed to put the items back exactly as they are now.
     *
     * @param list<int> $ids
     * @return list<array{row:array<string,mixed>,tags:list<string>,photos:list<array<string,mixed>>,attachments:list<array<string,mixed>>}>
     */
    public function snapshot(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $row = $this->rows('SELECT * FROM items WHERE id = ?', [$id])[0] ?? null;
            if ($row === null) {
                continue;
            }
            $out[] = ['row' => $row,
                'tags' => array_map(static fn (array $r): string => (string)$r['name'], $this->rows('SELECT t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = ? ORDER BY t.name', [$id])),
                'photos' => $this->rows('SELECT file, position, created_at FROM item_photos WHERE item_id = ? ORDER BY position, id', [$id]),
                'attachments' => $this->rows('SELECT file, name, mime, size, created_at FROM item_attachments WHERE item_id = ? ORDER BY id', [$id])];
        }

        return $out;
    }

    /**
     * Files a snapshot of deleted items references (kept while the token lives).
     *
     * @param list<array{row:array<string,mixed>,tags:list<string>,photos:list<array<string,mixed>>,attachments:list<array<string,mixed>>}> $snap
     * @return list<array{0:string,1:string}>
     */
    public static function files(array $snap): array
    {
        $f = [];
        foreach ($snap as $s) {
            foreach ($s['photos'] as $p) {
                $f[] = ['img', (string)$p['file']];
            }
            if (!empty($s['row']['image'])) {
                $f[] = ['img', (string)$s['row']['image']];
            }
            foreach ($s['attachments'] as $a) {
                $f[] = ['att', (string)$a['file']];
            }
        }

        return array_values(array_unique($f, SORT_REGULAR));
    }

    /**
     * Store a token; null when undo is off or nothing was snapshotted.
     *
     * @param list<array<string,mixed>> $snap
     * @param array<string,mixed> $extra
     * @param list<array{0:string,1:string}> $files
     */
    public function create(string $action, string $label, array $snap, array $extra = [], array $files = []): ?string
    {
        if (!$this->enabled() || $snap === []) {
            return null;
        }
        $this->prune();
        $token = bin2hex(random_bytes(16));
        $now = $this->time();
        Writes::check($this->db->table('undo_tokens')->insert([
            'token' => $token, 'action' => $action, 'label' => mb_substr($label, 0, 200),
            'payload' => json_encode(['items' => $snap] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'files' => json_encode($files, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s', $now), 'expires_at' => gmdate('Y-m-d H:i:s', $now + $this->seconds),
        ]), 'undo token');

        return $token;
    }

    /** @return array<string,mixed>|null a live token */
    public function get(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            return null;
        }

        return $this->rows('SELECT * FROM undo_tokens WHERE token = ? AND expires_at > ?', [$token, $this->clock->now()])[0] ?? null;
    }

    /** @param array<string,mixed> $t seconds left */
    public function left(array $t): int
    {
        return max(0, (int)strtotime($t['expires_at'] . ' UTC') - $this->time());
    }

    /** Remove a used token. */
    public function consume(string $token): void
    {
        Writes::check($this->db->table('undo_tokens')->where('token', $token)->delete(), 'use undo token');
    }

    /** Drop expired tokens (all with $all) and release the files they kept. */
    public function prune(bool $all = false): int
    {
        $n = 0;
        foreach ($this->rows('SELECT token, files FROM undo_tokens' . ($all ? '' : ' WHERE expires_at <= ?'), $all ? [] : [$this->clock->now()]) as $t) {
            $this->consume((string)$t['token']);
            $files = json_decode((string)$t['files'], true);
            $this->release(is_array($files) ? $files : []);
            $n++;
        }

        return $n;
    }

    /**
     * The bar for a token given in the URL: label, seconds left and the token, or null.
     *
     * @return array{label:string,left:int,token:string}|null
     */
    public function bar(mixed $token): ?array
    {
        $tok = is_string($token) ? $token : '';
        $t = $tok === '' ? null : $this->get($tok);

        return $t === null ? null : ['label' => (string)$t['label'], 'left' => $this->left($t), 'token' => $tok];
    }

    /** @param array<array-key,mixed> $files */
    private function release(array $files): void
    {
        foreach ($files as $f) {
            [$kind, $file] = is_array($f) ? $f + [null, null] : [null, null];
            if (!is_string($file)) {
                continue;
            }
            if ($kind === 'img' && $this->rows('SELECT 1 AS x FROM item_photos WHERE file = ? UNION ALL SELECT 1 AS x FROM items WHERE image = ?', [$file, $file]) === []) {
                ($this->deleteImage)($file);
            } elseif ($kind === 'att' && $this->rows('SELECT 1 AS x FROM item_attachments WHERE file = ?', [$file]) === []) {
                ($this->deleteAttachment)($file);
            }
        }
    }

    private function time(): int
    {
        $t = strtotime($this->clock->now() . ' UTC');

        return $t !== false ? $t : time();
    }

    /**
     * @param list<int|string|null> $binds
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        return Writes::rows($this->db, $sql, $binds);
    }
}
