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
$config = ApothekeConfig::fromEnvironment();
try {
    (new DsnTranslator())->translate($config->database());
} catch (DsnException $e) {
    // Spark is CLI, so apotheke:migrate can still print its banner and the
    // allowlisted connect line. A web request stops here.
    if (PHP_SAPI !== 'cli') {
        $driver = (string)($config->database()['driver'] ?? '');
        $log = DsnTranslator::logLine($driver, $e->dsn);
        BootError::send($e->getMessage(), $e->getMessage() === DsnException::PUBLIC_MESSAGE ? $log : $log . '. ' . $e->getMessage());
    }
}
try {
    $timezone = (string)($config->values()['timezone'] ?? 'UTC');
    date_default_timezone_set($timezone !== '' ? $timezone : 'UTC');
    $web = defined('FCPATH') ? rtrim(FCPATH, '/\\') : ApothekeConfig::repoRoot() . '/ci4/public';
    (new StoragePreflight())->prepare($config->dataDir(), $config->originalsDir(), $web);
} catch (StorageRefused $e) {
    BootError::send($e->getMessage(), $e->logMessage);
}
