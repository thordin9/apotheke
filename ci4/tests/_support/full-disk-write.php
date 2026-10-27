<?php

declare(strict_types=1);

/*
 * LocalStorageTest's writer on a full disk (N-452): run under a file size
 * limit, with warnings turned into exceptions as CodeIgniter does. Stores
 * 256 kB at files/a.bin under argv[1], through write() or writeStream()
 * (argv[2]), and prints "stored" or the class of what was thrown.
 */

use Apotheke\Core\Storage\LocalStorage;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$store = new LocalStorage($argv[1]);
$bytes = str_repeat('x', 256 * 1024);
try {
    if ($argv[2] === 'stream') {
        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, $bytes);
        rewind($fh);
        $store->writeStream('files/a.bin', $fh);
    } else {
        $store->write('files/a.bin', $bytes);
    }
    echo "stored\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
