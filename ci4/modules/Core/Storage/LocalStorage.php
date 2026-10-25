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
    /** $fileMode: chmod for written files (originals use 0640); null keeps the umask's mode. */
    public function __construct(private readonly string $root, private readonly ?int $fileMode = null)
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
        $path = $this->path($key);
        if ($path === null) {
            throw new InvalidArgumentException('Invalid storage key.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
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
        $written = 0;
        $len = strlen($bytes);
        while ($written < $len) {
            $n = fwrite($fh, substr($bytes, $written));
            if ($n === false || $n === 0) {
                fclose($fh);
                @unlink($tmp);
                throw new RuntimeException('Could not store the file.');
            }
            $written += $n;
        }
        fclose($fh);
        if ($this->fileMode !== null) {
            @chmod($tmp, $this->fileMode);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not store the file.');
        }
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
