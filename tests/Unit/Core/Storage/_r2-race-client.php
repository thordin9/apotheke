<?php
require dirname(__DIR__, 4) . '/vendor/autoload.php';
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
[$_, $dir, $name] = $argv;
ini_set('display_errors', 'stderr');
$s = new R2Storage(new R2Bridge('http://127.0.0.1:' . trim(file_get_contents($dir . '/port'))));
$observed = $s->version('manifest');
file_put_contents($dir . '/ready-' . $name, $observed[1]);
while (!is_file($dir . '/gate')) {
    usleep(1000);
    if (!is_file($dir . '/run')) throw new RuntimeException('test aborted');
}
file_put_contents($dir . '/result-' . $name, json_encode([$s->putIfVersion('manifest', $observed[1], $name), $s->version('manifest')]));
