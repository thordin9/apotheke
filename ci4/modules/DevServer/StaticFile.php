<?php

declare(strict_types=1);

namespace Apotheke\DevServer;

/** A real file under the document root. Rejects "/", traversal and *.php. */
final class StaticFile
{
    public static function isStatic(string $docroot, string $uriPath): bool
    {
        if ($uriPath === '/' || str_ends_with(strtolower($uriPath), '.php')) {
            return false;
        }
        $root = realpath($docroot);
        if ($root === false) {
            return false;
        }
        $file = realpath($docroot . $uriPath);

        return $file !== false
            && is_file($file)
            && str_starts_with($file, $root . DIRECTORY_SEPARATOR);
    }
}
