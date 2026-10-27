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
 * Stricter than legacy (L-440): a restore that fails at any point leaves
 * the files as they were. Every file it creates is noted and removed again
 * on failure, and the camera-original links (originals/map/) are rewritten
 * only after the rows commit. A listed entry that cannot be read fails the
 * restore. Before backup.json is read and decoded, its size is checked
 * against the memory left (L-441). One restore runs at a time: the whole
 * restore holds an exclusive lock on DATA_DIR/tmp/restore.lock, and a
 * second one is refused at once with BUSY (L-450).
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

    public const FAILED = 'The backup could not be restored. Nothing was changed.';

    public const BUSY = 'Another restore is running. Try again when it has finished.';

    /** In DATA_DIR/tmp; held for the whole restore (L-450). */
    public const LOCK_FILE = 'restore.lock';

    public const TOO_LARGE = 'This backup is too large to restore within the memory limit. Nothing was changed.';

    /** Kept free beside the estimate: the restore's own work after decoding. */
    private const MEMORY_MARGIN = 4 * 1024 * 1024;

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
        // L-450: one restore at a time, files and rows alike (web and CLI); a second one is refused at once.
        $lock = $this->lock();
        try {
            return $this->restoreLocked($zipPath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * DATA_DIR/tmp/restore.lock, held exclusively. Not waited for: while
     * another restore holds it, the answer is BUSY (409).
     *
     * @return resource
     */
    private function lock()
    {
        $fh = @fopen($this->backups->tmpDir() . '/' . self::LOCK_FILE, 'c');
        if ($fh === false) {
            throw new UserError(self::FAILED);
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new UserError(self::BUSY, 409);
        }

        return $fh;
    }

    /**
     * @return array{tables:array<string,int>,photos:int,warnings:list<string>,token_cleared:bool}
     */
    private function restoreLocked(string $zipPath): array
    {
        $b = $this->open($zipPath);
        $dump = $b['dump'];
        $zip = $b['zip'];
        $warnings = $b['warnings'];
        // Files this restore creates, removed again if it fails; the original links it would write.
        $created = [];
        $links = [];
        try {
            try {
                $written = $this->restorePhotos($zip, $dump, $warnings, $created);
                $this->restoreAttachments($zip, $dump, $warnings, $created);
                $this->restoreOriginals($zip, $dump, $warnings, $created, $links);
            } finally {
                $zip->close();
            }
            $this->restoreRows($dump);
        } catch (Throwable $e) {
            $this->discard($created);
            throw $e instanceof UserError ? $e : new UserError(self::FAILED, 0, $e);
        }
        $this->linkOriginals($links, $warnings);
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
        $stat = $zip->statName('backup.json');
        if ($stat === false) {
            throw new UserError('This is not an Apotheke backup (backup.json is missing).');
        }
        // The text, and decoded at least as much again (L-441).
        if (!self::fits(2 * (int)$stat['size'], self::headroom())) {
            throw new UserError(self::TOO_LARGE);
        }
        $json = $zip->getFromName('backup.json');
        if ($json === false) {
            throw new UserError('This is not an Apotheke backup (backup.json is missing).');
        }
        if (!self::fits(self::decodedSize($json), self::headroom())) {
            throw new UserError(self::TOO_LARGE);
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
     * About what json_decode() of $json takes, on the high side: the text's
     * bytes, plus a value's zval and string header (48) for every comma, a
     * list's table (200) for every bracket and a map's (450) for every
     * brace. Measured on PHP 8.4, from rows of one integer to long notes.
     */
    public static function decodedSize(string $json): int
    {
        return strlen($json) + 48 * substr_count($json, ',') + 200 * substr_count($json, '[') + 450 * substr_count($json, '{');
    }

    /** The bytes PHP may still allocate, or null without a memory limit. */
    public static function headroom(): ?int
    {
        $limit = ini_parse_quantity((string)ini_get('memory_limit'));

        return $limit <= 0 ? null : $limit - memory_get_usage();
    }

    /** Does $need, plus the margin, fit in $headroom (null: no limit)? */
    public static function fits(int $need, ?int $headroom): bool
    {
        return $headroom === null || $need + self::MEMORY_MARGIN <= $headroom;
    }

    /**
     * An entry's bytes, or null when the zip has no such entry. One that is
     * there but cannot be read (damaged) fails the restore.
     */
    private static function entry(ZipArchive $zip, string $name): ?string
    {
        if ($zip->locateName($name) === false) {
            return null;
        }
        $data = @$zip->getFromName($name);   // a damaged entry is false, not a warning
        if ($data === false) {
            throw new UserError(self::FAILED);
        }

        return $data;
    }

    /**
     * Write a file that is not there yet and note it, so a failed restore
     * can remove it again.
     *
     * @param list<array{Storage,string}> $created
     */
    private static function create(Storage $store, string $key, string $bytes, array &$created): void
    {
        $store->write($key, $bytes);
        $created[] = [$store, $key];
    }

    /** @param list<array{Storage,string}> $created */
    private function discard(array $created): void
    {
        foreach (array_reverse($created) as [$store, $key]) {
            try {
                $store->delete($key);
            } catch (Throwable) {
                // Best effort: the name is random and no row refers to it.
            }
        }
    }

    /**
     * The camera-original links, once the rows are in (legacy writes them
     * with the bytes, before the rows).
     *
     * @param array<string,string> $links map key => original name
     * @param list<string> $warnings
     */
    private function linkOriginals(array $links, array &$warnings): void
    {
        try {
            foreach ($links as $key => $base) {
                $this->originals->write($key, $base);
            }
        } catch (Throwable) {
            $warnings[] = 'Camera files could not be restored (originals directory is not writable).';
        }
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
     * @param list<array{Storage,string}> $created
     */
    private function restorePhotos(ZipArchive $zip, array $dump, array &$warnings, array &$created): int
    {
        $written = 0;
        foreach (self::photoFiles($dump) as $f) {
            $data = self::entry($zip, 'images/' . $f);
            if ($data === null) {
                continue;
            }
            $info = @getimagesizefromstring($data);
            if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
                $warnings[] = "Photo $f is not a JPEG and was skipped.";
                continue;
            }
            if (!$this->data->exists('images/' . $f)) {
                self::create($this->data, 'images/' . $f, $data, $created);
            }
            if (!$this->data->exists('thumbs/' . $f)) {
                $thumb = self::entry($zip, 'thumbs/' . $f);
                $ti = $thumb === null ? false : @getimagesizefromstring($thumb);
                if (is_string($thumb) && $ti !== false && $ti[2] === IMAGETYPE_JPEG) {
                    self::create($this->data, 'thumbs/' . $f, $thumb, $created);
                } elseif (!self::overDecodeLimits((int)$info[0], (int)$info[1]) && ($im = @imagecreatefromstring($data)) !== false) {
                    self::create($this->data, 'thumbs/' . $f, $this->thumbJpeg($im), $created);
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
     * @param list<array{Storage,string}> $created
     */
    private function restoreAttachments(ZipArchive $zip, array $dump, array &$warnings, array &$created): void
    {
        foreach (self::attachmentFiles($dump) as $f) {
            $data = self::entry($zip, 'files/' . $f);
            if ($data === null || $this->data->exists('files/' . $f)) {
                continue;
            }
            if (Attachments::sniff(substr($data, 0, 16)) !== pathinfo($f, PATHINFO_EXTENSION)) {
                $warnings[] = "Attachment $f does not match its type and was skipped.";
                continue;
            }
            self::create($this->data, 'files/' . $f, $data, $created);
        }
    }

    /**
     * Camera files back next to their served photos (legacy
     * backup_restore_originals()). Bytes are written only when missing; the
     * map entry that points at the file from this zip goes into $links, for
     * after the rows commit.
     *
     * @param array<string,mixed> $dump
     * @param list<string> $warnings
     * @param list<array{Storage,string}> $created
     * @param array<string,string> $links
     */
    private function restoreOriginals(ZipArchive $zip, array $dump, array &$warnings, array &$created, array &$links): void
    {
        $raw = self::entry($zip, 'originals/index.json');
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
            $data = self::entry($zip, 'originals/' . $base);
            if ($data === null) {
                $warnings[] = "Original $base is missing from the archive.";
                continue;
            }
            try {
                if (!$this->originals->exists($base)) {
                    self::create($this->originals, $base, $data, $created);
                }
                $links['map/' . substr($served, 0, 32)] = $base;
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
                        Writes::check($this->db->query('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$t, (string)$next]), 'sequence');   // text, as legacy binds it
                    }
                }
            });
        } catch (WriteFailed) {
            throw new UserError(self::FAILED);
        }
    }
}
