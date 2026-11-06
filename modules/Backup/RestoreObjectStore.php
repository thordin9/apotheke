<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Storage\LeaseDenied;
use Apotheke\Core\Storage\ObjectLease;
use Apotheke\Core\Storage\Storage;
use Apotheke\Core\Storage\VersionedStorage;
use Closure;
use Throwable;

/**
 * Chunked restore uploads on one versioned storage. The manifest, the chunks and the lease all use that
 * storage. The lease is taken before a chunk or a manifest write. A conditional manifest write uses the
 * version read with those bytes.
 *
 * @phpstan-type Manifest array{owner:string,size:int,sha256:?string,chunk_size:int,received:int,next:int,status:string,fingerprint?:?string,result?:array<string,mixed>}
 */
final class RestoreObjectStore
{
    private const INDEX = 'uploads/restore/index.json';

    private const CREATE = 'uploads/restore/leases/create';

    private const ID = '/^[a-f0-9]{32}$/D';

    /** @var list<string> */
    private const DONE = ['restored', 'failed', 'interrupted'];

    private readonly ObjectLease $lease;

    /** @var Closure(string):?float */
    private readonly Closure $freeBytes;

    /** @var Closure():int */
    private readonly Closure $now;

    /**
     * @param Closure():?string $owner
     * @param ?Closure(string):?float $freeBytes
     * @param ?Closure():?RestoreStage $stage
     * @param ?Closure():int $now
     */
    public function __construct(
        private readonly Storage&VersionedStorage $storage,
        private readonly int $chunkBytes,
        private readonly int $maxBytes,
        private readonly Closure $owner,
        private readonly int $maxOpen = 8,
        ?Closure $freeBytes = null,
        private readonly ?Closure $stage = null,
        private readonly int $leaseTtl = 3600,
        ?Closure $now = null,
    ) {
        if ($this->leaseTtl < 1) {
            throw new \InvalidArgumentException('A restore lease needs a positive lifetime.');
        }
        $this->lease = new ObjectLease($storage);
        $this->freeBytes = $freeBytes ?? static fn (string $dir): ?float => null;
        $this->now = $now ?? static fn (): int => time();
    }

    public static function manifestKey(string $id): string
    {
        return 'uploads/restore/manifests/' . $id . '.json';
    }

    public static function leaseKey(string $id): string
    {
        return 'uploads/restore/leases/' . $id;
    }

    public static function chunkKey(string $id, int $index): string
    {
        return 'uploads/restore/chunks/' . $id . '/' . $index;
    }

    /** @return array{0:string,1:Manifest} */
    public function create(mixed $size, mixed $sha256): array
    {
        $owner = $this->me();
        if (!is_int($size) || $size < 1) {
            throw new UserError('Give the backup\'s size in bytes.', 422);
        }
        if ($size > $this->maxBytes) {
            throw new UserError('This backup is larger than the restore limit of ' . intdiv($this->maxBytes, 1048576) . ' MB.', 413);
        }
        if ($sha256 !== null && (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1)) {
            throw new UserError('sha256 must be 64 lowercase hex digits.', 422);
        }
        $this->sweep();
        $digest = is_string($sha256) ? $sha256 : null;

        return $this->withCreate(fn (): array => $this->allocate($owner, $size, $digest));
    }

    /** @return Manifest */
    public function get(string $id): array
    {
        return $this->locked($id, function (array $manifest, string $etag) use ($id): array {
            $this->commit(self::manifestKey($id), $etag, $manifest);

            return $manifest;
        });
    }

