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
    public function __construct(private readonly string $root)
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
        if (@file_put_contents($path, $bytes) === false) {
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

    /** Absolute path, or null when the key would leave the root. */
    private function path(string $key): ?string
    {
        if ($key === '' || str_contains($key, "\0") || str_contains($key, '\\') || str_starts_with($key, '/')) {
            return null;
        }
        foreach (explode('/', $key) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }

        return rtrim($this->root, '/') . '/' . $key;
    }
}
