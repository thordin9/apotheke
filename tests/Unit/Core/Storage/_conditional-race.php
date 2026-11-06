<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/vendor/autoload.php';
use Apotheke\Core\Storage\LocalStorage;
[$_, $dir] = $argv;
$s = new LocalStorage($dir);
$first = $s->version('manifest.json');
file_put_contents($dir . '/done', '1');
while (!is_file($dir . '/gate')) {
    usleep(1000);
    if (!is_file($dir . '/run')) throw new RuntimeException('test aborted');
}
$result = $s->putIfVersion('manifest.json', $first[1], 'last');
echo var_export([$result, $s->version('manifest.json')[0]], true);
