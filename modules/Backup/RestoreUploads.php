<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Closure;

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

    /**
     * @param Closure():?string $owner this request's account key ("user:<id>:<sha256 of created_at>", "local" with sign-in off), null for a guest; WriteFailed when the account cannot be read (500)
     * @param int $maxOpen most uploads not yet confirmed, all accounts together
     * @param ?Closure(string):?float $freeBytes free bytes on the folder's disk, null when unknown (disk_free_space())
     * @param ?Closure(string):void $afterLock a test seam: runs on the manifest's path once it is locked, before the checks again
     */
    public function __construct(
        private readonly string $dataDir,
        private readonly int $chunkBytes,
        private readonly int $maxBytes,
        private readonly Closure $owner,
        private readonly int $maxOpen = 8,
        ?Closure $freeBytes = null,
        ?Closure $afterLock = null,
    ) {
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
        $root = $this->sweep();
        try {
            $lock = Restorer::openLock($root . '/' . self::CREATE_LOCK, wait: true);   // L-1581: one start at a time
        } catch (UserError $e) {
            throw new UserError(self::LOCK_UNUSABLE, 500, $e);   // N-1591: a server fault, not the client's
        }
        try {
            $open = [];   // id => bytes it still expects
            foreach ($this->ids($root) as $old) {
                $m = self::read($root . '/' . $old);
                if ($m !== null && hash_equals($m['owner'], $owner)) {
                    if (!$this->removeFree($root, $old, static fn (): bool => true)) {
                        throw new UserError('Your earlier restore upload is in use. Try again when it has finished.', 409);
                    }
                } elseif ($m === null || !in_array($m['status'], self::DONE, true)) {
                    $open[$old] = $m === null ? 0 : max(0, $m['size'] - $m['received']);
                }
            }
            if (count($open) >= $this->maxOpen) {
                $limit = time() - self::IDLE_FULL;
                foreach (array_keys($open) as $old) {
                    if ($this->removeFree($root, $old, static fn (array $st): bool => $st['mtime'] <= $limit
                        && in_array(self::read($root . '/' . $old)['status'] ?? null, ['uploading', 'ready'], true))) {
                        unset($open[$old]);
                    }
                }
            }
            if (count($open) >= $this->maxOpen) {
                throw new UserError('Too many restore uploads are open. Try again later.', 503);
            }
            $free = ($this->freeBytes)($root);
            if ($free !== null && $free - array_sum($open) < $size + self::DISK_MARGIN) {
                throw new UserError('There is not enough free disk space for this backup.', 507);
            }
            $id = bin2hex(random_bytes(16));
            if (!@mkdir($root . '/' . $id, 0770)) {
                throw new UserError('The upload could not be stored.', 500);
            }
            $m = ['owner' => $owner, 'size' => $size, 'sha256' => $sha256, 'chunk_size' => $this->chunkBytes, 'received' => 0, 'next' => 0, 'status' => 'uploading'];
            if (@file_put_contents($root . '/' . $id . '/' . self::MANIFEST, json_encode($m), LOCK_EX) === false) {
                self::remove($root . '/' . $id);
                throw new UserError('The upload could not be stored.', 500);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return [$id, $m];
    }

    /** @return Manifest your upload $id; 404 when it is not there or not yours */
    public function get(string $id): array
    {
        return $this->locked($id, static fn (string $dir, array $m): array => $m);
    }

    /**
     * Append chunk $index (the uploaded file $tmp, $bytes long). Out of order, over the chunk size, past the declared
     * size, or a short chunk that is not the last is refused and changes nothing.
     *
     * @return Manifest
     */
    public function append(string $id, int $index, string $tmp, int $bytes): array
    {
        return $this->locked($id, function (string $dir, array $m, $fh) use ($index, $tmp, $bytes): array {
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

            return self::write($fh, $m);
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
        return $this->locked($id, function (string $dir, array $m, $fh) use ($open): array {
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
                $m = self::write($fh, $m);
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
        return $this->locked($id, static function (string $dir, array $m, $fh) use ($restore): array {
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
            self::write($fh, ['status' => 'applying', 'fingerprint' => is_string($sum) ? $sum : null] + $m, true);
            try {
                $out = $restore($zip);
            } catch (\Throwable $e) {
                if ($e->getCode() === 409) {
                    self::write($fh, $m, true);   // not applied: ready to confirm again
                } else {
                    @unlink($zip);
                    self::write($fh, ['status' => 'failed', 'result' => ['error' => $e instanceof UserError ? $e->getMessage() : 'The restore failed.']] + $m, true);
                }
                throw $e;
            }
            @unlink($zip);
            self::write($fh, ['status' => 'restored', 'result' => $out] + $m, true);

            return $out;
        });
    }

    /** Cancel upload $id: its folder and files are removed. */
    public function delete(string $id): void
    {
        $this->locked($id, static function (string $dir): array {
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
     * @param Closure(string, Manifest, resource):T $fn
     * @return T
     */
    private function locked(string $id, Closure $fn): mixed
    {
        $owner = $this->me();
        $root = $this->sweep();
        $dir = $root . '/' . $id;
        $file = $dir . '/' . self::MANIFEST;
        $st = preg_match(self::ID, $id) === 1 ? @lstat($dir) : false;
        $named = $st !== false && ($st['mode'] & 0170000) === 0040000 ? @lstat($file) : false;
        $fh = $named !== false && Restorer::plainFile($named) ? @fopen($file, 'r+') : false;
        if ($fh === false) {
            throw new UserError(self::NOT_FOUND, 404);
        }
        try {
            $open = fstat($fh);
            $m = self::decode((string)stream_get_contents($fh));
            if ($open === false || $open['ino'] !== $named['ino'] || $m === null || !hash_equals($m['owner'], $owner)) {
                throw new UserError(self::NOT_FOUND, 404);
            }
            if (!flock($fh, LOCK_EX | LOCK_NB)) {
                throw new UserError('Another request is using this upload. Try again when it has finished.', 409);
            }
            ($this->afterLock)($file);
            // N-1582: checked again under the lock: still the plain manifest of a real folder under the root
            clearstatcache(true, $dir);
            clearstatcache(true, $file);
            $now = @lstat($dir);
            if ($now === false || ($now['mode'] & 0170000) !== 0040000 || !Restorer::sameFile(fstat($fh), @lstat($file))
                || realpath($dir) !== realpath($root) . '/' . $id) {
                throw new UserError(self::NOT_FOUND, 404);
            }
            rewind($fh);
            $m = self::decode((string)stream_get_contents($fh)) ?? throw new UserError(self::NOT_FOUND, 404);
            if ($m['status'] === 'applying') {   // N-1594: nobody holds the lock, so that restore has stopped
                @unlink($dir . '/' . self::ZIP);
                $m = self::write($fh, ['status' => 'interrupted', 'result' => ['error' => self::INTERRUPTED, 'sha256' => $m['fingerprint'] ?? null]] + $m, true);
            }
            @touch($file);   // a request keeps the upload from the idle sweep

            return $fn($dir, $m, $fh);
        } finally {
            if (is_resource($fh)) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
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
     * @param resource $fh the locked manifest
     * @param Manifest $m
     * @param bool $sync on disk before it returns (fsync), for the status around a restore
     * @return Manifest
     */
    private static function write($fh, array $m, bool $sync = false): array
    {
        $json = (string)json_encode($m);
        if (!ftruncate($fh, 0) || !rewind($fh) || fwrite($fh, $json) !== strlen($json) || !fflush($fh) || ($sync && !fsync($fh))) {
            throw new UserError('The upload could not be stored.', 500);
        }

        return $m;
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
        $dir = $root . '/' . $id;
        $file = $dir . '/' . self::MANIFEST;
        clearstatcache(true, $file);
        $named = @lstat($file);
        if ($named === false || !Restorer::plainFile($named)) {   // no manifest (yet): judged by the folder
            clearstatcache(true, $dir);
            $st = @lstat($dir);

            return $st !== false && ($st['mode'] & 0170000) === 0040000 && $still($st) && self::remove($dir);
        }
        if (!$still($named)) {
            return false;   // N-1651: kept without taking its lock, so a sweep never makes a fresh upload look in use
        }
        $fh = @fopen($file, 'r');
        if ($fh === false) {
            return false;
        }
        try {
            if (!flock($fh, LOCK_EX | LOCK_NB)) {
                return false;   // in use
            }
            ($this->afterLock)($file);
            clearstatcache(true, $dir);
            clearstatcache(true, $file);
            $st = @lstat($dir);
            $now = @lstat($file);

            return $st !== false && ($st['mode'] & 0170000) === 0040000 && Restorer::sameFile(fstat($fh), $now) && $now !== false && $still($now)
                && self::remove($dir);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
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
