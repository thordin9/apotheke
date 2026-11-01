<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Exceptions\UserError;
use Closure;

/**
 * Chunked restore uploads (POST /api/v1/restores…): a backup zip sent in pieces, for hosts that cap a request body.
 * Each upload is a folder DATA_DIR/uploads/restore/<32 hex>/ with manifest.json (owner, declared size, optional
 * SHA-256, bytes received), data.part while it is sent, and backup.zip once finish() has checked it. Only this class
 * writes there; nothing is followed through a link. Chunks come in order, every one but the last exactly
 * $chunkBytes long. The zip itself is checked and restored by Restorer, as a one-shot upload is. An upload is its
 * owner's only (another account's id is not found), and one idle for IDLE seconds is removed by sweep().
 *
 * @phpstan-type Manifest array{owner:string,size:int,sha256:?string,chunk_size:int,received:int,next:int,status:string}
 */
final class RestoreUploads
{
    /** Seconds without a request after which an upload is removed. */
    public const IDLE = 3600;

    public const GUEST = 'Restores need a signed-in account.';

    public const NOT_FOUND = 'Restore upload not found.';

    private const ID = '/^[a-f0-9]{32}$/D';

    private const MANIFEST = 'manifest.json';

    private const PART = 'data.part';

    private const ZIP = 'backup.zip';

    /** @param Closure():?string $owner this request's account key ("user:<id>", "local" with sign-in off), null for a guest */
    public function __construct(
        private readonly string $dataDir,
        private readonly int $chunkBytes,
        private readonly int $maxBytes,
        private readonly Closure $owner,
    ) {
    }

    public function chunkBytes(): int
    {
        return $this->chunkBytes;
    }

    /**
     * Start an upload of $size bytes ($sha256: the client's hex digest of the whole file, or null). Your earlier
     * unfinished upload is removed first: one at a time per account.
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
        foreach ($this->ids($root) as $old) {
            if (($m = self::read($root . '/' . $old)) !== null && hash_equals($m['owner'], $owner)) {
                self::remove($root . '/' . $old);
            }
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
     *
     * @param Closure(string, Manifest):array<string,mixed> $open runs on the zip's path while the upload is locked
     * @return array<string,mixed> what $open returned
     */
    public function finish(string $id, Closure $open): array
    {
        return $this->locked($id, function (string $dir, array $m, $fh) use ($open): array {
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
     * Run $restore on the checked zip of upload $id, while it is locked. The upload is removed afterwards, unless
     * $restore throws a 409 (another restore is running), so it can be confirmed again.
     *
     * @template T
     * @param Closure(string):T $restore
     * @return T
     */
    public function confirm(string $id, Closure $restore): mixed
    {
        return $this->locked($id, function (string $dir, array $m) use ($restore): mixed {
            if ($m['status'] !== 'ready') {
                throw new UserError('Finish the upload first.', 409);
            }
            try {
                $out = $restore($dir . '/' . self::ZIP);
            } catch (\Throwable $e) {
                if ($e->getCode() !== 409) {
                    self::remove($dir);
                }
                throw $e;
            }
            self::remove($dir);

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

    /** Remove every upload idle for IDLE seconds or more; returns the checked root folder ($create: make it if missing). */
    public function sweep(?int $now = null, bool $create = true): string
    {
        $root = $this->root($create);
        $limit = ($now ?? time()) - self::IDLE;
        foreach ($this->ids($root) as $id) {
            $named = @lstat($root . '/' . $id . '/' . self::MANIFEST) ?: @lstat($root . '/' . $id);
            if ($named !== false && $named['mtime'] <= $limit) {
                self::remove($root . '/' . $id);
            }
        }

        return $root;
    }

    /** How many uploads are there (any owner)? For the sweep command's report. */
    public function count(): int
    {
        return count($this->ids($this->root(false)));
    }

    /** this request's owner key; guests are refused */
    private function me(): string
    {
        $me = ($this->owner)();
        if ($me === null || $me === '') {
            throw new UserError(self::GUEST, 403);
        }

        return $me;
    }

    /**
     * DATA_DIR/uploads/restore, made if missing (0770) when $create (the command line, which may run as root, never
     * makes it). A link or anything but a folder at either name, or a real path elsewhere, is refused.
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
            rewind($fh);
            $m = self::decode((string)stream_get_contents($fh)) ?? throw new UserError(self::NOT_FOUND, 404);
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
        if (!is_array($m) || !is_string($m['owner'] ?? null) || !in_array($m['status'] ?? null, ['uploading', 'ready'], true)
            || array_filter($ints, static fn (string $k): bool => !is_int($m[$k] ?? null)) !== [] || !(($m['sha256'] ?? null) === null || is_string($m['sha256']))) {
            return null;
        }

        return ['owner' => $m['owner'], 'size' => $m['size'], 'sha256' => $m['sha256'] ?? null, 'chunk_size' => $m['chunk_size'],
            'received' => $m['received'], 'next' => $m['next'], 'status' => $m['status']];
    }

    /**
     * @param resource $fh the locked manifest
     * @param Manifest $m
     * @return Manifest
     */
    private static function write($fh, array $m): array
    {
        $json = (string)json_encode($m);
        if (!ftruncate($fh, 0) || !rewind($fh) || fwrite($fh, $json) !== strlen($json) || !fflush($fh)) {
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

    /** Remove an upload folder: its entries (a link itself, never what it points at), then the folder. */
    private static function remove(string $dir): void
    {
        foreach (@scandir($dir) ?: [] as $n) {
            if ($n !== '.' && $n !== '..' && ($st = @lstat($dir . '/' . $n)) !== false && ($st['mode'] & 0170000) !== 0040000) {
                @unlink($dir . '/' . $n);
            }
        }
        @rmdir($dir);
    }
}