    /** @return Manifest */
    public function append(string $id, int $index, string $tmp, int $bytes): array
    {
        return $this->locked($id, function (array $manifest, string $etag, Closure $guard) use ($id, $index, $tmp, $bytes): array {
            if ($manifest['status'] !== 'uploading' || $index !== $manifest['next']) {
                throw new UserError($manifest['status'] !== 'uploading' ? 'This upload is complete.' : 'Send chunk ' . $manifest['next'] . ' next.', 409);
            }
            if ($bytes > $manifest['chunk_size'] || $manifest['received'] + $bytes > $manifest['size']) {
                throw new UserError('This chunk is larger than ' . ($bytes > $manifest['chunk_size'] ? 'the chunk size.' : 'the rest of the backup.'), 413);
            }
            if ($bytes < 1 || ($bytes !== $manifest['chunk_size'] && $manifest['received'] + $bytes !== $manifest['size'])) {
                throw new UserError('Every chunk but the last must be exactly ' . $manifest['chunk_size'] . ' bytes.', 422);
            }
            $length = @filesize($tmp);
            $raw = $length === $bytes ? @file_get_contents($tmp) : false;
            if (!is_string($raw) || strlen($raw) !== $bytes) {
                throw new UserError('The chunk could not be stored.', 500);
            }
            $part = self::chunkKey($id, $index);
            $guard($part);
            $this->storage->write($part, $raw);
            $manifest['received'] += $bytes;
            $manifest['next']++;
            $saved = $this->commit(self::manifestKey($id), $etag, $manifest);
            $guard(null);

            return $saved;
        });
    }

    /**
     * @param Closure(string, Manifest):array<string,mixed> $open
     * @return array<string,mixed>
     */
    public function finish(string $id, Closure $open): array
    {
        try {
            return $this->locked($id, function (array $manifest, string $etag) use ($id, $open): array {
                if ($manifest['status'] !== 'uploading' && $manifest['status'] !== 'ready') {
                    throw new UserError('This upload was confirmed already.', 409);
                }
                if ($manifest['status'] === 'uploading') {
                    if ($manifest['received'] !== $manifest['size']) {
                        throw new UserError('The upload is incomplete: ' . $manifest['received'] . ' of ' . $manifest['size'] . ' bytes received.', 409);
                    }
                    $spool = $this->spool($id, $manifest);
                    try {
                        $sum = hash_file('sha256', $spool);
                        if ($manifest['sha256'] !== null && ($sum === false || !hash_equals($manifest['sha256'], $sum))) {
                            $this->deleteRecord($id, $manifest);
                            throw new RestoreUploadRemoved(new UserError('The upload does not match its sha256. Start the upload again.', 422));
                        }
                        $manifest['status'] = 'ready';
                        $manifest = $this->commit(self::manifestKey($id), $etag, $manifest);
                    } finally {
                        @unlink($spool);
                    }
                }
                $spool = $this->spool($id, $manifest);
                try {
                    return $open($spool, $manifest);
                } catch (Throwable $e) {
                    $this->deleteRecord($id, $manifest);
                    throw new RestoreUploadRemoved($e);
                } finally {
                    @unlink($spool);
                }
            });
        } catch (RestoreUploadRemoved $removed) {
            $this->withCreate(function () use ($id): void {
                $this->forget($id);
            });
            throw $removed->reason();
        }
    }

    /**
     * @param Closure(string):array<string,mixed> $restore
     * @return array<string,mixed>
     */
    public function confirm(string $id, Closure $restore): array
    {
        return $this->locked($id, function (array $manifest, string $etag) use ($id, $restore): array {
            if ($manifest['status'] === 'interrupted') {
                throw new UserError(RestoreUploads::INTERRUPTED, 409);
            }
            if ($manifest['status'] === 'restored' || $manifest['status'] === 'failed') {
                return $manifest['status'] === 'restored' ? ($manifest['result'] ?? []) : throw new UserError((string)($manifest['result']['error'] ?? 'The restore failed.'));
            }
            if ($manifest['status'] !== 'ready') {
                throw new UserError('Finish the upload first.', 409);
            }
            $spool = $this->spool($id, $manifest);
            try {
                $sum = $manifest['sha256'] ?? hash_file('sha256', $spool);
                $this->commit(self::manifestKey($id), $etag, ['status' => 'applying', 'fingerprint' => is_string($sum) ? $sum : null] + $manifest);
                $fresh = $this->fresh($id);
                try {
                    $out = $restore($spool);
                } catch (Throwable $e) {
                    if ($e->getCode() === 409) {
                        $this->commit(self::manifestKey($id), $fresh[1], $manifest);
                    } else {
                        $this->deleteBytes($id, $manifest);
                        $this->commit(self::manifestKey($id), $fresh[1], ['status' => 'failed', 'result' => ['error' => $e instanceof UserError ? $e->getMessage() : 'The restore failed.']] + $manifest);
                    }
                    throw $e;
                }
                $this->deleteBytes($id, $manifest);
                $this->commit(self::manifestKey($id), $fresh[1], ['status' => 'restored', 'result' => $out] + $manifest);

                return $out;
            } finally {
                @unlink($spool);
            }
        });
    }

