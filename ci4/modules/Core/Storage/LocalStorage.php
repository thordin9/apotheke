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
final class LocalStorage implements Storage
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
     * into place. A write that fails part way (a full disk, N-452) removes
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
        $tmp = $dir . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        $fh = @fopen($tmp, 'xb');
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
        if ($this->fileMode !== null) {
            @chmod($tmp, $this->fileMode);
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
