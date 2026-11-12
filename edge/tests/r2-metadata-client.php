<?php

declare(strict_types=1);

// The PHP side of r2.test.mjs's real-HTTP metadata test: R2Storage over the curl bridge, base URL and timeout from argv.
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;

$root = dirname(__DIR__, 2);
require $root . '/modules/Core/Storage/Storage.php';
require $root . '/modules/Core/Storage/VersionedStorage.php';
require $root . '/modules/Core/Storage/R2StorageException.php';
require $root . '/modules/Core/Storage/R2Bridge.php';
require $root . '/modules/Core/Storage/R2Storage.php';

$storage = new R2Storage(new R2Bridge($argv[1], timeout: (int)$argv[2]));
echo json_encode([
    'exists' => $storage->exists('images/a.jpg'),
    'modified' => $storage->modifiedAt('images/a.jpg'),
    'missing' => $storage->exists('images/none.jpg'),
    'missingModified' => $storage->modifiedAt('images/none.jpg'),
], JSON_THROW_ON_ERROR);
