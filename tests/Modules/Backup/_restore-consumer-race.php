<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Apotheke\Backup\RestoreUploads;
use Apotheke\Core\Storage\LocalStorage;

[, $data, $mode, $id, $ordinal] = $argv + [4 => '0'];
$wait = static function (string $file) use ($data): void {
    for ($i = 0; !is_file($data . '/' . $file) && $i < 5000; $i++) usleep(1000);
    if (!is_file($data . '/' . $file)) throw new RuntimeException('Gate timeout.');
};
try {
    $u = new RestoreUploads($data, 4, 100, static fn (): string => 'user:1');
    if ($mode === 'confirm') {
        touch($data . '/competing-' . $ordinal);
        for ($i = 0; count(glob($data . '/competing-*')) !== 2 && $i < 5000; $i++) usleep(1000);
        $u->confirm($id, static function (string $zip) use ($data, $wait): array {
            file_put_contents($data . '/applied', (string)getmypid() . "\n", FILE_APPEND | LOCK_EX);
            $wait('confirm-gate');
            return ['applied' => file_get_contents($zip)];
        });
        echo 'confirmed';
    } elseif ($mode === 'hold') {
        $s = new LocalStorage($data, dirMode: 0770);
        $s->withLease('uploads/restore/' . $id . '/manifest.json', static function () use ($data, $wait): void {
            touch($data . '/hold-ready');
            $wait('hold-gate');
        });
        echo 'released';
    } elseif ($mode === 'create') {
        touch($data . '/create-ready');
        $u->create(3, null);
        echo 'created';
    } else {
        throw new InvalidArgumentException('Unknown actor.');
    }
} catch (Throwable $e) {
    echo get_class($e) . ':' . $e->getCode();
}