    public function delete(string $id): void
    {
        $this->locked($id, function (array $manifest) use ($id): array {
            $this->deleteRecord($id, $manifest);

            return [];
        });
        $this->withCreate(function () use ($id): void {
            $this->forget($id);
        });
    }

    public function sweep(?int $now = null, bool $create = true): string
    {
        foreach ($this->ids() as $id) {
            $this->expireOne($id, $now);
        }
        $this->stage()?->sweep();

        return 'uploads/restore';
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /** @return array{0:string,1:Manifest} */
    private function allocate(string $owner, int $size, ?string $sha256): array
    {
        $version = $this->storage->version(self::INDEX);
        $etag = $version[1] ?? null;
        $ids = $version === null ? [] : $this->idsFrom($version[0]);
        $open = [];
        $kept = [];
        foreach ($ids as $old) {
            $manifest = $this->manifest($old);
            if ($manifest !== null && hash_equals($manifest['owner'], $owner)) {
                $token = $this->take($old);
                if ($token === null) {
                    throw new UserError('Your earlier restore upload is in use. Try again when it has finished.', 409);
                }
                $this->removeHeld($old, $manifest, $token);
                continue;
            }
            if ($manifest === null || !in_array($manifest['status'], self::DONE, true)) {
                $open[$old] = $manifest === null ? 0 : max(0, $manifest['size'] - $manifest['received']);
            }
            $kept[] = $old;
        }
        if (count($open) >= $this->maxOpen) {
            $limit = ($this->now)() - RestoreUploads::IDLE_FULL;
            foreach (array_keys($open) as $old) {
                $manifest = $this->manifest($old);
                $modified = $this->storage->modifiedAt(self::manifestKey($old));
                if ($modified === null || $modified > $limit || !in_array($manifest['status'] ?? null, ['uploading', 'ready'], true)) {
                    continue;
                }
                $token = $this->take($old);
                if ($token === null) {
                    continue;
                }
                $again = $this->manifest($old);
                $modified = $this->storage->modifiedAt(self::manifestKey($old));
                if ($again !== null && $modified !== null && $modified <= $limit && in_array($again['status'], ['uploading', 'ready'], true)) {
                    $this->removeHeld($old, $again, $token);
                    unset($open[$old]);
                    $kept = array_values(array_filter($kept, static fn (string $id): bool => $id !== $old));
                    continue;
                }
                $this->lease->release(self::leaseKey($old), $token);
            }
        }
        if (count($open) >= $this->maxOpen) {
            throw new UserError('Too many restore uploads are open. Try again later.', 503);
        }
        $free = ($this->freeBytes)(sys_get_temp_dir());
        if ($free !== null && $free - array_sum($open) < $size + RestoreUploads::DISK_MARGIN) {
            throw new UserError('There is not enough free disk space for this backup.', 507);
        }
        $id = bin2hex(random_bytes(16));
        $manifest = ['owner' => $owner, 'size' => $size, 'sha256' => $sha256, 'chunk_size' => $this->chunkBytes, 'received' => 0, 'next' => 0, 'status' => 'uploading'];
        if (!$this->storage->putIfMissing(self::manifestKey($id), $this->json($manifest))) {
            throw new UserError('The upload could not be stored.', 500);
        }
        try {
            $this->saveIndex([...$kept, $id], $etag);
        } catch (Throwable $e) {
            $this->storage->delete(self::manifestKey($id));
            throw $e;
        }

        return [$id, $manifest];
    }

    /** @param Closure(Manifest, string, Closure(?string):void):mixed $fn */
    private function locked(string $id, Closure $fn): mixed
    {
        $owner = $this->me();
        if (preg_match(self::ID, $id) !== 1 || $this->storage->version(self::manifestKey($id)) === null) {
            throw new UserError(RestoreUploads::NOT_FOUND, 404);
        }
        try {
            $token = $this->lease->acquire(self::leaseKey($id), ($this->now)(), $this->leaseTtl);
        } catch (LeaseDenied $e) {
            throw new UserError('Another request is using this upload. Try again when it has finished.', 409, $e);
        }
        $cleanup = null;
        try {
            $version = $this->fresh($id);
            $manifest = RestoreUploads::manifestFrom($version[0]);
            if ($manifest === null || !hash_equals($manifest['owner'], $owner)) {
                throw new UserError(RestoreUploads::NOT_FOUND, 404);
            }
            $etag = $version[1];
            if ($manifest['status'] === 'applying') {
                $manifest = $this->commit(self::manifestKey($id), $etag, RestoreUploads::interruptedManifest($id, $manifest, $this->stage()));
                $etag = $this->fresh($id)[1];
            }
            $guard = static function (?string $key) use (&$cleanup): void {
                $cleanup = $key;
            };

            return $fn($manifest, $etag, $guard);
        } catch (Throwable $e) {
            if (is_string($cleanup)) {
                $this->storage->delete($cleanup);
            }
            throw $e;
        } finally {
            $this->lease->release(self::leaseKey($id), $token);
        }
    }

    /**
     * @param Manifest $manifest
     * @return Manifest
     */
    private function commit(string $key, string $etag, array $manifest): array
    {
        if (!$this->storage->putIfVersion($key, $etag, $this->json($manifest))) {
            throw new UserError('Another request is using this upload. Try again when it has finished.', 409);
        }

        return $manifest;
    }

    /** @return array{0:string,1:string} */
    private function fresh(string $id): array
    {
        $version = $this->storage->version(self::manifestKey($id));
        if ($version === null) {
            throw new UserError(RestoreUploads::NOT_FOUND, 404);
        }

        return $version;
    }

    /** @param Closure():mixed $fn */
    private function withCreate(Closure $fn): mixed
    {
        $deadline = hrtime(true) + 5_000_000_000;
        $token = '';
        while (true) {
            try {
                $token = $this->lease->acquire(self::CREATE, ($this->now)(), $this->leaseTtl);
                break;
            } catch (LeaseDenied $e) {
                if (hrtime(true) >= $deadline) {
                    throw new UserError(RestoreUploads::LOCK_UNUSABLE, 500, $e);
                }
                usleep(2_000);
            }
        }
        try {
            return $fn();
        } finally {
            $this->lease->release(self::CREATE, $token);
        }
    }

    private function expireOne(string $id, ?int $now): void
    {
        $limit = ($now ?? ($this->now)()) - RestoreUploads::IDLE;
        $modified = $this->storage->modifiedAt(self::manifestKey($id));
        if ($modified !== null && $modified > $limit) {
            return;
        }
        $token = $this->take($id);
        if ($token === null) {
            return;
        }
        $manifest = null;
        try {
            $modified = $this->storage->modifiedAt(self::manifestKey($id));
            if ($modified !== null && $modified > $limit) {
                return;
            }
            $manifest = $this->manifest($id);
            if ($manifest === null) {
                $this->storage->delete(self::manifestKey($id));
            } else {
                $this->deleteRecord($id, $manifest);
            }
        } finally {
            $this->lease->release(self::leaseKey($id), $token);
        }
        $this->withCreate(function () use ($id): void {
            $this->forget($id);
        });
    }

    /** @return list<string> */
    private function ids(): array
    {
        $version = $this->storage->version(self::INDEX);
        if ($version === null) {
            return [];
        }

        return $this->idsFrom($version[0]);
    }

    /** @return list<string> */
    private function idsFrom(string $json): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UserError('The upload could not be stored.', 500);
        }
        if (!is_array($data) || !is_array($data['ids'] ?? null)) {
            throw new UserError('The upload could not be stored.', 500);
        }
        $ids = [];
        foreach ($data['ids'] as $id) {
            if (is_string($id) && preg_match(self::ID, $id) === 1) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @param list<string> $ids */
    private function saveIndex(array $ids, ?string $etag): void
    {
        $bytes = $this->json(['ids' => $ids]);
        $saved = $etag === null ? $this->storage->putIfMissing(self::INDEX, $bytes) : $this->storage->putIfVersion(self::INDEX, $etag, $bytes);
        if (!$saved) {
            throw new UserError('Another request is using this upload. Try again when it has finished.', 409);
        }
    }

    private function forget(string $id): void
    {
        $version = $this->storage->version(self::INDEX);
        if ($version === null) {
            return;
        }
        $ids = $this->idsFrom($version[0]);
        $left = array_values(array_filter($ids, static fn (string $have): bool => $have !== $id));
        if (count($left) === count($ids)) {
            return;
        }
        $this->saveIndex($left, $version[1]);
    }

    /** @return Manifest|null */
    private function manifest(string $id): ?array
    {
        $version = $this->storage->version(self::manifestKey($id));

        return $version === null ? null : RestoreUploads::manifestFrom($version[0]);
    }

    private function take(string $id): ?string
    {
        try {
            return $this->lease->acquire(self::leaseKey($id), ($this->now)(), $this->leaseTtl);
        } catch (LeaseDenied) {
            return null;
        }
    }

    /** @param Manifest $manifest */
    private function removeHeld(string $id, array $manifest, string $token): void
    {
        try {
            $this->deleteRecord($id, $manifest);
        } finally {
            $this->lease->release(self::leaseKey($id), $token);
        }
    }

    /** @param Manifest $manifest */
    private function deleteRecord(string $id, array $manifest): void
    {
        $this->deleteBytes($id, $manifest);
        $this->storage->delete(self::manifestKey($id));
    }

    /** @param Manifest $manifest */
    private function deleteBytes(string $id, array $manifest): void
    {
        for ($i = 0; $i < $manifest['next']; $i++) {
            $this->storage->delete(self::chunkKey($id, $i));
        }
    }

    /** @param Manifest $manifest */
    private function spool(string $id, array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apotheke-restore-');
        if ($path === false) {
            throw new UserError('The upload could not be stored.', 500);
        }
        $out = @fopen($path, 'wb');
        if ($out === false) {
            @unlink($path);
            throw new UserError('The upload could not be stored.', 500);
        }
        try {
            for ($i = 0; $i < $manifest['next']; $i++) {
                $bytes = $this->storage->read(self::chunkKey($id, $i));
                if (!is_string($bytes) || @fwrite($out, $bytes) !== strlen($bytes)) {
                    throw new UserError('The chunk could not be stored.', 500);
                }
            }
            if (!fflush($out)) {
                throw new UserError('The chunk could not be stored.', 500);
            }
        } catch (Throwable $e) {
            fclose($out);
            @unlink($path);
            throw $e;
        }
        fclose($out);
        if (@filesize($path) !== $manifest['size']) {
            @unlink($path);
            throw new UserError('The upload is incomplete: ' . (int)@filesize($path) . ' of ' . $manifest['size'] . ' bytes received.', 409);
        }

        return $path;
    }

    private function me(): string
    {
        try {
            $me = ($this->owner)();
        } catch (WriteFailed $e) {
            throw new UserError(RestoreUploads::OWNER_UNREADABLE, 500, $e);
        }
        if ($me === null || $me === '') {
            throw new UserError(RestoreUploads::GUEST, 403);
        }

        return $me;
    }

    private function stage(): ?RestoreStage
    {
        return $this->stage === null ? null : ($this->stage)();
    }

    /** @param array<array-key,mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UserError('The upload could not be stored.', 500);
        }
    }
}

/** The upload record was removed; the caller still has to drop it from the index. */
final class RestoreUploadRemoved extends \RuntimeException
{
    public function __construct(private readonly Throwable $reason)
    {
        parent::__construct($reason->getMessage(), (int)$reason->getCode(), $reason);
    }

    public function reason(): Throwable
    {
        return $this->reason;
    }
}
