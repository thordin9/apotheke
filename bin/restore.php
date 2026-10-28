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
require dirname(__DIR__) . '/src/config.php';
require dirname(__DIR__) . '/src/cli-owner.php';
// started as root: continue as the data folder's owner before anything is read or written, or stop
cli_data_owner_or_exit('php bin/restore.php backup.zip');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';
require dirname(__DIR__) . '/src/backup.php';

$args = array_slice($argv, 1);
$file = null;
foreach ($args as $a) if ($a[0] !== '-') $file = $a;
if ($file === null || in_array('--help', $args, true)) { fwrite(STDERR, "Usage: php bin/restore.php backup.zip [--dry-run] [--yes]\n"); exit(2); }

$pdo = db();
$driver = db_driver();
try {
    $b = backup_open($file, $pdo, $driver);
    $b['zip']->close();
} catch (UserError $e) {
    fwrite(STDERR, 'Invalid backup: ' . $e->getMessage() . "\n");
    exit(1);
}
$now = backup_counts($pdo, $driver);
echo "Backup from {$b['created_at']} UTC ({$b['driver']}): " . backup_summary_text($b['tables'], $b['photos']) . "\n";
echo 'Current catalog: ' . backup_summary_text($now, count(glob(DATA_DIR . '/images/*.jpg') ?: [])) . "\n";
foreach ($b['warnings'] as $w) echo "  warning: $w\n";
if (in_array('--dry-run', $args, true)) { echo "Dry run: nothing changed.\n"; exit(0); }
if (!in_array('--yes', $args, true)) {
    echo 'Replace ALL current data with this backup? Type "yes": ';
    if (trim((string)fgets(STDIN)) !== 'yes') { echo "Aborted.\n"; exit(1); }
}
try {
    $r = backup_restore($pdo, $driver, DATA_DIR, $file);
} catch (UserError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo 'Restored: ' . backup_summary_text($r['tables'], $r['photos']) . "\n";
// warnings the restore itself added (camera files that could not be linked, settings that were not restored; N-593)
foreach (array_diff($r['warnings'], $b['warnings']) as $w) echo "  warning: $w\n";
