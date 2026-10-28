#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – write a backup zip (portable JSON dump + photos).
 *   php bin/backup.php                     → apotheke-backup-<date>.zip in the current directory
 *   php bin/backup.php /backups/home.zip   → that file
 */

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/config.php';
require dirname(__DIR__) . '/src/cli-owner.php';
// started as root: continue as the data folder's owner before anything is read or written, or stop (L-622)
cli_data_owner_or_exit('php bin/backup.php');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';
require dirname(__DIR__) . '/src/backup.php';

$out = $argv[1] ?? ('apotheke-backup-' . date('Y-m-d-His') . '.zip');
if (in_array($out, ['-h', '--help'], true)) { fwrite(STDOUT, "Usage: php bin/backup.php [file.zip]\n"); exit(0); }
$r = backup_create(db(), db_driver(), DATA_DIR, $out);
echo 'Backup written: ' . $out . ' (' . number_format($r['bytes'] / 1048576, 1) . " MB)\n";
echo '  ' . backup_summary_text($r['tables'], $r['photos']) . "\n";
foreach ($r['missing'] as $f) fwrite(STDERR, "  warning: photo file $f is missing on disk\n");
