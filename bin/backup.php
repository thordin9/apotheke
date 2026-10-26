#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – write a backup zip (portable JSON dump + photos).
 *   php bin/backup.php                     → apotheke-backup-<date>.zip in the current directory
 *   php bin/backup.php /backups/home.zip   → that file
 */

if (PHP_SAPI !== 'cli') exit(1);
// APOTHEKE_APP=ci4: the same command runs as `spark apotheke:backup` (same output and exit codes).
if (getenv('APOTHEKE_APP') === 'ci4') {
    $spark = dirname(__DIR__) . '/ci4/spark';
    if (!file_exists($spark)) {
        fwrite(STDERR, "APOTHEKE_APP=ci4 is set but ci4/spark is not in this install.\n");
        exit(1);
    }
    putenv('APOTHEKE_CLI_CWD=' . getcwd());   // spark changes to ci4/public; file names are relative to here
    putenv('APOTHEKE_CLI_ARGS=' . json_encode(array_slice($argv, 1)));   // as typed: spark would take --help itself
    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $spark, 'apotheke:backup', '--no-header']));
    passthru($command, $code);
    exit($code);
}
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';
require dirname(__DIR__) . '/src/backup.php';

$out = $argv[1] ?? ('apotheke-backup-' . date('Y-m-d-His') . '.zip');
if (in_array($out, ['-h', '--help'], true)) { fwrite(STDOUT, "Usage: php bin/backup.php [file.zip]\n"); exit(0); }
$r = backup_create(db(), db_driver(), DATA_DIR, $out);
echo 'Backup written: ' . $out . ' (' . number_format($r['bytes'] / 1048576, 1) . " MB)\n";
echo '  ' . backup_summary_text($r['tables'], $r['photos']) . "\n";
foreach ($r['missing'] as $f) fwrite(STDERR, "  warning: photo file $f is missing on disk\n");
