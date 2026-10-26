<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Catalog\Items\Attachments;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Storage\Storage;
use Apotheke\Media\ImageLimits;
use Apotheke\Media\ImageStore;
use Apotheke\Settings\SettingsService;
use Apotheke\Undo\UndoTokens;
use Closure;
use CodeIgniter\Database\BaseConnection;
use GdImage;
use JsonException;
use Throwable;
use ZipArchive;

/**
 * Checking and restoring a backup zip (legacy backup_open() and
 * backup_restore()). Restore replaces all catalog data in one transaction;
 * the files go first, under their own random names, so nothing that exists
 * is overwritten, and files no longer named are kept.
 *
 * Every entry name must be one the backup writes, so no entry can name a
 * path outside its folder. Files are written through Storage (no symlinked
 * path, written whole or not at all). A missing thumbnail is drawn again
 * only within Media's decode limits.
 *
 * @phpstan-type Opened array{dump:array<string,mixed>,zip:ZipArchive,tables:array<string,int>,photos:int,created_at:string,driver:string,warnings:list<string>}
 */
final class Restorer
{
    /** Zip-bomb guard: the sizes the entries declare, unpacked. */
    public const MAX_UNPACKED = 4 * 1024 * 1024 * 1024;

    public const MAX_ENTRIES = 100_000;

