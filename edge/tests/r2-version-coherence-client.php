<?php

declare(strict_types=1);

use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;

$root = dirname(__DIR__, 2);
require $root . '/modules/Core/Storage/Storage.php';
require $root . '/modules/Core/Storage/VersionedStorage.php';
require $root . '/modules/Core/Storage/R2StorageException.php';
require $root . '/modules/Core/Storage/R2Bridge.php';
require $root . '/modules/Core/Storage/R2Storage.php';

$storage = new R2Storage(new R2Bridge($argv[1]));
$version = $storage->version('backups/manifest.json');
$replaced = $storage->putIfVersion('backups/manifest.json', $version[1], 'replacement-from-A');
echo json_encode(['version' => $version, 'replaced' => $replaced], JSON_THROW_ON_ERROR);
