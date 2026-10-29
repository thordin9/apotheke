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
$spark = dirname(__DIR__) . '/spark';
// started as root: run as DATA_DIR's owner before anything else, or stop (L-622, N-625; DataDirOwner)
require_once dirname(__DIR__) . '/modules/Backup/Commands/DataDirOwner.php';
if (!\Apotheke\Backup\Commands\DataDirOwner::forCommandLine(STDERR, 'php bin/migrate.php')) exit(1);
$args = [PHP_BINARY, $spark, 'apotheke:migrate', '--no-header'];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--status') $args[] = '--status';
}
$command = implode(' ', array_map('escapeshellarg', $args));
passthru($command, $code);
exit($code);
