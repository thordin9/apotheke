<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Closure;
use Apotheke\Core\Storage\LeaseStorage;
use Apotheke\Core\Storage\LocalStorage;

/**
 * Chunked restore uploads (POST /api/v1/restores…): a backup zip sent in pieces, for hosts that cap a request body.
 * Each upload is a folder DATA_DIR/uploads/restore/<32 hex>/ with manifest.json (owner, declared size, optional
 * SHA-256, bytes received), data.part while it is sent, and backup.zip once finish() has checked it. Only this class
 * writes there; nothing is followed through a link. Chunks come in order, every one but the last exactly
 * $chunkBytes long. The zip itself is checked and restored by Restorer, as a one-shot upload is. An upload is its
 * owner's only (another account's id is not found), and one idle for IDLE seconds is removed by sweep(). Starts are
 * serialised by create.lock (one upload per account, at most $maxOpen in all; when full, other accounts' unconfirmed
 * uploads idle for IDLE_FULL seconds give way). A confirmed upload keeps only its manifest with the outcome (status restored, failed or interrupted, `result`), so a
 * client that lost the answer reads it. Confirm writes status applying (with the backup's SHA-256, `fingerprint`)
 * before the restore; found again with nobody holding the lock, the restore stopped and it becomes interrupted.
 * On a batch-only database ($stage gives a RestoreStage) a staged restore's marker tells instead: restored when its swap committed
 * (RestoreStage::applied() with the upload's id as the run), else failed with nothing changed; and the sweep also
 * drops a stopped restore's leftover copies (N-1705).
 *
 * @phpstan-type Manifest array{owner:string,size:int,sha256:?string,chunk_size:int,received:int,next:int,status:string,fingerprint?:?string,result?:array<string,mixed>}
 */
final class RestoreUploads
{
    /** Seconds without a request after which an upload is removed. */
    public const IDLE = 3600;

    public const GUEST = 'Restores need a signed-in account.';

    /** N-1636: the signed-in account could not be read (a database error), not a guest. */
    public const OWNER_UNREADABLE = 'Your account could not be checked. Try again later.';

    public const NOT_FOUND = 'Restore upload not found.';

    /** N-1594: a confirm that stopped before its outcome was written. */
    public const INTERRUPTED = 'The restore was interrupted and may have been applied. Check the data before you restore again.';

    /** N-1591: create.lock cannot be used (no path in the message). */
    public const LOCK_UNUSABLE = 'Restore uploads cannot be started on this server right now. Ask the administrator to check the data folder.';

    /** A staged restore that stopped after its swap: the rows are in, the steps after them did not run. */
    public const APPLIED_AFTER_STOP = 'The restore was interrupted after the catalog data was replaced. Check the photos, camera files and settings.';

    /** A staged restore that stopped before its swap. */
    /** L-1707: a stopped restore that was applied, and a later restore has replaced its data since. */
    public const REPLACED_SINCE = 'A later restore has replaced this data since.';

    public const NOT_APPLIED = 'The restore was interrupted before the catalog data was replaced. Nothing was changed. Start the upload again.';

    /** N-1592: idle seconds after which another account's unconfirmed upload gives way while the cap is reached. */
    public const IDLE_FULL = 600;

    /** Outcome records: confirmed, never counted as open. */
    private const DONE = ['restored', 'failed', 'interrupted'];

    private const ID = '/^[a-f0-9]{32}$/D';

    private const MANIFEST = 'manifest.json';

    private const PART = 'data.part';

    private const ZIP = 'backup.zip';

    private const CREATE_LOCK = 'create.lock';

    /** Free disk a new upload needs beyond its declared size. */
    public const DISK_MARGIN = 67108864;

    /** Room left in post_max_size for the multipart framing around a chunk. */
    public const MULTIPART_MARGIN = 65536;

    /** @var Closure(string):?float */
    private readonly Closure $freeBytes;

    /** @var Closure(string):void */
    private readonly Closure $afterLock;

    private readonly LeaseStorage $storage;

