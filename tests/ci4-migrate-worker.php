<?php

declare(strict_types=1);

/**
 * Subprocess for tests/Unit/Ci4MigrateTest.php: one PdoMigrationRunner run,
 * set up as the apotheke:migrate command sets it up.
 *   php tests/ci4-migrate-worker.php
 *
 * Honours DATA_DIR, DB_*, SQLITE_PATH, APOTHEKE_CLOCK, SEED_DEFAULTS,
 * APP_PASSWORD and MIGRATE_DIR (a directory with a slice of the migrations).
 * Prints {"applied": [...]} as JSON, or the error on stderr with exit 1.
 */

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Migrations\PdoMigrationRunner;

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$config = ApothekeConfig::fromEnvironment();
$db = $config->database();
$runner = new PdoMigrationRunner($root . '/migrations');
apotheke_migration_cfg($config->values());
$dir = getenv('MIGRATE_DIR');
$pdo = $runner->connect($db);
try {
    $done = $runner->migrate($pdo, (string)$db['driver'], [
        'data_dir' => $config->dataDir(),
        'seed' => (bool)($config->values()['seed_defaults'] ?? false),
        'dir' => is_string($dir) && $dir !== '' ? $dir : null,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $runner->close($pdo);
}
echo json_encode(['applied' => array_values($done)], JSON_THROW_ON_ERROR);
