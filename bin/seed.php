#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – seed data (idempotent; safe to run repeatedly).
 *   php bin/seed.php           starter locations & categories (one default each), skipping existing names
 *   php bin/seed.php --demo    …plus a handful of demo items with generated photos
 */

if (PHP_SAPI !== 'cli') exit(1);
// APOTHEKE_APP=ci4: runs as `spark apotheke:seed` (same output)
if (getenv('APOTHEKE_APP') === 'ci4') {
    $spark = dirname(__DIR__) . '/ci4/spark';
    if (!file_exists($spark)) {
        fwrite(STDERR, "APOTHEKE_APP=ci4 is set but ci4/spark is not in this install.\n");
        exit(1);
    }
    // started as root: run as DATA_DIR's owner before anything else, or stop (L-622, N-625; DataDirOwner)
    require_once dirname(__DIR__) . '/ci4/modules/Backup/Commands/DataDirOwner.php';
    if (!\Apotheke\Backup\Commands\DataDirOwner::forCommandLine(STDERR, 'php bin/seed.php')) exit(1);
    $args = [PHP_BINARY, $spark, 'apotheke:seed', '--no-header'];
    if (in_array('--demo', $argv, true)) $args[] = '--demo';
    passthru(implode(' ', array_map('escapeshellarg', $args)), $code);
    exit($code);
}
require dirname(__DIR__) . '/src/config.php';
require dirname(__DIR__) . '/src/cli-owner.php';
// started as root: continue as the data folder's owner before anything is read or written, or stop (L-622)
cli_data_owner_or_exit('php bin/seed.php');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';
require_once dirname(__DIR__) . '/src/seed.php';

$pdo = db();   // runs pending migrations first
$n = seed_defaults($pdo, true);
echo "Starter locations/categories created: $n\n";
if (in_array('--demo', $argv, true)) {
    echo 'Demo items created: ' . seed_demo() . "\n";
}