    /**
     * @param Closure():?string $owner this request's account key ("user:<id>:<sha256 of created_at>", "local" with sign-in off), null for a guest; WriteFailed when the account cannot be read (500)
     * @param int $maxOpen most uploads not yet confirmed, all accounts together
     * @param ?Closure(string):?float $freeBytes free bytes on the folder's disk, null when unknown (disk_free_space())
     * @param ?Closure(string):void $afterLock a test seam: runs on the manifest's path once it is locked, before the checks again
     * @param ?Closure(string, Closure(): mixed): mixed $unit takes $id first, before any owner/package/chunk access
     * @param ?Closure():?RestoreStage $stage a batch-only database's restore stage (staged restores), else null; asked
     *                                     only when needed, so the sweep needs no database elsewhere
     */
    public function __construct(
        private readonly string $dataDir,
        private readonly int $chunkBytes,
        private readonly int $maxBytes,
        private readonly Closure $owner,
        private readonly int $maxOpen = 8,
        ?Closure $freeBytes = null,
        ?Closure $afterLock = null,
        private readonly ?Closure $stage = null,
        ?LeaseStorage $storage = null,
        private readonly ?Closure $unit = null,
    ) {
        $this->storage = $storage ?? new LocalStorage($dataDir, dirMode: 0770);
        $this->freeBytes = $freeBytes ?? static fn (string $dir): ?float => is_float($f = @disk_free_space($dir)) ? $f : null;
        $this->afterLock = $afterLock ?? static function (string $file): void {
        };
    }

    /**
     * The chunk size to use: $configured, but no more than PHP takes in one request (upload_max_filesize, and
     * post_max_size less MULTIPART_MARGIN), in whole KiB and at least 1 KiB. A limit of 0 or less is none.
     */
    public static function effectiveChunk(int $configured, string|false $uploadMax, string|false $postMax): int
    {
        $limits = [$configured];
        if (($u = self::iniBytes($uploadMax)) > 0) {
            $limits[] = $u;
        }
        if (($p = self::iniBytes($postMax)) > 0) {
            $limits[] = $p - self::MULTIPART_MARGIN;
        }

        return max(1024, intdiv(min($limits), 1024) * 1024);
    }

