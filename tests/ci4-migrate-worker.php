<?php

declare(strict_types=1);

/**
 * Subprocess for the CI4 migration tests.
 *   php tests/ci4-migrate-worker.php run legacy|ci4
 *   php tests/ci4-migrate-worker.php golden legacy|ci4
 *   php tests/ci4-migrate-worker.php lock legacy|ci4
 *
 * run/lock honour DATA_DIR, DB_*, APOTHEKE_CLOCK, SEED_DEFAULTS and MIGRATE_DIR.
 * The ci4 mode loads src/config.php and the shim, not the rest of src/.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$mode = $argv[1] ?? '';
$which = $argv[2] ?? '';
$root = dirname(__DIR__);

if (!in_array($mode, ['run', 'golden', 'lock'], true) || !in_array($which, ['legacy', 'ci4'], true)) {
    fwrite(STDERR, "usage: ci4-migrate-worker.php run|golden|lock legacy|ci4\n");
    exit(2);
}

if ($mode === 'golden') {
    if ($which === 'legacy') {
        require $root . '/src/bootstrap.php';
        require $root . '/src/items.php';
    } else {
        require $root . '/src/config.php';
        require $root . '/modules/Migrations/LegacyFunctions.php';
    }
    echo json_encode(golden_payload(), JSON_THROW_ON_ERROR);
    exit(0);
}

if ($which === 'legacy') {
    require $root . '/src/bootstrap.php';
    require $root . '/src/items.php';
    $cfg = cfg();
    try {
        $pdo = db_connect($cfg['db']);
    } catch (PDOException) {
        fwrite(STDERR, 'Could not connect to the database (' . $cfg['db']['driver'] . ' ' . config_migrate_where($cfg['db']) . ").\n");
        exit(1);
    }
    $done = migrate($pdo, (string)$cfg['db']['driver'], worker_opt($cfg));
    $pdo = null;
} else {
    require $root . '/modules/Migrations/PdoMigrationRunner.php';
    $dir = getenv('MIGRATE_DIR');
    $migrations = is_string($dir) && $dir !== '' ? $dir : $root . '/migrations';
    $runner = new Apotheke\Migrations\PdoMigrationRunner($migrations);
    $cfg = cfg();
    $pdo = $runner->connect($cfg['db']);
    try {
        $done = $runner->migrate($pdo, (string)$cfg['db']['driver'], worker_opt($cfg));
    } finally {
        $runner->close($pdo);
    }
}

$lock = null;
if ($mode === 'lock' && $cfg['db']['driver'] === 'sqlite') {
    $sqlite = new SQLite3((string)$cfg['db']['sqlite_path']);
    $sqlite->busyTimeout(0);
    $locked = $sqlite->exec('BEGIN EXCLUSIVE');
    if ($locked) {
        $sqlite->exec('ROLLBACK');
    }
    $checkpoint = $sqlite->querySingle('PRAGMA wal_checkpoint(TRUNCATE)');
    $lock = ['exclusive' => $locked, 'checkpoint' => $checkpoint];
    $sqlite->close();
}

$src = [];
$srcRoot = realpath($root . '/src');
if ($which === 'ci4' && is_string($srcRoot)) {
    $prefix = $srcRoot . DIRECTORY_SEPARATOR;
    foreach (get_included_files() as $file) {
        $real = realpath($file);
        if (!is_string($real) || !str_starts_with($real, $prefix)) {
            continue;
        }
        if ($real === $srcRoot . DIRECTORY_SEPARATOR . 'config.php') {
            continue;
        }
        $src[] = substr($real, strlen($prefix));
    }
}

echo json_encode(['applied' => array_values($done), 'src' => $src, 'lock' => $lock], JSON_THROW_ON_ERROR);

/** @param array<string,mixed> $cfg */
function worker_opt(array $cfg): array
{
    $dir = getenv('MIGRATE_DIR');

    return [
        'data_dir' => (string)$cfg['data_dir'],
        'seed' => (bool)$cfg['seed_defaults'],
        'dir' => is_string($dir) && $dir !== '' ? $dir : null,
        'log' => static function (string $message): void {
        },
    ];
}

/** @return array<string,mixed> */
function golden_payload(): array
{
    $fold = [];
    foreach (['Άθήνα', 'Café', 'naïve', 'Straße', 'Πρίζα', 'Κουτί 5'] as $sample) {
        $fold[$sample] = fold($sample);
    }
    $natkey = [];
    foreach (['Item 2', 'Item 10', 'Δωμάτιο 3', 'box 1023', '  BOX   1023 '] as $sample) {
        $natkey[$sample] = natkey($sample);
    }
    $clean = [];
    foreach (['  a   b  ', "x\ty\nz", 'Κουτί'] as $sample) {
        $clean[$sample] = clean_line($sample, 12);
    }
    $schema = settings_schema();
    $defaults = [];
    foreach (array_keys($schema) as $key) {
        $defaults[$key] = setting_default($key);
    }

    return [
        'fold' => $fold,
        'natkey' => $natkey,
        'clean' => $clean,
        'now' => now_utc(),
        'roles' => ROLE_DEFAULTS,
        'schema' => $schema,
        'defaults' => $defaults,
        'tags' => tags_parse("Home, #New\nnew; Café"),
    ];
}
