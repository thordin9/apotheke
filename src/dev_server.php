<?php
declare(strict_types=1);

/**
 * Built-in PHP server (`php -S … -t public public/index.php`): whether $uriPath is a
 * real static file under $docroot. Rejects path traversal (`/../…`) and *.php.
 */
function dev_server_static_file(string $docroot, string $uriPath): bool
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
