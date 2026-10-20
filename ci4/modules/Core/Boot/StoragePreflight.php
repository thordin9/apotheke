<?php

declare(strict_types=1);

namespace Apotheke\Core\Boot;

use RuntimeException;

/** Creates the data directories and refuses photo storage inside the web root. */
final class StoragePreflight
{
    public function prepare(string $dataDir, string $originalsDir, string $webRoot): void
    {
        $originals = rtrim($originalsDir, '/');
        $web = rtrim($webRoot, '/');
        if ($this->dirInWebRoot($originals, $web)) {
            throw new StorageRefused(
                'The catalog cannot start because photo storage is inside the public web directory. Set originals_dir to a directory outside public/.',
                'ORIGINALS_DIR is inside the web root (originals=' . $originals . ', web=' . $web . '). Set originals_dir to a directory outside public/.'
            );
        }
        $data = rtrim($dataDir, '/');
        foreach ([$data, $data . '/images', $data . '/thumbs', $originals, $originals . '/map', $data . '/sessions'] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $message = 'Data directory ' . $dir . ' is not writable.';
                throw new StorageRefused($message, $message);
            }
        }
        $this->chmodDir($originals);
        $this->chmodDir($originals . '/map');
        $this->writeHtaccess($originals . '/.htaccess');
        $this->chmodFile($originals . '/.htaccess');
    }

    public function dirInWebRoot(string $dir, string $webRoot): bool
    {
        $dir = $this->normalize($dir);
        $web = $this->normalize($webRoot);
        if ($dir === '' || $web === '') {
            return false;
        }
        if ($dir === $web || str_starts_with($dir, $web . '/')) {
            return true;
        }
        $realWeb = realpath($webRoot);
        $realDir = realpath($dir);
        if ($realWeb === false || $realDir === false) {
            return false;
        }
        $realWeb = $this->normalize($realWeb);
        $realDir = $this->normalize($realDir);

        return $realDir === $realWeb || str_starts_with($realDir, $realWeb . '/');
    }

    public function htaccess(): string
    {
        return "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    }

    private function writeHtaccess(string $path): void
    {
        $want = $this->htaccess();
        if (is_file($path) && (string)@file_get_contents($path) === $want) {
            return;
        }
        @file_put_contents($path, $want);
        $this->chmodFile($path);
    }

    private function chmodDir(string $dir): void
    {
        if (is_dir($dir)) {
            @chmod($dir, 0750);
        }
    }

    private function chmodFile(string $path): void
    {
        if (is_file($path)) {
            @chmod($path, 0640);
        }
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $abs = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return ($abs ? '/' : '') . implode('/', $parts);
    }
}

final class StorageRefused extends RuntimeException
{
    public function __construct(string $public, public readonly string $logMessage)
    {
        parent::__construct($public);
    }
}
