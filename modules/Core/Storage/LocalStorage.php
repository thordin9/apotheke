<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

use InvalidArgumentException;
use RuntimeException;

/**
 * Storage on local disk. The root is the catalog data directory
 * (DATA_DIR), not CodeIgniter's `writable/` folder. Keys are relative
 * (`autofill/info.json`, `autofill/tmp/autofill-….jpg`).
 */
final class LocalStorage implements Storage, VersionedStorage
{
    /**
     * $fileMode: chmod for written files (originals use 0640); null keeps the umask's mode.
     * $dirMode: mode for missing directories, before the umask (attachments use legacy's 0770).
     */
    public function __construct(private readonly string $root, private readonly ?int $fileMode = null, private readonly int $dirMode = 0700)
    {
    }

    public function read(string $key): ?string
    {
        $path = $this->path($key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    public function write(string $key, string $bytes): void
    {
        $this->put($key, static fn ($fh): bool => self::writeAll($fh, $bytes));
    }

    public function writeStream(string $key, mixed $stream): void
    {
        $this->put($key, static function ($fh) use ($stream): bool {
            while (!feof($stream)) {
                $chunk = @fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    return false;
                }
                if (!self::writeAll($fh, $chunk)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Write through a hidden temp file in the key's folder, then rename it
     * into place. The temp file is created with its final mode (through the
     * umask, so nothing changes it by name later): $fileMode, else the mode
     * of the plain file it replaces (without execute bits), else the umask's.
     * The file belongs to the user that writes it; the restore command line
     * runs as DATA_DIR's owner (L-590). A write that fails part way (a full disk, N-452) removes
     * the temp file, also when a warning is thrown as an exception.
     *
     * @param \Closure(resource):bool $fill writes the bytes; false when it could not
     */
    private function put(string $key, \Closure $fill): void
    {
        $path = $this->path($key);
        if ($path === null) {
            throw new InvalidArgumentException('Invalid storage key.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, $this->dirMode, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not store the file.');
        }
        if ($this->path($key) === null) {
            throw new InvalidArgumentException('Invalid storage key.');
        }
        clearstatcache(true, $path);
        $named = @lstat($path);
        $mode = $this->fileMode ?? ($named !== false && ($named['mode'] & 0170000) === 0100000 ? $named['mode'] & 0777 : null);
        $tmp = $dir . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        $umask = $mode === null ? null : umask(~$mode & 0777);
        try {
            $fh = @fopen($tmp, 'xb');
        } finally {
            if ($umask !== null) {
                umask($umask);
            }
        }
        if ($fh === false) {
            throw new RuntimeException('Could not store the file.');
        }
        $ok = false;
        try {
            $ok = $fill($fh);
        } finally {
            // every byte, flushed and closed: a short write (a full disk) fails
            $ok = @fflush($fh) && $ok;
            $ok = @fclose($fh) && $ok;
            if (!$ok) {
                @unlink($tmp);
            }
        }
        if (!$ok) {
            throw new RuntimeException('Could not store the file.');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not store the file.');
        }
    }

    /** @param resource $fh */
    private static function writeAll($fh, string $bytes): bool
    {
        $len = strlen($bytes);
        $written = @fwrite($fh, $bytes);
        while ($written !== false && $written > 0 && $written < $len) {
            $n = @fwrite($fh, substr($bytes, $written));
            $written = $n === false || $n === 0 ? false : $written + $n;
        }

        return $written === $len;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    public function exists(string $key): bool
    {
        $path = $this->path($key);

        return $path !== null && is_file($path);
    }

    public function modifiedAt(string $key): ?int
    {
        $path = $this->path($key);
        if ($path === null) {
            return null;
        }
        clearstatcache(true, $path);
        $time = is_file($path) ? @filemtime($path) : false;

        return $time === false ? null : $time;
    }

    public function version(string $key): ?array
    {
        $path = $this->path($key);
        if ($path === null || !is_file($path)) return null;
        $fh = @fopen($path, 'rb');
        if ($fh === false) return null;
        try {
            $bytes = stream_get_contents($fh);
            if ($bytes === false) throw new RuntimeException('Could not read the file.');
            return [$bytes, hash('sha256', $bytes)];
        } finally {
            fclose($fh);
        }
    }

    public function putIfMissing(string $key, string $bytes): bool
    {
        $path = $this->path($key);
        if ($path === null) throw new InvalidArgumentException('Invalid storage key.');
        $tmpKey = (dirname($key) === '.' ? '' : dirname($key) . '/') . '.'. basename($key) . '.new-' . bin2hex(random_bytes(8));
        $tmp = $this->path($tmpKey);
        $this->put($tmpKey, static fn ($fh): bool => self::writeAll($fh, $bytes));
        $created = $tmp !== null && @link($tmp, $path);
        @unlink($tmp);
        clearstatcache(true, $path);
        if (!$created) {
            if (is_file($path)) return false;
            throw new RuntimeException('Could not store the file.');
        }
        return true;
    }

    public function putIfVersion(string $key, string $etag, string $bytes): bool
    {
        $path = $this->path($key);
        if ($path === null) throw new InvalidArgumentException('Invalid storage key.');
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), $this->dirMode, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Could not store the file.');
        }
        // This stable lock survives replacement of the data inode. It is the
        // local unit lock for this manifest, outside the object namespace.
        $lockDir = $this->root . '/.locks';
        if (!is_dir($lockDir) && !@mkdir($lockDir, $this->dirMode, true) && !is_dir($lockDir)) {
            throw new RuntimeException('Could not store the file.');
        }
        $lock = @fopen($lockDir . '/' . hash('sha256', $key) . '.lock', 'c+b');
        if ($lock === false) throw new RuntimeException('Could not store the file.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Could not store the file.');
            $current = $this->version($key);
            if ($current === null || !hash_equals($current[1], $etag)) return false;
            $this->put($key, static fn ($fh): bool => self::writeAll($fh, $bytes));
            return true;
        } finally {
            fclose($lock);
        }
    }

    /** ZIP spool path for a local source; no directory is created for a read. */
    public function pathFor(string $key): string
    {
        return $this->path($key) ?? throw new \InvalidArgumentException('Invalid storage key.');
    }

    /** Absolute path, or null when the key would leave the root. */
    private function path(string $key): ?string
    {
        if ($key === '' || str_contains($key, "\0") || str_contains($key, '\\') || str_starts_with($key, '/')) {
            return null;
        }
        $acc = rtrim($this->root, '/');
        foreach (explode('/', $key) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
            $acc .= '/' . $part;
            if (is_link($acc)) {
                return null;
            }
        }

        return $acc;
    }
}
