#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – run database migrations.
 *   php bin/migrate.php            apply pending migrations (also adopts legacy SQLite v1–v3 installs, with backup)
 *   php bin/migrate.php --status   list applied / pending migrations
 * Migrations also run automatically on the first request, so this is optional (handy for deploys/CI).
 */

if (PHP_SAPI !== 'cli') exit(1);
if (getenv('APOTHEKE_APP') === 'ci4') {
    $args = [PHP_BINARY, dirname(__DIR__) . '/ci4/spark', 'apotheke:migrate', '--no-header'];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--status') $args[] = '--status';
    }
    $command = implode(' ', array_map('escapeshellarg', $args));
    passthru($command, $code);
    exit($code);
}
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';

$c = cfg();
$driver = $c['db']['driver'];
echo 'Database: ' . $driver . ' – ' . config_migrate_where($c['db']) . "\n";
try {
    $pdo = db_connect($c['db']);
} catch (PDOException) {
    fwrite(STDERR, 'Could not connect to the database (' . $driver . ' ' . config_migrate_where($c['db']) . ").\n");
    exit(1);
}

if (in_array('--status', $argv, true)) {
    $applied = migrations_applied($pdo, $driver);
    foreach (migrations_available() as $v => $f) {
        printf("  %s  %-40s %s\n", $v, substr(basename($f, '.php'), 5), isset($applied[$v]) ? 'applied ' . $applied[$v] : 'PENDING');
    }
    exit(0);
}

$done = migrate($pdo, $driver, ['data_dir' => $c['data_dir'], 'seed' => $c['seed_defaults'], 'log' => fn(string $m) => print("  $m\n")]);
echo $done ? 'Done: ' . count($done) . " step(s).\n" : "Already up to date.\n";
