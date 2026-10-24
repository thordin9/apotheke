<?php

declare(strict_types=1);

namespace Apotheke\Core\Libraries;

/**
 * Versioned URL of a bundled asset (legacy asset()): /assets/<file>?v=<8 hex>
 * from the file's mtime and size, '0' when the file is missing.
 */
final class AssetUrl
{
    public function __construct(private readonly string $assetsDir)
    {
    }

    public function url(string $file): string
    {
        $path = $this->path($file);
        $v = is_file($path) ? substr(md5((string)filemtime($path) . filesize($path)), 0, 8) : '0';

        return '/assets/' . $file . '?v=' . $v;
    }

    public function path(string $file): string
    {
        return rtrim($this->assetsDir, '/') . '/' . $file;
    }
}
