<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/vendor/autoload.php';

$dir = $argv[1];
$name = $argv[2];
$storage = new Apotheke\Core\Storage\LocalStorage($dir);
$state = $storage->version('state.json') ?? throw new RuntimeException('Missing state.');
touch($dir . '/ready-' . $name);
for ($i = 0; !is_file($dir . '/gate') && $i < 5000; $i++) usleep(1000);
if (!is_file($dir . '/gate')) throw new RuntimeException('No gate.');
echo $storage->withLease('state.json', static fn (): bool => $storage->putIfVersion('state.json', $state[1], $name)) ? 'true' : 'false';
