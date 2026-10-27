<?php

declare(strict_types=1);

namespace Apotheke\Backup\Commands;

use Apotheke\Backup\Backups;
use Apotheke\Backup\Restorer;
use Apotheke\Core\Exceptions\UserError;
use RuntimeException;

/**
 * Legacy bin/backup.php and bin/restore.php, with the same output and exit
 * codes. Restore asks before it replaces the catalog unless --yes is given.
 */
final class BackupCli
{
    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private readonly Backups $backups,
        private readonly Restorer $restorer,
        private readonly string $dataDir,
        private readonly mixed $in,
        private readonly mixed $out,
        private readonly mixed $err,
        private readonly string $cwd = '',
    ) {
    }

    /**
     * The arguments bin/backup.php and bin/restore.php pass in
     * APOTHEKE_CLI_ARGS (a JSON list, so spark never reads --help as its
     * own), else $argv's.
     *
     * @param list<string> $argv
     * @return list<string>
     */
    public static function args(string|false $json, array $argv): array
    {
        $list = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($list) || !array_is_list($list)) {
            return $argv;
        }

        return array_map(static fn (mixed $a): string => is_scalar($a) ? (string)$a : '', $list);
    }

    /** $file as the user typed it, relative to the directory the command was started in. */
    private function path(string $file): string
    {
        return $this->cwd === '' || $file === '' || str_starts_with($file, '/') ? $file : rtrim($this->cwd, '/') . '/' . $file;
    }

    /**
     * bin/backup.php [file.zip]
     *
     * @param list<string> $args
     */
    public function backup(array $args): int
    {
        $file = $args[0] ?? ('apotheke-backup-' . date('Y-m-d-His') . '.zip');
        if (in_array($file, ['-h', '--help'], true)) {
            fwrite($this->out, "Usage: php bin/backup.php [file.zip]\n");

            return 0;
        }
        try {
            $r = $this->backups->create($this->path($file));
        } catch (RuntimeException $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return 1;
        }
        fwrite($this->out, 'Backup written: ' . $file . ' (' . number_format($r['bytes'] / 1048576, 1) . " MB)\n");
        fwrite($this->out, '  ' . Backups::summaryText($r['tables'], $r['photos']) . "\n");
        foreach ($r['missing'] as $f) {
            fwrite($this->err, "  warning: photo file $f is missing on disk\n");
        }

        return 0;
    }

    /**
     * bin/restore.php backup.zip [--dry-run] [--yes]
     *
     * @param list<string> $args
     */
    public function restore(array $args): int
    {
        $file = null;
        foreach ($args as $a) {
            if (!str_starts_with($a, '-')) {
                $file = $a;
            }
        }
        if ($file === null || in_array('--help', $args, true)) {
            fwrite($this->err, "Usage: php bin/restore.php backup.zip [--dry-run] [--yes]\n");

            return 2;
        }
        try {
            $b = $this->restorer->open($this->path($file));
            $b['zip']->close();
        } catch (UserError $e) {
            fwrite($this->err, 'Invalid backup: ' . $e->getMessage() . "\n");

            return 1;
        }
        fwrite($this->out, "Backup from {$b['created_at']} UTC ({$b['driver']}): " . Backups::summaryText($b['tables'], $b['photos']) . "\n");
        fwrite($this->out, 'Current catalog: ' . Backups::summaryText($this->backups->counts(), count(glob($this->dataDir . '/images/*.jpg') ?: [])) . "\n");
        foreach ($b['warnings'] as $w) {
            fwrite($this->out, "  warning: $w\n");
        }
        if (in_array('--dry-run', $args, true)) {
            fwrite($this->out, "Dry run: nothing changed.\n");

            return 0;
        }
        if (!in_array('--yes', $args, true)) {
            fwrite($this->out, 'Replace ALL current data with this backup? Type "yes": ');
            if (trim((string)fgets($this->in)) !== 'yes') {
                fwrite($this->out, "Aborted.\n");

                return 1;
            }
        }
        try {
            $r = $this->restorer->restore($this->path($file));
        } catch (UserError $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return 1;
        }
        fwrite($this->out, 'Restored: ' . Backups::summaryText($r['tables'], $r['photos']) . "\n");

        return 0;
    }
}
