#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – restore a backup zip. REPLACES all catalog data.
 *   php bin/restore.php backup.zip --dry-run   validate and show what would be restored
 *   php bin/restore.php backup.zip             ask for confirmation, then restore
 *   php bin/restore.php backup.zip --yes       no prompt (scripts)
 */

if (PHP_SAPI !== 'cli') exit(1);
// Runs as `spark apotheke:restore`.
$spark = dirname(__DIR__) . '/spark';
// started as root: run as DATA_DIR's owner before CodeIgniter starts, or stop (DataDirOwner)
require_once dirname(__DIR__) . '/modules/Backup/Commands/DataDirOwner.php';
if (!\Apotheke\Backup\Commands\DataDirOwner::forCommandLine(STDERR, \Apotheke\Backup\Commands\DataDirOwner::RESTORE)) exit(1);
putenv('APOTHEKE_CLI_CWD=' . getcwd());   // spark changes to public; file names are relative to here
putenv('APOTHEKE_CLI_ARGS=' . json_encode(array_slice($argv, 1)));   // as typed: spark would take --help itself
$command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $spark, 'apotheke:restore', '--no-header']));
passthru($command, $code);
exit($code);