    /** Legacy's quality for a thumbnail drawn again. */
    private const THUMB_QUALITY = 76;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param Closure():list<string> $known the migration versions this install has
     * @param Storage $data rooted at DATA_DIR (images/, thumbs/, files/)
     * @param Storage $originals rooted at ORIGINALS_DIR
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Backups $backups,
        private readonly SettingsService $settings,
        private readonly UndoTokens $undo,
        private readonly Closure $known,
        private readonly Storage $data,
        private readonly Storage $originals,
        private readonly int $thumbMax,
    ) {
    }

    public static function originalNameOk(string $base): bool
    {
        return preg_match('/^[a-f0-9]{64}\.(jpg|png|webp|gif|heic)$/D', $base) === 1;
    }

    /** May a backup hold an entry of this name? (the folders, and only the names the backup writes) */
    public static function entryNameOk(string $name): bool
    {
        return $name === 'backup.json' || $name === 'originals/index.json'
            || preg_match('#^(images|thumbs)/[a-f0-9]{32}\.jpg$#D', $name) === 1
            || preg_match('#^(images|thumbs|files|originals)/$#D', $name) === 1
            || preg_match('#^files/[a-f0-9]{32}\.(pdf|jpg|png|webp)$#D', $name) === 1
            || preg_match('#^originals/[a-f0-9]{64}\.(jpg|png|webp|gif|heic)$#D', $name) === 1;
    }

    /**
     * Open and check a backup zip without touching the database (legacy
     * backup_open()). The caller closes the zip.
     *
     * @return Opened
     */
    public function open(string $zipPath): array
    {
        $zip = new ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new UserError('That file is not a zip archive.');
        }
        try {
            return $this->check($zip);
        } catch (Throwable $e) {
            $zip->close();
            throw $e;
        }
    }

    /**
     * Replace all catalog data with the backup's (legacy backup_restore()).
     *
     * @return array{tables:array<string,int>,photos:int,warnings:list<string>,token_cleared:bool}
     */
    public function restore(string $zipPath): array
    {
        $b = $this->open($zipPath);
        $dump = $b['dump'];
        $zip = $b['zip'];
        $warnings = $b['warnings'];
        try {
            $written = $this->restorePhotos($zip, $dump, $warnings);
            $this->restoreAttachments($zip, $dump, $warnings);
            $this->restoreOriginals($zip, $dump, $warnings);
        } finally {
            $zip->close();
        }
        $this->restoreRows($dump);
        $cleared = false;
        if (is_array($dump['settings'] ?? null)) {
            $r = $this->settings->import((string)json_encode(['format' => 'apotheke-settings', 'settings' => $dump['settings']]), null);
            foreach ($r['errors'] as $k => $msg) {
                $warnings[] = "Setting $k was not restored: $msg";
            }
            $cleared = $r['token_cleared'];
        }
        // Undo tokens refer to the data that was just replaced.
        if ($this->backups->tableExists('undo_tokens')) {
            $this->undo->prune(true);
        }

        return ['tables' => $b['tables'], 'photos' => $written, 'warnings' => $warnings, 'token_cleared' => $cleared];
    }

    /** @return Opened */
    private function check(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new UserError('The backup has too many files.');
        }
        $total = 0;
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            $total += (int)($s['size'] ?? 0);
            $name = (string)($s['name'] ?? '');
            if (!self::entryNameOk($name)) {
                throw new UserError('Unexpected file in backup: ' . mb_substr($name, 0, 80));
            }
            // Stricter than legacy: one entry per name, so the check and the restore read the same bytes.
            if (isset($seen[$name])) {
                throw new UserError('Unexpected file in backup: ' . mb_substr($name, 0, 80));
            }
            $seen[$name] = true;
        }
        if ($total > self::MAX_UNPACKED) {
            throw new UserError('The backup is too large to unpack.');
        }
        $json = $zip->getFromName('backup.json');
        if ($json === false) {
            throw new UserError('This is not an Apotheke backup (backup.json is missing).');
        }
        try {
            $dump = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UserError('backup.json is not valid JSON.');
        }
        unset($json);
        if (!is_array($dump) || ($dump['format'] ?? null) !== Backups::FORMAT) {
            throw new UserError('This is not an Apotheke backup.');
        }
        if (!is_int($dump['version'] ?? null) || $dump['version'] > Backups::VERSION) {
            throw new UserError('This backup was made by a newer version of Apotheke.');
        }
        if (!is_array($dump['tables'] ?? null) || !is_array($dump['schema'] ?? null)) {
            throw new UserError('The backup is incomplete.');
        }
        $unknown = array_diff(array_map(static fn (mixed $v): string => is_scalar($v) ? (string)$v : 'Array', $dump['schema']), ($this->known)());
        if ($unknown !== []) {
            throw new UserError('This backup needs a newer version of Apotheke (schema ' . implode(', ', $unknown) . ').');
        }
        $allowed = Backups::tables();
        foreach ($dump['tables'] as $t => $tb) {
            if (!in_array($t, $allowed, true)) {
                throw new UserError('Unknown table in backup: ' . mb_substr((string)$t, 0, 40));
            }
            if (!is_array($tb) || !is_array($tb['columns'] ?? null) || !is_array($tb['rows'] ?? null)) {
                throw new UserError("Table $t is malformed.");
            }
            $have = $this->backups->columns($t);
            foreach ($tb['columns'] as $c) {
                if (!is_string($c) || !in_array($c, $have, true)) {
                    throw new UserError("Unknown column $t." . mb_substr(is_scalar($c) ? (string)$c : 'Array', 0, 40) . ' in backup.');
                }
            }
            $n = count($tb['columns']);
            foreach ($tb['rows'] as $r) {
                if (!is_array($r) || count($r) !== $n) {
                    throw new UserError("A row in $t has the wrong number of values.");
                }
                foreach ($r as $v) {
                    if (!is_scalar($v) && $v !== null) {
                        throw new UserError("A value in $t is not a plain value.");
                    }
                }
            }
        }
        foreach (['locations', 'categories', 'items'] as $t) {
            if (!isset($dump['tables'][$t])) {
                throw new UserError("The backup has no $t table.");
            }
        }
        /** @var array<string,mixed> $dump */
        $warnings = [];
        $photos = 0;
        foreach (self::photoFiles($dump) as $f) {
            if ($zip->locateName('images/' . $f) === false) {
                $warnings[] = "Photo $f is missing from the archive.";
                continue;
            }
            $photos++;
        }
        foreach (self::attachmentFiles($dump) as $f) {
            if ($zip->locateName('files/' . $f) === false) {
                $warnings[] = "Attachment $f is missing from the archive.";
            }
        }
        /** @var array<string,array{columns:list<string>,rows:list<list<mixed>>}> $tables */
        $tables = $dump['tables'];

        return ['dump' => $dump, 'zip' => $zip, 'tables' => array_map(static fn (array $t): int => count($t['rows']), $tables), 'photos' => $photos,
            'created_at' => is_scalar($dump['created_at'] ?? null) ? (string)$dump['created_at'] : '', 'driver' => is_scalar($dump['driver'] ?? null) ? (string)$dump['driver'] : '?', 'warnings' => $warnings];
    }

    /**
     * Photo names the dump's rows refer to (legacy backup_photo_files()).
     *
     * @param array<string,mixed> $dump
     * @return list<string>
     */
    public static function photoFiles(array $dump): array
    {
        $files = [];
        foreach (['item_photos' => 'file', 'items' => 'image'] as $t => $col) {
            foreach (self::column($dump, $t, $col) as $v) {
                if (ImageStore::validName($v)) {
                    $files[$v] = true;
                }
            }
        }
        ksort($files, SORT_STRING);

        return array_map('strval', array_keys($files));
    }

    /**
     * Attachment names the dump's rows refer to (legacy backup_attachment_files()).
     *
     * @param array<string,mixed> $dump
     * @return list<string>
     */
    public static function attachmentFiles(array $dump): array
    {
        $files = [];
        foreach (self::column($dump, 'item_attachments', 'file') as $v) {
            if (Attachments::validName($v)) {
                $files[$v] = true;
            }
        }
        ksort($files, SORT_STRING);

        return array_map('strval', array_keys($files));
    }

    /**
     * @param array<string,mixed> $dump
     * @return list<?string> the string values of one column
     */
    private static function column(array $dump, string $table, string $col): array
    {
        $tb = $dump['tables'][$table] ?? null;
        if (!is_array($tb) || !is_array($tb['columns'] ?? null) || !is_array($tb['rows'] ?? null)) {
            return [];
        }
        $i = array_search($col, $tb['columns'], true);
        if ($i === false) {
            return [];
        }
        $out = [];
        foreach ($tb['rows'] as $r) {
            $v = is_array($r) ? ($r[$i] ?? null) : null;
            $out[] = is_string($v) ? $v : null;
        }

        return $out;
    }

    /**
     * Served photos and thumbnails: each one must be a JPEG; one that exists
     * is kept. A missing thumbnail is drawn from the photo.
     *
     * @param array<string,mixed> $dump
     * @param list<string> $warnings
     */
    private function restorePhotos(ZipArchive $zip, array $dump, array &$warnings): int
    {
        $written = 0;
        foreach (self::photoFiles($dump) as $f) {
            $data = $zip->getFromName('images/' . $f);
            if ($data === false) {
                continue;
            }
            $info = @getimagesizefromstring($data);
            if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
                $warnings[] = "Photo $f is not a JPEG and was skipped.";
                continue;
            }
            if (!$this->data->exists('images/' . $f)) {
                $this->data->write('images/' . $f, $data);
            }
            if (!$this->data->exists('thumbs/' . $f)) {
                $thumb = $zip->getFromName('thumbs/' . $f);
                $ti = $thumb === false ? false : @getimagesizefromstring($thumb);
                if (is_string($thumb) && $ti !== false && $ti[2] === IMAGETYPE_JPEG) {
                    $this->data->write('thumbs/' . $f, $thumb);
                } elseif (!self::overDecodeLimits((int)$info[0], (int)$info[1]) && ($im = @imagecreatefromstring($data)) !== false) {
                    $this->data->write('thumbs/' . $f, $this->thumbJpeg($im));
                }
            }
            $written++;
        }

        return $written;
    }

    /** Stricter than legacy: a thumbnail is drawn again only within Media's decode limits. */
    public static function overDecodeLimits(int $w, int $h): bool
    {
        return ImageLimits::rasterHeaderOverLimit($w, $h, IMAGETYPE_JPEG) || (float)$w * (float)$h > (float)ImageLimits::GD_MAX_PIXELS;
    }

    /** Legacy imagejpeg(image_fit($im, image_thumb_max()), …, 76). */
    private function thumbJpeg(GdImage $src): string
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $s = min(1, $this->thumbMax / max($w, $h));
        $nw = max(1, (int)round($w * $s));
        $nh = max(1, (int)round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white === false ? 0 : $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, self::THUMB_QUALITY);

        return (string)ob_get_clean();
    }

    /**
     * Attachments whose content is the type their name says; one that exists is kept.
     *
     * @param array<string,mixed> $dump
     * @param list<string> $warnings
     */
    private function restoreAttachments(ZipArchive $zip, array $dump, array &$warnings): void
    {
        foreach (self::attachmentFiles($dump) as $f) {
            $data = $zip->getFromName('files/' . $f);
            if ($data === false || $this->data->exists('files/' . $f)) {
                continue;
            }
            if (Attachments::sniff(substr($data, 0, 16)) !== pathinfo($f, PATHINFO_EXTENSION)) {
                $warnings[] = "Attachment $f does not match its type and was skipped.";
                continue;
            }
            $this->data->write('files/' . $f, $data);
        }
    }

    /**
     * Camera files back next to their served photos (legacy
     * backup_restore_originals()). Bytes are written only when missing; the
     * map entry is rewritten to point at the file from this zip.
     *
     * @param array<string,mixed> $dump
     * @param list<string> $warnings
     */
    private function restoreOriginals(ZipArchive $zip, array $dump, array &$warnings): void
    {
        $raw = $zip->getFromName('originals/index.json');
        if (!is_string($raw) || $raw === '') {
            return;
        }
        try {
            $index = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $warnings[] = 'originals/index.json was skipped.';

            return;
        }
        if (!is_array($index)) {
            return;
        }
        $photos = array_flip(self::photoFiles($dump));
        foreach ($index as $served => $base) {
            if (!is_string($served) || !ImageStore::validName($served) || !isset($photos[$served])) {
                continue;
            }
            if (!is_string($base) || !self::originalNameOk($base)) {
                continue;
            }
            $data = $zip->getFromName('originals/' . $base);
            if ($data === false) {
                $warnings[] = "Original $base is missing from the archive.";
                continue;
            }
            try {
                if (!$this->originals->exists($base)) {
                    $this->originals->write($base, $data);
                }
                $this->originals->write('map/' . substr($served, 0, 32), $base);
            } catch (Throwable) {
                $warnings[] = 'Camera files could not be restored (originals directory is not writable).';

                return;
            }
        }
    }

    /**
     * The rows: one transaction, children deleted first, parents inserted
     * first, then every id sequence past the highest id restored.
     *
     * Lock first: on PostgreSQL the catalog tables are locked against other
     * writers before the first delete, so a write that started earlier
     * finishes first and is replaced, and one that starts later waits. On
     * SQLite the first statement is a write (the delete), which takes the
     * database's one write lock.
     *
     * @param array<string,mixed> $dump
     */
    private function restoreRows(array $dump): void
    {
        $tables = array_values(array_filter(Backups::tables(), fn (string $t): bool => $this->backups->tableExists($t)));
        $pg = $this->backups->driver() === 'pgsql';
        try {
            Writes::transaction($this->db, function () use ($tables, $dump, $pg): void {
                if ($pg) {
                    Writes::check($this->db->query('LOCK TABLE ' . implode(', ', $tables) . ' IN SHARE ROW EXCLUSIVE MODE'), 'lock tables');
                } else {
                    Writes::check($this->db->query('PRAGMA defer_foreign_keys = ON'), 'defer foreign keys');
                }
                foreach (array_reverse($tables) as $t) {
                    Writes::check($this->db->query("DELETE FROM $t"), 'delete ' . $t);
                }
                foreach ($tables as $t) {
                    $tb = $dump['tables'][$t] ?? null;
                    if (!is_array($tb) || !is_array($tb['rows'] ?? null) || $tb['rows'] === [] || !is_array($tb['columns'] ?? null)) {
                        continue;
                    }
                    /** @var list<string> $cols */
                    $cols = $tb['columns'];
                    $sql = "INSERT INTO $t (" . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
                    foreach ($tb['rows'] as $r) {
                        /** @var list<int|string|null> $binds */
                        $binds = array_map(static fn (mixed $v): int|float|string|null => is_bool($v) ? (int)$v : (is_int($v) || is_float($v) || is_string($v) ? $v : null), array_values((array)$r));
                        Writes::check($this->db->query($sql, $binds), 'insert ' . $t);
                    }
                }
                foreach ($tables as $t) {
                    if (!in_array('id', $this->backups->columns($t), true)) {
                        continue;
                    }
                    // Never hand out an id again: past the highest restored id and, for items,
                    // past every id in the history (the activity log outlives deleted items).
                    $max = "SELECT MAX(id) AS m FROM $t" . ($t === 'items' && in_array('activity', $tables, true) ? ' UNION ALL SELECT MAX(item_id) AS m FROM activity' : '');
                    $next = (int)(Writes::rows($this->db, "SELECT COALESCE(MAX(m), 0) AS n FROM ($max) x")[0]['n'] ?? 0);
                    if ($pg) {
                        // identity columns do not advance on explicit ids
                        Writes::rows($this->db, "SELECT setval(pg_get_serial_sequence('$t', 'id'), " . ($next + 1) . ', false) AS v');
                    } else {
                        Writes::check($this->db->query('DELETE FROM sqlite_sequence WHERE name = ?', [$t]), 'sequence');
                        Writes::check($this->db->query('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$t, $next]), 'sequence');
                    }
                }
            });
        } catch (WriteFailed) {
            throw new UserError('The backup could not be restored. Nothing was changed.');
        }
    }
}