    /** A php.ini size ("2M", "1G", "512K", "100") in bytes; 0 for none, unset or not a size. */
    public static function iniBytes(string|false $value): int
    {
        if ($value === false || preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/iD', $value, $m) !== 1) {
            return 0;
        }

        return (int)$m[1] * [''  => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2])];
    }

    public function chunkBytes(): int
    {
        return $this->chunkBytes;
    }

    /**
     * Start an upload of $size bytes ($sha256: the client's hex digest of the whole file, or null). Your earlier
     * upload is removed first (409 while a request uses it): one at a time per account. When $maxOpen uploads are
     * open, other accounts' unconfirmed ones idle for IDLE_FULL seconds are removed first (N-1592); still full: 503.
     * The disk must have $size plus DISK_MARGIN free beyond what the other open uploads still expect (N-1593), else 507.
     *
     * @return array{0:string,1:Manifest}
     */
    public function create(mixed $size, mixed $sha256): array
    {
        return $this->unit('', function () use ($size, $sha256): array {
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

        try {
            $root = $this->root();
            return $this->storage->withLease('uploads/restore/' . self::CREATE_LOCK, function () use ($root, $owner, $size, $sha256): array {
            $open = [];   // id => bytes it still expects
            foreach ($this->ids($root) as $old) {
                $m = self::read($root . '/' . $old);
                if ($m !== null && hash_equals($m['owner'], $owner)) {
                    if (!$this->removeFree($root, $old, static fn (): bool => true)) throw new UserError('Your earlier restore upload is in use. Try again when it has finished.', 409);
                } elseif ($m === null || !in_array($m['status'], self::DONE, true)) {
                    $open[$old] = $m === null ? 0 : max(0, $m['size'] - $m['received']);
                }
            }
            if (count($open) >= $this->maxOpen) {
                $limit = time() - self::IDLE_FULL;
                foreach (array_keys($open) as $old) {
                    if ($this->removeFree($root, $old, static fn (array $st): bool => $st['mtime'] <= $limit
                        && in_array(self::read($root . '/' . $old)['status'] ?? null, ['uploading', 'ready'], true))) unset($open[$old]);
                }
            }
            if (count($open) >= $this->maxOpen) throw new UserError('Too many restore uploads are open. Try again later.', 503);
            $free = ($this->freeBytes)($root);
            if ($free !== null && $free - array_sum($open) < $size + self::DISK_MARGIN) throw new UserError('There is not enough free disk space for this backup.', 507);
            $id = bin2hex(random_bytes(16));
            $m = ['owner' => $owner, 'size' => $size, 'sha256' => $sha256, 'chunk_size' => $this->chunkBytes, 'received' => 0, 'next' => 0, 'status' => 'uploading'];
            try {
                if (!$this->storage->putIfMissing('uploads/restore/' . $id . '/' . self::MANIFEST, (string)json_encode($m))) throw new UserError('The upload could not be stored.', 500);
            } catch (\Throwable $e) {
                self::remove($root . '/' . $id); throw $e;
            }
            return [$id, $m];
            });
        } catch (UserError $e) { throw $e; } catch (\Throwable $e) { throw new UserError(self::LOCK_UNUSABLE, 500, $e); }
        });
    }

    /** @return Manifest your upload $id; 404 when it is not there or not yours */
    public function get(string $id): array
    {
        return $this->locked($id, static fn (string $dir, array $m, string $etag): array => $m);
    }

    /**
     * Append chunk $index (the uploaded file $tmp, $bytes long). Out of order, over the chunk size, past the declared
     * size, or a short chunk that is not the last is refused and changes nothing.
     *
     * @return Manifest
     */
    public function append(string $id, int $index, string $tmp, int $bytes): array
    {
        return $this->locked($id, function (string $dir, array $m, string $etag) use ($id, $index, $tmp, $bytes): array {
            if ($m['status'] !== 'uploading' || $index !== $m['next']) {
                throw new UserError($m['status'] !== 'uploading' ? 'This upload is complete.' : 'Send chunk ' . $m['next'] . ' next.', 409);
            }
            if ($bytes > $m['chunk_size'] || $m['received'] + $bytes > $m['size']) {
                throw new UserError('This chunk is larger than ' . ($bytes > $m['chunk_size'] ? 'the chunk size.' : 'the rest of the backup.'), 413);
            }
            if ($bytes < 1 || ($bytes !== $m['chunk_size'] && $m['received'] + $bytes !== $m['size'])) {
                throw new UserError('Every chunk but the last must be exactly ' . $m['chunk_size'] . ' bytes.', 422);
            }
            $part = $dir . '/' . self::PART;
            $in = @fopen($tmp, 'rb');
            $out = self::plainOrNone($part) ? @fopen($part, 'ab') : false;
            $copied = $in !== false && $out !== false ? stream_copy_to_stream($in, $out) : false;
            $closed = $out !== false && fclose($out);
            if ($in !== false) {
                fclose($in);
            }
            clearstatcache(true, $part);
            if ($copied !== $bytes || !$closed || @filesize($part) !== $m['received'] + $bytes) {
                $h = self::plainOrNone($part) ? @fopen($part, 'r+') : false;   // back to the chunks before this one
                if ($h !== false) {
                    ftruncate($h, $m['received']);
                    fclose($h);
                }
                throw new UserError('The chunk could not be stored.', 500);
            }
            $m['received'] += $bytes;
            $m['next']++;

            return $this->writeState($id, $m);
        });
    }

    /**
     * Check the whole upload (size, then the SHA-256 if one was given) and make it the zip to restore. The caller
     * opens it with Restorer::open(). A checksum that does not match, or a zip $open refuses, removes the upload.
     * Again on a checked upload: the dry run again. After confirm: 409.
     *
     * @param Closure(string, Manifest):array<string,mixed> $open runs on the zip's path while the upload is locked
     * @return array<string,mixed> what $open returned
     */
    public function finish(string $id, Closure $open): array
    {
        return $this->locked($id, function (string $dir, array $m, string $etag) use ($id, $open): array {
            if ($m['status'] !== 'uploading' && $m['status'] !== 'ready') {
                throw new UserError('This upload was confirmed already.', 409);
            }
            if ($m['status'] === 'uploading') {
                $part = $dir . '/' . self::PART;
                clearstatcache(true, $part);
                if ($m['received'] !== $m['size'] || !self::plainOrNone($part) || @filesize($part) !== $m['size']) {
                    throw new UserError('The upload is incomplete: ' . $m['received'] . ' of ' . $m['size'] . ' bytes received.', 409);
                }
                if ($m['sha256'] !== null && !hash_equals($m['sha256'], (string)hash_file('sha256', $part))) {
                    self::remove($dir);
                    throw new UserError('The upload does not match its sha256. Start the upload again.', 422);
                }
                if (!self::plainOrNone($dir . '/' . self::ZIP) || !@rename($part, $dir . '/' . self::ZIP)) {
                    throw new UserError('The upload could not be stored.', 500);
                }
                $m['status'] = 'ready';
                $m = $this->writeState($id, $m);
            }

            try {
                return $open($dir . '/' . self::ZIP, $m);
            } catch (\Throwable $e) {
                self::remove($dir);   // not a backup that can be restored: as a one-shot upload, it is not kept
                throw $e;
            }
        });
    }

    /**
     * Run $restore on the checked zip of upload $id, while it is locked. First status applying (with the zip's SHA-256
     * as `fingerprint`) is written and synced (N-1594), so a restore that stops before its outcome is written is found
     * as interrupted and never applied again. A 409 from $restore (another restore is running, nothing applied) makes
     * it ready again. Otherwise the zip is removed and the outcome kept in the manifest (status restored with
     * $restore's answer as `result`, or failed with {"error"}), so the answer can be read again (N-1588: GET, or
     * confirm again, which never restores twice). Interrupted: 409 INTERRUPTED.
     *
     * @param Closure(string):array<string,mixed> $restore
     * @return array<string,mixed> $restore's answer
     */
    public function confirm(string $id, Closure $restore): array
    {
        return $this->locked($id, function (string $dir, array $m, string $etag) use ($id, $restore): array {
            if ($m['status'] === 'interrupted') {
                throw new UserError(self::INTERRUPTED, 409);
            }
            if ($m['status'] === 'restored' || $m['status'] === 'failed') {
                return $m['status'] === 'restored' ? ($m['result'] ?? []) : throw new UserError((string)($m['result']['error'] ?? 'The restore failed.'));
            }
            if ($m['status'] !== 'ready') {
                throw new UserError('Finish the upload first.', 409);
            }
            $zip = $dir . '/' . self::ZIP;
            $sum = $m['sha256'] ?? @hash_file('sha256', $zip);
            $this->writeState($id, ['status' => 'applying', 'fingerprint' => is_string($sum) ? $sum : null] + $m, true);
            try {
                $out = $restore($zip);
            } catch (\Throwable $e) {
                if ($e->getCode() === 409) {
                    $this->writeState($id, $m, true);   // not applied: ready to confirm again
                } else {
                    @unlink($zip);
                    $this->writeState($id, ['status' => 'failed', 'result' => ['error' => $e instanceof UserError ? $e->getMessage() : 'The restore failed.']] + $m, true);
                }
                throw $e;
            }
            @unlink($zip);
            $this->writeState($id, ['status' => 'restored', 'result' => $out] + $m, true);

            return $out;
        });
    }

    /** Cancel upload $id: its folder and files are removed. */
    public function delete(string $id): void
    {
        $this->locked($id, static function (string $dir, array $m, string $etag): array {
            self::remove($dir);

            return [];
        });
    }

    /**
     * Remove every upload idle for IDLE seconds or more, unless a request holds it (N-1583), and what a removal
     * left behind; returns the checked root folder ($create: make it if missing).
     */
    public function sweep(?int $now = null, bool $create = true): string
    {
        if (!$create) return $items = $this->sweepOnce($now, false);
        return $this->unit('', fn (): string => $this->storage->withLease('uploads/restore/' . self::CREATE_LOCK, fn (): string => $this->sweepOnce($now)));
    }

    private function sweepOnce(?int $now, bool $create = true): string
    {
        $root = $this->root($create);
        $limit = ($now ?? time()) - self::IDLE;
        foreach ($this->ids($root) as $id) {
            $this->removeFree($root, $id, static fn (array $st): bool => $st['mtime'] <= $limit);
        }
        foreach (@scandir($root) ?: [] as $n) {
            if (preg_match('/^\.removing-[a-f0-9]{16}$/D', $n) === 1) {
                self::clear($root . '/' . $n);
            }
        }
        $this->stage()?->sweep();

        return $root;
    }

    /** How many uploads are there (any owner)? For the sweep command's report. */
    public function count(): int
    {
        return count($this->ids($this->root(false)));
    }

    /** this request's owner key; guests are refused, and an account that cannot be read is a server fault (N-1636) */
    private function me(): string
    {
        try {
            $me = ($this->owner)();
        } catch (WriteFailed $e) {
            throw new UserError(self::OWNER_UNREADABLE, 500, $e);
        }
        if ($me === null || $me === '') {
            throw new UserError(self::GUEST, 403);
        }

        return $me;
    }

    /**
     * DATA_DIR/uploads/restore, made if missing (0770) when $create (the command line, run as the data directory's
     * owner, never makes it). A link or anything but a folder at either name, or a real path elsewhere, is refused.
     */
    private function root(bool $create = true): string
    {
        $data = rtrim($this->dataDir, '/');
        foreach ([$data . '/uploads', $data . '/uploads/restore'] as $d) {
            $st = self::fresh($d);
            if ($st === false && !$create) {
                return $data . '/uploads/restore';   // nothing to sweep
            }
            if ($st === false) {
                @mkdir($d, 0770);
                $st = self::fresh($d);
            }
            if ($st === false || ($st['mode'] & 0170000) !== 0040000) {
                throw new UserError('The restore upload folder DATA_DIR/uploads/restore is a link or not a folder.', 500);
            }
        }
        $real = realpath($data);
        if ($real === false || realpath($data . '/uploads/restore') !== $real . '/uploads/restore') {
            throw new UserError('The restore upload folder DATA_DIR/uploads/restore is a link or not a folder.', 500);
        }

        return $data . '/uploads/restore';
    }

    /** @return array<int|string,int>|false lstat() of $path, not cached */
    private static function fresh(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    /** @return list<string> the upload folders (well-formed names that are real folders, not links) */
    private function ids(string $root): array
    {
        return array_values(array_filter(@scandir($root) ?: [], static fn (string $n): bool => preg_match(self::ID, $n) === 1
            && ($st = @lstat($root . '/' . $n)) !== false && ($st['mode'] & 0170000) === 0040000));
    }

    /**
     * Run $fn on your upload $id with its manifest locked (exclusive, not waited for: 409 while another request
     * holds it). Not yours, malformed, missing or not a plain folder and manifest: 404, the same answer.
     *
     * @template T
     * @param Closure(string, Manifest, string):T $fn
     * @return T
     */
    private function locked(string $id, Closure $fn): mixed
    {
        return $this->unit($id, function () use ($id, $fn): mixed {
            if (preg_match(self::ID, $id) !== 1) throw new UserError(self::NOT_FOUND, 404);
            $owner = $this->me();
            $key = 'uploads/restore/' . $id . '/' . self::MANIFEST;
            $dirKey = 'uploads/restore/' . $id;
            $dir = rtrim($this->dataDir, '/') . '/' . $dirKey;
            $checked = @lstat($dir);
            if ($checked === false || is_link($dir) || ($checked['mode'] & 0170000) !== 0040000) throw new UserError(self::NOT_FOUND, 404);
            try {
            $result = $this->storage->withLease($key, function () use ($id, $key, $owner, $fn): mixed {
                $dirKey = 'uploads/restore/' . $id;
                $dir = $this->storage->pathFor($dirKey);
                $root = $this->root();
                $st = preg_match(self::ID, $id) === 1 ? @lstat($dir) : false;
                if ($st === false || ($st['mode'] & 0170000) !== 0040000 || realpath($dir) !== realpath($root) . '/' . $id) throw new UserError(self::NOT_FOUND, 404);
                ($this->afterLock)($this->storage->pathFor($key));
                try { $state = $this->storage->version($key); } catch (\InvalidArgumentException) { throw new UserError(self::NOT_FOUND, 404); }
                if ($state === null) throw new UserError(self::NOT_FOUND, 404);
                $m = self::decode($state[0]);
                if ($m === null || !hash_equals($m['owner'], $owner)) throw new UserError(self::NOT_FOUND, 404);
                if ($m['status'] === 'applying') {
                    $this->storage->delete($dirKey . '/' . self::ZIP);
                    $m = $this->write($id, $state[1], $this->stopped($id, $m));
                }
                $this->storage->renew($key);
                return $fn($dir, $m, $state[1]);
            }, false);
            } catch (\InvalidArgumentException) { throw new UserError(self::NOT_FOUND, 404); }
            if ($result === false) throw new UserError('Another request is using this upload. Try again when it has finished.', 409);
            return $result;
        });
    }

    /**
     * The outcome of a restore found stopped: interrupted, or with a staged restore what its marker says.
     *
     * @param Manifest $m
     * @return Manifest
     */
    private function stopped(string $id, array $m): array
    {
        try {
            $outcome = $this->stage()?->outcome($id);
        } catch (\Throwable) {
            $outcome = null;   // unreadable: may have been applied
        }
        $restored = static fn (array $warnings): array => ['status' => 'restored', 'result' => ['tables' => [], 'photos' => 0, 'warnings' => $warnings, 'token_cleared' => false]];

        // L-1707: from this run's own marker row; unknown when it is older than the runs kept
        return match ($outcome) {
            RestoreStage::APPLIED => $restored([self::APPLIED_AFTER_STOP]) + $m,
            RestoreStage::REPLACED => $restored([self::APPLIED_AFTER_STOP, self::REPLACED_SINCE]) + $m,
            RestoreStage::NONE => ['status' => 'failed', 'result' => ['error' => self::NOT_APPLIED]] + $m,
            default => ['status' => 'interrupted', 'result' => ['error' => self::INTERRUPTED, 'sha256' => $m['fingerprint'] ?? null]] + $m,
        };
    }

    private function stage(): ?RestoreStage
    {
        return $this->stage === null ? null : ($this->stage)();
    }

    /** @return Manifest|null */
    private static function read(string $dir): ?array
    {
        $named = @lstat($dir . '/' . self::MANIFEST);

        return $named !== false && Restorer::plainFile($named) ? self::decode((string)@file_get_contents($dir . '/' . self::MANIFEST)) : null;
    }

    /** @return Manifest|null */
    private static function decode(string $json): ?array
    {
        $m = json_decode($json, true);
        $ints = ['size', 'chunk_size', 'received', 'next'];
        if (!is_array($m) || !is_string($m['owner'] ?? null) || !in_array($m['status'] ?? null, ['uploading', 'ready', 'applying', 'restored', 'failed', 'interrupted'], true)
            || array_filter($ints, static fn (string $k): bool => !is_int($m[$k] ?? null)) !== [] || !(($m['sha256'] ?? null) === null || is_string($m['sha256']))
            || !(($m['result'] ?? null) === null || is_array($m['result'])) || !(($m['fingerprint'] ?? null) === null || is_string($m['fingerprint']))) {
            return null;
        }

        return ['owner' => $m['owner'], 'size' => $m['size'], 'sha256' => $m['sha256'] ?? null, 'chunk_size' => $m['chunk_size'],
            'received' => $m['received'], 'next' => $m['next'], 'status' => $m['status']] + (array_key_exists('fingerprint', $m) ? ['fingerprint' => $m['fingerprint']] : [])
            + (is_array($m['result'] ?? null) ? ['result' => $m['result']] : []);
    }

    /**
     * @param string $etag the manifest ETag read under its lease
     * @param Manifest $m
     * @param bool $sync on disk before it returns (fsync), for the status around a restore
     * @return Manifest
     */
    private function write(string $id, string $etag, array $m, bool $sync = false): array
    {
        $key = 'uploads/restore/' . $id . '/' . self::MANIFEST;
        if (!$this->storage->putIfVersion($key, $etag, (string)json_encode($m), $sync)) throw new UserError('Another request changed this upload. Try again.', 409);
        return $m;
    }

    /**
     * @param Manifest $m
     * @return Manifest
     */
    private function writeState(string $id, array $m, bool $sync = false): array
    {
        $state = $this->storage->version('uploads/restore/' . $id . '/' . self::MANIFEST);
        if ($state === null || !$this->storage->putIfVersion('uploads/restore/' . $id . '/' . self::MANIFEST, $state[1], (string)json_encode($m), $sync)) {
            throw new UserError('Another request changed this upload. Try again.', 409);
        }
        return $m;
    }

    private function unit(string $id, Closure $fn): mixed
    {
        return $this->unit === null ? $fn() : ($this->unit)($id, $fn);
    }

    /** Nothing at $path, or a plain file (never a link). */
    private static function plainOrNone(string $path): bool
    {
        $st = @lstat($path);

        return $st === false || Restorer::plainFile($st);
    }

    /**
     * N-1582, N-1583: remove upload $id only when no request holds its manifest lock and $still(lstat of the
     * manifest, or of the folder when it has none) holds, checked before the lock is taken and again under it
     * (N-1651). False when it was kept.
     *
     * @param Closure(array<int|string,int>):bool $still
     */
    private function removeFree(string $root, string $id, Closure $still): bool
    {
        $dir = $root . '/' . $id; $file = $dir . '/' . self::MANIFEST;
        $st = @lstat($dir);
        if ($st === false || ($st['mode'] & 0170000) !== 0040000) return false;
        $named = @lstat($file);
        if ($named === false || !Restorer::plainFile($named)) return $still($st) && self::remove($dir);
        if (!$still($named)) return false;
        return (bool)$this->storage->withLease('uploads/restore/' . $id . '/' . self::MANIFEST, function () use ($dir, $still): bool {
            ($this->afterLock)($dir . '/' . self::MANIFEST);
            $now = @lstat($dir . '/' . self::MANIFEST); $st = @lstat($dir);
            return ($st['mode'] & 0170000) === 0040000 && Restorer::plainFile($now) && $still($now) && self::remove($dir);
        }, false);
    }

    /**
     * Remove an upload folder. It is first renamed to a name only this call knows (a rename moves a link itself,
     * never what it points at), then its entries and the folder are removed from there.
     */
    private static function remove(string $dir): bool
    {
        $gone = dirname($dir) . '/.removing-' . bin2hex(random_bytes(8));
        if (!@rename($dir, $gone)) {
            return false;
        }
        self::clear($gone);

        return true;
    }

    /** Remove $path: a link or file itself, or a real folder's entries (never a folder's) and then the folder. */
    private static function clear(string $path): void
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        if ($st === false || ($st['mode'] & 0170000) !== 0040000) {
            @unlink($path);

            return;
        }
        foreach (@scandir($path) ?: [] as $n) {
            if ($n !== '.' && $n !== '..' && ($e = @lstat($path . '/' . $n)) !== false && ($e['mode'] & 0170000) !== 0040000) {
                @unlink($path . '/' . $n);
            }
        }
        @rmdir($path);
    }
}
