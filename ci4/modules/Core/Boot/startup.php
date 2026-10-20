<?php

declare(strict_types=1);

use Apotheke\Core\Boot\BootError;
use Apotheke\Core\Boot\StoragePreflight;
use Apotheke\Core\Boot\StorageRefused;
use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DsnException;
use Apotheke\Core\Config\DsnTranslator;

require_once __DIR__ . '/BootError.php';
require_once __DIR__ . '/StoragePreflight.php';
require_once dirname(__DIR__) . '/Config/ApothekeConfig.php';
require_once dirname(__DIR__) . '/Config/DsnTranslator.php';

/**
 * Runs from Config/Boot before Composer's autoloader. A bad DSN or photo
 * storage inside the web root stops the process here.
 */
try {
    $config = ApothekeConfig::fromEnvironment();
    (new DsnTranslator())->translate($config->database());
    $timezone = (string)($config->values()['timezone'] ?? 'UTC');
    date_default_timezone_set($timezone !== '' ? $timezone : 'UTC');
    $web = defined('FCPATH') ? rtrim(FCPATH, '/\\') : ApothekeConfig::repoRoot() . '/ci4/public';
    (new StoragePreflight())->prepare($config->dataDir(), $config->originalsDir(), $web);
} catch (DsnException $e) {
    BootError::send($e->getMessage(), $e->getMessage());
} catch (StorageRefused $e) {
    BootError::send($e->getMessage(), $e->logMessage);
}
