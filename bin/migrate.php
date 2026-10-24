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
    $spark = dirname(__DIR__) . '/ci4/spark';
    if (!file_exists($spark)) {
        fwrite(STDERR, "APOTHEKE_APP=ci4 is set but ci4/spark is not in this install.\n");
        exit(1);
    }
    $args = [PHP_BINARY, $spark, 'apotheke:migrate', '--no-header'];
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
} catch (PDOException $e) {
    // libpq's own text names the user ("password authentication failed for user …").
    // With zend.exception_ignore_args off, the trace includes the DSN and the user.
    // On PHP 8.2 and newer the password argument is shown as SensitiveParameterValue.
    // The password text appears only when it is embedded in the DSN and
    // zend.exception_string_param_max_len is raised.
    // SQLite open errors do not name a login; leave those as the engine reports them.
    if ($driver !== 'sqlite') {
        fwrite(STDERR, db_connect_failure_message($driver, config_migrate_where($c['db'])) . "\n");
        exit(1);
    }
    throw $e;
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
