#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – write a backup zip (portable JSON dump + photos).
 *   php bin/backup.php                     → apotheke-backup-<date>.zip in the current directory
 *   php bin/backup.php /backups/home.zip   → that file
 */

if (PHP_SAPI !== 'cli') exit(1);
// Runs as `spark apotheke:backup`.
$spark = dirname(__DIR__) . '/spark';
// started as root: run as DATA_DIR's owner before anything else, or stop (L-622, N-625; DataDirOwner)
require_once dirname(__DIR__) . '/modules/Backup/Commands/DataDirOwner.php';
if (!\Apotheke\Backup\Commands\DataDirOwner::forCommandLine(STDERR, 'php bin/backup.php')) exit(1);
putenv('APOTHEKE_CLI_CWD=' . getcwd());   // spark changes to public; file names are relative to here
putenv('APOTHEKE_CLI_ARGS=' . json_encode(array_slice($argv, 1)));   // as typed: spark would take --help itself
$command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $spark, 'apotheke:backup', '--no-header']));
passthru($command, $code);
exit($code);
