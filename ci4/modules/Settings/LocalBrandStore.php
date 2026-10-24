<?php

declare(strict_types=1);

namespace Apotheke\Settings;

use InvalidArgumentException;
use RuntimeException;

/** BrandStore on local disk, by default DATA_DIR/brand (legacy brand_dir()). */
final class LocalBrandStore implements BrandStore
{
    public function __construct(private readonly string $dir)
    {
    }

    public function has(string $name): bool
    {
        return $this->path($name) !== null;
    }

    public function read(string $name): ?string
    {
        $path = $this->path($name);
        if ($path === null) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    public function size(string $name): ?int
    {
        $path = $this->path($name);
        $size = $path === null ? false : @filesize($path);

        return $size === false ? null : $size;
    }

    public function modifiedAt(string $name): ?int
    {
        $path = $this->path($name);
        $time = $path === null ? false : @filemtime($path);

        return $time === false ? null : $time;
    }

    public function put(string $name, string $bytes): void
    {
        if (!isset(self::FILES[$name])) {
            throw new InvalidArgumentException('Not a brand file name.');
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Server could not save the logo.');
        }
        if (@file_put_contents($this->dir . '/' . $name, $bytes) === false) {
            throw new RuntimeException('Server could not save the logo.');
        }
    }

    public function clear(): void
    {
        foreach (array_keys(self::FILES) as $name) {
            @unlink($this->dir . '/' . $name);
        }
    }

    /** The file path when the name is allowed and the file exists. */
    private function path(string $name): ?string
    {
        if (!isset(self::FILES[$name])) {
            return null;
        }
        $path = $this->dir . '/' . $name;
        clearstatcache(true, $path);

        return is_file($path) ? $path : null;
    }
}
