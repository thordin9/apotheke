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
use PgSql\Connection as PgConnection;
use SQLite3;
use JsonException;
use LengthException;
use Throwable;
use ZipArchive;

/**
 * Checking and restoring a backup zip (legacy backup_open() and
 * backup_restore()). Restore replaces all catalog data in one transaction;
 * the files go first, under their own random names, so nothing that exists
 * is overwritten, and files no longer named are kept.
 *
 * Streamed (L-441, L-451, N-442): backup.json is copied out of the zip and
 * read a value at a time (JsonReader), once to check it and once for the
 * rows, which go in through prepared statements; every other entry is
 * copied to a temp file, checked against its size and CRC, and written
 * through Storage from there. So memory stays about the same for any size
 * of backup; only one value larger than the memory left is refused
 * (TOO_LARGE). Each entry may be at most MAX_ENTRY bytes.
 *
 * Stricter than legacy (L-440): a restore that fails at any point leaves
 * the files as they were. Every file it creates is noted and removed again
 * on failure, with any folder it had to create (N-453), and the
 * camera-original links (originals/map/) are rewritten only after the rows
 * commit. A listed entry that cannot be read fails the restore. One restore
 * runs at a time: the whole restore holds an exclusive lock on
 * DATA_DIR/tmp/restore.lock, and a second one is refused at once with BUSY
 * (L-450). On PostgreSQL a restore that loses a deadlock to another write
 * is tried again, and then refused with CONFLICT (N-445).
 *
 * Every entry name must be one the backup writes, so no entry can name a
 * path outside its folder. Files are written through Storage (no symlinked
 * path, written whole or not at all). A missing thumbnail is drawn again
 * only within Media's decode limits. The backup's settings are restored only
 * for a user who may change settings (N-443, the command line may).
 *
 * @phpstan-type Opened array{zip:ZipArchive,tables:array<string,int>,photos:int,created_at:string,driver:string,warnings:list<string>}
 * @phpstan-type Inspected array{zip:ZipArchive,json:string,tables:array<string,int>,photos:int,created_at:string,driver:string,warnings:list<string>,photoFiles:list<string>,attachmentFiles:list<string>,settings:mixed,offsets:array<string,int>}
 */
final class Restorer
{
    /** Zip-bomb guard: the sizes the entries declare, unpacked. */
    public const MAX_UNPACKED = 4 * 1024 * 1024 * 1024;

    public const MAX_ENTRIES = 100_000;

    /** One file in the zip, unpacked (N-442): the largest photo or attachment the upload limits allow. */
    public const MAX_ENTRY = 200 * 1024 * 1024;

    public const FAILED = 'The backup could not be restored. Nothing was changed.';

    public const BUSY = 'Another restore is running. Try again when it has finished.';

    /** N-459: why a lock file that is a link (or not a plain file) stops the restore. */
    public const LOCK_REFUSED = 'The restore lock DATA_DIR/tmp/restore.lock is a link or not a plain file. Remove it, then try again. Nothing was changed.';

    /** N-445: the restore lost a deadlock to another write, also when tried again. */
    public const CONFLICT = 'Another change was being saved at the same time, so the restore was stopped. Nothing was changed. Try again.';

    /** In DATA_DIR/tmp; held for the whole restore (L-450). */
    public const LOCK_FILE = 'restore.lock';

    public const TOO_LARGE = 'This backup is too large to restore within the memory limit. Nothing was changed.';

    /** The columns that name a photo or an attachment file (legacy backup_photo_files(), backup_attachment_files()). */
    public const FILE_COLUMNS = ['items' => 'image', 'item_photos' => 'file', 'item_attachments' => 'file'];

    /** Rows tried in all, when a restore loses a deadlock on PostgreSQL (N-445). */
    private const ATTEMPTS = 3;

    /** WriteFailed's code for a deadlock or a serialization failure. */
    private const CONFLICT_CODE = 409;

    /** Kept free beside the estimate: the restore's own work after decoding. */
    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    /** Bytes per read or write of a stream. */
    private const CHUNK = 65536;

    /** backup.json's depth, as legacy decodes it. */
    private const DEPTH = 64;

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

    /** The file a row's value in $table's FILE_COLUMNS column names, if it is a valid name. */
    public static function fileName(string $table, mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $ok = $table === 'item_attachments' ? Attachments::validName($value) : ImageStore::validName($value);

        return $ok ? $value : null;
    }

    /**
     * Open and check a backup zip without touching the database (legacy
     * backup_open()). The caller closes the zip.
     *
     * @return Opened
     */
    public function open(string $zipPath): array
    {
        $b = $this->inspect($zipPath);
        @unlink($b['json']);

        return ['zip' => $b['zip'], 'tables' => $b['tables'], 'photos' => $b['photos'], 'created_at' => $b['created_at'], 'driver' => $b['driver'], 'warnings' => $b['warnings']];
    }

    /**
     * Replace all catalog data with the backup's (legacy backup_restore()).
     * $settings: may the backup's settings be restored too (the user may
     * change settings, or the command line)?
     *
     * @return array{tables:array<string,int>,photos:int,warnings:list<string>,token_cleared:bool}
     */
    public function restore(string $zipPath, bool $settings): array
    {
        // L-450: one restore at a time, files and rows alike (web and CLI); a second one is refused at once.
        $lock = $this->lock();
        try {
            return $this->restoreLocked($zipPath, $settings);
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
        $path = $this->backups->tmpDir() . '/' . self::LOCK_FILE;
        // N-456, N-459: never through a link or anything but a plain file, and say why.
        $named = @lstat($path);
        if ($named !== false && !self::plainFile($named)) {
            throw new UserError(self::LOCK_REFUSED);
        }
        // N-458: 'x' creates the file and never follows a link; a file that is there is opened read-only,
        // which creates nothing (L-455: flock() works on a read handle, whoever made the file).
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            $fh = @fopen($path, 'r');
        }
        if ($fh === false) {
            throw new UserError(self::FAILED);
        }
        // N-458: what was opened must be the plain file at $path, not a link swapped in meanwhile.
        clearstatcache(true, $path);
        if (!self::sameFile(fstat($fh), @lstat($path))) {
            fclose($fh);
            throw new UserError(self::LOCK_REFUSED);
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new UserError(self::BUSY, 409);
        }

        return $fh;
    }

    /** @param array<int|string,int> $stat */
    public static function plainFile(array $stat): bool
    {
        return (($stat['mode'] ?? 0) & 0170000) === 0100000;
    }

    /**
     * Is the open file ($open, fstat()) the plain file the name points at ($named, lstat())?
     *
     * @param array<int|string,int>|false $open
     * @param array<int|string,int>|false $named
     */
    public static function sameFile(array|false $open, array|false $named): bool
    {
        return $open !== false && $named !== false && self::plainFile($named)
            && ($open['dev'] ?? null) === ($named['dev'] ?? -1) && ($open['ino'] ?? null) === ($named['ino'] ?? -1);
    }

    /**
     * @return array{tables:array<string,int>,photos:int,warnings:list<string>,token_cleared:bool}
     */
    private function restoreLocked(string $zipPath, bool $settings): array
    {
        $b = $this->inspect($zipPath);
        $zip = $b['zip'];
        $warnings = $b['warnings'];
        $tmp = $this->backups->tmpDir();
        // Files this restore creates, removed again if it fails; the original links it would write.
        $created = [];
        $links = [];
        $folders = $this->missingFolders();
        try {
            try {
                $written = $this->restorePhotos($zip, $b['photoFiles'], $tmp, $warnings, $created);
                $this->restoreAttachments($zip, $b['attachmentFiles'], $tmp, $warnings, $created);
                $this->restoreOriginals($zip, $b['photoFiles'], $tmp, $warnings, $created, $links);
            } finally {
                $zip->close();
            }
            $this->restoreRows($b['json'], $b['offsets']);
        } catch (Throwable $e) {
            $this->discard($created);
            self::removeEmpty($folders);
            throw $e instanceof UserError ? $e : new UserError(self::FAILED, 0, $e);
        } finally {
            @unlink($b['json']);
        }
        $this->linkOriginals($links, $warnings);
        $cleared = false;
        if (is_array($b['settings']) && !$settings) {
            $warnings[] = 'The settings were not restored: that needs the permission to change settings.';
        } elseif (is_array($b['settings'])) {
            $r = $this->settings->import((string)json_encode(['format' => 'apotheke-settings', 'settings' => $b['settings']]), null);
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

    /**
     * Open the zip, check its entries, copy backup.json out and check it
     * (legacy backup_open()). The caller closes the zip and removes the
     * copy ('json').
     *
     * @return Inspected
     */
    private function inspect(string $zipPath): array
    {
        $zip = new ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new UserError('That file is not a zip archive.');
        }
        $json = null;
        try {
            $this->checkEntries($zip);
            $json = $this->extract($zip, 'backup.json', $this->backups->tmpDir(), 'This is not an Apotheke backup (backup.json is missing).');
            if ($json === null) {
                throw new UserError('This is not an Apotheke backup (backup.json is missing).');
            }
            $b = $this->scan($json);
            $warnings = [];
            $photos = 0;
            foreach ($b['photoFiles'] as $f) {
                if ($zip->locateName('images/' . $f) === false) {
                    $warnings[] = "Photo $f is missing from the archive.";
                    continue;
                }
                $photos++;
            }
            foreach ($b['attachmentFiles'] as $f) {
                if ($zip->locateName('files/' . $f) === false) {
                    $warnings[] = "Attachment $f is missing from the archive.";
                }
            }

            return ['zip' => $zip, 'json' => $json, 'photos' => $photos, 'warnings' => $warnings] + $b;
        } catch (Throwable $e) {
            $zip->close();
            if ($json !== null) {
                @unlink($json);
            }
            throw $e;
        }
    }

    /** Names, count and sizes, from the zip's directory alone. */
    private function checkEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new UserError('The backup has too many files.');
        }
        $total = 0;
        $largest = null;
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            $size = (int)($s['size'] ?? 0);
            $total += $size;
            $name = (string)($s['name'] ?? '');
            if (!self::entryNameOk($name)) {
                throw new UserError('Unexpected file in backup: ' . mb_substr($name, 0, 80));
            }
            // Stricter than legacy: one entry per name, so the check and the restore read the same bytes.
            if (isset($seen[$name])) {
                throw new UserError('Unexpected file in backup: ' . mb_substr($name, 0, 80));
            }
            $seen[$name] = true;
            if ($size > self::MAX_ENTRY && $name !== 'backup.json') {
                $largest ??= $name;
            }
        }
        if ($total > self::MAX_UNPACKED) {
            throw new UserError('The backup is too large to unpack.');
        }
        if ($largest !== null) {
            throw new UserError('A file in the backup is too large to restore: ' . mb_substr($largest, 0, 80));
        }
        if ($zip->statName('backup.json') === false) {
            throw new UserError('This is not an Apotheke backup (backup.json is missing).');
        }
    }

    /**
     * Copy entry $name to a new temp file in $tmp and return its path, or
     * null when the zip has no such entry. One that is damaged (its size or
     * CRC is not what the zip says) fails with $damaged; one that cannot be
     * written (a full disk) with FAILED. Nothing is left behind on failure.
     */
    private function extract(ZipArchive $zip, string $name, string $tmp, string $damaged = self::FAILED): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        $size = (int)$stat['size'];
        $in = @$zip->getStream($name);
        if ($in === false) {
            throw new UserError($damaged);
        }
        $path = $tmp . '/restore-' . bin2hex(random_bytes(8)) . '.part';
        $out = @fopen($path, 'xb');
        if ($out === false) {
            fclose($in);
            throw new UserError(self::FAILED);
        }
        $error = null;
        try {
            $crc = hash_init('crc32b');
            $n = 0;
            while ($n <= $size && is_string($chunk = @fread($in, self::CHUNK)) && $chunk !== '') {
                $n += strlen($chunk);
                hash_update($crc, $chunk);
                if (@fwrite($out, $chunk) !== strlen($chunk)) {
                    $error = self::FAILED;
                    break;
                }
            }
            if ($error === null && ($n !== $size || hexdec(hash_final($crc)) !== (int)$stat['crc'])) {
                $error = $damaged;
            }
        } finally {
            fclose($in);
            if (!@fclose($out)) {
                $error ??= self::FAILED;
            }
            if ($error !== null) {
                @unlink($path);
            }
        }
        if ($error !== null) {
            throw new UserError($error);
        }

        return $path;
    }

    /** May $bytes more be taken from PHP's memory? (L-451) */
    private static function guard(): Closure
    {
        return static fn (int $bytes): bool => self::fits($bytes, self::headroom());
    }

    /**
     * Check backup.json (copied out to $path) a value at a time, in
     * legacy's order: valid JSON, format, version, tables and schema, the
     * schema, each table (name, shape, columns, rows), the core tables.
     * Stricter than legacy: a key twice in the backup's own objects, a
     * table's rows before its columns, and true or false as a value (N-448)
     * are refused.
     *
     * @return array{tables:array<string,int>,created_at:string,driver:string,photoFiles:list<string>,attachmentFiles:list<string>,settings:mixed,offsets:array<string,int>}
     */
    private function scan(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new UserError(self::FAILED);
        }
        $top = [];
        $scan = ['counts' => [], 'offsets' => [], 'files' => [], 'error' => null, 'twice' => false];
        $tables = false;
        try {
            $r = new JsonReader($fh, self::DEPTH, self::CHUNK, self::guard());
            if ($r->kind() !== '{') {
                $r->skip();
            } else {
                foreach ($r->entries() as $key) {
                    if (array_key_exists($key, $top) || ($key === 'tables' && $tables)) {
                        $scan['twice'] = true;
                    }
                    if ($key === 'tables' && in_array($r->kind(), ['{', '['], true)) {
                        $tables = true;
                        $this->scanTables($r, $scan);
                    } elseif (in_array($key, ['format', 'version', 'created_at', 'driver', 'schema', 'tables', 'settings'], true)) {
                        $top[$key] = $r->value();
                    } else {
                        $top[$key] = null;
                    }
                }
            }
            $r->finish();
        } catch (JsonException) {
            throw new UserError('backup.json is not valid JSON.');
        } catch (LengthException) {
            throw new UserError(self::TOO_LARGE);
        } finally {
            fclose($fh);
        }
        if ($scan['twice']) {
            throw new UserError('backup.json names the same key twice.');
        }
        if (($top['format'] ?? null) !== Backups::FORMAT) {
            throw new UserError('This is not an Apotheke backup.');
        }
        if (!is_int($top['version'] ?? null) || $top['version'] > Backups::VERSION) {
            throw new UserError('This backup was made by a newer version of Apotheke.');
        }
        if (!($tables || is_array($top['tables'] ?? null)) || !is_array($top['schema'] ?? null)) {
            throw new UserError('The backup is incomplete.');
        }
        $unknown = array_diff(array_map(static fn (mixed $v): string => is_scalar($v) ? (string)$v : 'Array', $top['schema']), ($this->known)());
        if ($unknown !== []) {
            throw new UserError('This backup needs a newer version of Apotheke (schema ' . implode(', ', $unknown) . ').');
        }
        if ($scan['error'] !== null) {
            throw new UserError($scan['error']);
        }
        foreach (['locations', 'categories', 'items'] as $t) {
            if (!isset($scan['counts'][$t])) {
                throw new UserError("The backup has no $t table.");
            }
        }
        $photos = $scan['files']['photo'] ?? [];
        $attachments = $scan['files']['attachment'] ?? [];
        ksort($photos, SORT_STRING);
        ksort($attachments, SORT_STRING);

        return ['tables' => $scan['counts'], 'offsets' => $scan['offsets'],
            'photoFiles' => array_map('strval', array_keys($photos)), 'attachmentFiles' => array_map('strval', array_keys($attachments)),
            'settings' => $top['settings'] ?? null,
            'created_at' => is_scalar($top['created_at'] ?? null) ? (string)$top['created_at'] : '', 'driver' => is_scalar($top['driver'] ?? null) ? (string)$top['driver'] : '?'];
    }

    /**
     * Each table of backup.json's "tables": its rows counted and checked,
     * the file names they refer to collected, where its value starts noted
     * (for the rows pass). The first problem is kept in $scan['error'].
     *
     * @param array{counts:array<string,int>,offsets:array<string,int>,files:array<string,array<string,true>>,error:?string,twice:bool} $scan
     */
    private function scanTables(JsonReader $r, array &$scan): void
    {
        $allowed = Backups::tables();
        foreach ($r->entries() as $t) {
            if (isset($scan['counts'][$t]) || isset($scan['offsets'][$t])) {
                $scan['twice'] = true;
            }
            if (!in_array($t, $allowed, true)) {
                $scan['error'] ??= 'Unknown table in backup: ' . mb_substr((string)$t, 0, 40);
                $scan['offsets'][(string)$t] = -1;
                $r->skip();
                continue;
            }
            /** @var string $t */
            $scan['offsets'][$t] = $r->offset();
            $error = $this->scanTable($r, $t, $scan);
            $scan['error'] ??= $error;
        }
    }

    /**
     * @param array{counts:array<string,int>,offsets:array<string,int>,files:array<string,array<string,true>>,error:?string,twice:bool} $scan
     * @return ?string the table's first problem
     */
    private function scanTable(JsonReader $r, string $t, array &$scan): ?string
    {
        $malformed = "Table $t is malformed.";
        if (!in_array($r->kind(), ['{', '['], true)) {
            $r->skip();

            return $malformed;
        }
        $columns = null;
        $error = null;
        $rows = null;
        $badShape = false;
        $seen = [];
        $file = isset(self::FILE_COLUMNS[$t]) ? ($t === 'item_attachments' ? 'attachment' : 'photo') : null;
        foreach ($r->entries() as $k) {
            if (isset($seen[$k])) {
                $scan['twice'] = true;
            }
            $seen[$k] = true;
            if ($k === 'columns') {
                $columns = $r->value();
                if (!is_array($columns)) {
                    continue;
                }
                $have = $this->backups->columns($t);
                foreach ($columns as $c) {
                    if (!is_string($c) || !in_array($c, $have, true)) {
                        $error ??= "Unknown column $t." . mb_substr(is_scalar($c) ? (string)$c : 'Array', 0, 40) . ' in backup.';
                        break;
                    }
                }
                continue;
            }
            if ($k !== 'rows') {
                continue;
            }
            // Stricter than legacy: the columns come first, as backups write them.
            if (!in_array($r->kind(), ['{', '['], true) || !is_array($columns)) {
                $r->skip();
                $badShape = true;
                continue;
            }
            $n = count($columns);
            $at = $file === null ? false : array_search(self::FILE_COLUMNS[$t], $columns, true);
            $rows = 0;
            foreach ($r->entries() as $_) {
                $row = $r->value();
                $rows++;
                if (!is_array($row) || count($row) !== $n) {
                    $error ??= "A row in $t has the wrong number of values.";
                    continue;
                }
                foreach ($row as $v) {
                    if (is_bool($v) || (!is_scalar($v) && $v !== null)) {
                        $error ??= "A value in $t is not a plain value.";
                        break;
                    }
                }
                if ($at !== false && ($name = self::fileName($t, $row[$at] ?? null)) !== null) {
                    $scan['files'][(string)$file][$name] = true;
                }
            }
        }
        if ($badShape || !is_array($columns) || $rows === null) {
            return $malformed;
        }
        $scan['counts'][$t] = $rows;

        return $error;
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
     * Write a file that is not there yet from the temp file $path, and note
     * it, so a failed restore can remove it again.
     *
     * @param list<array{Storage,string}> $created
     */
    private static function createFrom(Storage $store, string $key, string $path, array &$created): void
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new UserError(self::FAILED);
        }
        try {
            $store->writeStream($key, $fh);
        } finally {
            fclose($fh);
        }
        $created[] = [$store, $key];
    }

    /** @param list<array{Storage,string}> $created */
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
     * N-453: the local folders the files go into that are not there yet; a
     * failed restore removes them again when they are empty.
     *
     * @return list<string>
     */
    private function missingFolders(): array
    {
        $data = rtrim($this->backups->dataDir(), '/');

        return array_values(array_filter([$data . '/images', $data . '/thumbs', $data . '/files', rtrim($this->backups->originalsDir(), '/')],
            static fn (string $d): bool => !is_dir($d)));
    }

    /** @param list<string> $folders */
    private static function removeEmpty(array $folders): void
    {
        foreach ($folders as $d) {
            if (is_dir($d) && !is_link($d)) {
                @rmdir($d);   // only when empty
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
     * Served photos and thumbnails: each one must be a JPEG; one that exists
     * is kept. A missing thumbnail is drawn from the photo.
     *
     * @param list<string> $files
     * @param list<string> $warnings
     * @param list<array{Storage,string}> $created
     */
    private function restorePhotos(ZipArchive $zip, array $files, string $tmp, array &$warnings, array &$created): int
    {
        $written = 0;
        foreach ($files as $f) {
            $photo = $this->extract($zip, 'images/' . $f, $tmp);
            if ($photo === null) {
                continue;
            }
            try {
                $info = @getimagesize($photo);
                if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
                    $warnings[] = "Photo $f is not a JPEG and was skipped.";
                    continue;
                }
                if (!$this->data->exists('images/' . $f)) {
                    self::createFrom($this->data, 'images/' . $f, $photo, $created);
                }
                if (!$this->data->exists('thumbs/' . $f)) {
                    $thumb = $this->extract($zip, 'thumbs/' . $f, $tmp);
                    try {
                        $ti = $thumb === null ? false : @getimagesize($thumb);
                        if (is_string($thumb) && $ti !== false && $ti[2] === IMAGETYPE_JPEG) {
                            self::createFrom($this->data, 'thumbs/' . $f, $thumb, $created);
                        } elseif (!self::overDecodeLimits((int)$info[0], (int)$info[1]) && ($im = @imagecreatefromjpeg($photo)) !== false) {
                            self::create($this->data, 'thumbs/' . $f, $this->thumbJpeg($im), $created);
                        }
                    } finally {
                        if ($thumb !== null) {
                            @unlink($thumb);
                        }
                    }
                }
                $written++;
            } finally {
                @unlink($photo);
            }
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
     * @param list<string> $files
     * @param list<string> $warnings
     * @param list<array{Storage,string}> $created
     */
    private function restoreAttachments(ZipArchive $zip, array $files, string $tmp, array &$warnings, array &$created): void
    {
        foreach ($files as $f) {
            $path = $this->extract($zip, 'files/' . $f, $tmp);
            if ($path === null) {
                continue;
            }
            try {
                if ($this->data->exists('files/' . $f)) {
                    continue;
                }
                if (Attachments::sniff((string)@file_get_contents($path, false, null, 0, 16)) !== pathinfo($f, PATHINFO_EXTENSION)) {
                    $warnings[] = "Attachment $f does not match its type and was skipped.";
                    continue;
                }
                self::createFrom($this->data, 'files/' . $f, $path, $created);
            } finally {
                @unlink($path);
            }
        }
    }

    /**
     * Camera files back next to their served photos (legacy
     * backup_restore_originals()). Bytes are written only when missing; the
     * map entry that points at the file from this zip goes into $links, for
     * after the rows commit. originals/index.json is read a value at a
     * time; when it is not valid JSON nothing of it is used.
     *
     * @param list<string> $photoFiles
     * @param list<string> $warnings
     * @param list<array{Storage,string}> $created
     * @param array<string,string> $links
     */
    private function restoreOriginals(ZipArchive $zip, array $photoFiles, string $tmp, array &$warnings, array &$created, array &$links): void
    {
        $path = $this->extract($zip, 'originals/index.json', $tmp);
        if ($path === null) {
            return;
        }
        $photos = array_flip($photoFiles);
        $pairs = [];
        try {
            if ((int)@filesize($path) === 0) {
                return;
            }
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                throw new UserError(self::FAILED);
            }
            try {
                $r = new JsonReader($fh, 8, self::CHUNK, self::guard());
                if (in_array($r->kind(), ['{', '['], true)) {
                    foreach ($r->entries() as $served) {
                        $base = $r->value();
                        if (is_string($served) && ImageStore::validName($served) && isset($photos[$served]) && is_string($base) && self::originalNameOk($base)) {
                            $pairs[$served] = $base;
                        } else {
                            unset($pairs[$served]);   // the last value of a key counts, as json_decode() keeps it
                        }
                    }
                } else {
                    $r->skip();
                }
                $r->finish();
            } catch (JsonException|LengthException) {
                $warnings[] = 'originals/index.json was skipped.';

                return;
            } finally {
                fclose($fh);
            }
        } finally {
            @unlink($path);
        }
        foreach ($pairs as $served => $base) {
            $data = $this->extract($zip, 'originals/' . $base, $tmp);
            if ($data === null) {
                $warnings[] = "Original $base is missing from the archive.";
                continue;
            }
            try {
                if (!$this->originals->exists($base)) {
                    self::createFrom($this->originals, $base, $data, $created);
                }
                $links['map/' . substr((string)$served, 0, 32)] = $base;
            } catch (Throwable) {
                $warnings[] = 'Camera files could not be restored (originals directory is not writable).';

                return;
            } finally {
                @unlink($data);
            }
        }
    }

    /**
     * The rows: one transaction, children deleted first, parents inserted
     * first, then every id sequence past the highest id restored. Each
     * table's rows are read from backup.json ($json, where $offsets says
     * the table starts) and inserted one by one through a prepared
     * statement, so no value is copied into SQL text (L-451).
     *
     * Lock first: on PostgreSQL the catalog tables are locked against other
     * writers before the first delete, so a write that started earlier
     * finishes first and is replaced, and one that starts later waits. On
     * SQLite the first statement is a write (the delete), which takes the
     * database's one write lock. A PostgreSQL restore that loses a deadlock
     * (a write that holds one catalog table and then wants another) is
     * tried again, and after ATTEMPTS refused with CONFLICT (N-445).
     *
     * @param array<string,int> $offsets
     */
    private function restoreRows(string $json, array $offsets): void
    {
        $tables = array_values(array_filter(Backups::tables(), fn (string $t): bool => $this->backups->tableExists($t)));
        $pg = $this->backups->driver() === 'pgsql';
        $fh = @fopen($json, 'rb');
        if ($fh === false) {
            throw new UserError(self::FAILED);
        }
        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    Writes::transaction($this->db, function () use ($tables, $offsets, $pg, $fh): void {
                        if ($pg) {
                            self::pg($this->pgConnection(), 'LOCK TABLE ' . implode(', ', $tables) . ' IN SHARE ROW EXCLUSIVE MODE', null, 'lock tables');
                        } else {
                            Writes::check($this->db->query('PRAGMA defer_foreign_keys = ON'), 'defer foreign keys');
                        }
                        foreach (array_reverse($tables) as $t) {
                            Writes::check($this->db->query("DELETE FROM $t"), 'delete ' . $t);
                        }
                        foreach ($tables as $t) {
                            if (($offsets[$t] ?? -1) >= 0) {
                                $this->insertRows($fh, $t, $offsets[$t], $pg);
                            }
                        }
                        $this->advanceSequences($tables, $pg);
                    });

                    return;
                } catch (WriteFailed $e) {
                    if ($e->getCode() !== self::CONFLICT_CODE) {
                        throw new UserError(self::FAILED, 0, $e);
                    }
                    if ($attempt >= self::ATTEMPTS) {
                        throw new UserError(self::CONFLICT, 409, $e);
                    }
                    usleep(random_int(50_000, 250_000));
                } catch (LengthException) {
                    throw new UserError(self::TOO_LARGE);
                }
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * One table's rows, from where its value starts in backup.json.
     *
     * @param resource $fh
     */
    private function insertRows($fh, string $t, int $offset, bool $pg): void
    {
        if (fseek($fh, $offset) !== 0) {
            throw new WriteFailed('Database write failed: read ' . $t);
        }
        // Inside the document and its "tables": two levels of legacy's depth are taken.
        $r = new JsonReader($fh, self::DEPTH - 2, self::CHUNK, self::guard(), $offset);
        $cols = null;
        foreach ($r->entries() as $k) {
            if ($k === 'columns') {
                $cols = $r->value();
                continue;
            }
            if ($k !== 'rows' || !is_array($cols)) {
                continue;
            }
            /** @var list<string> $cols */
            $sql = "INSERT INTO $t (" . implode(', ', $cols) . ') VALUES (' . implode(',', $pg ? array_map(static fn (int $i): string => '$' . $i, range(1, max(1, count($cols)))) : array_fill(0, max(1, count($cols)), '?')) . ')';
            $insert = null;
            foreach ($r->entries() as $_) {
                $row = $r->value();
                /** @var list<int|float|string|null> $binds */
                $binds = array_values((array)$row);
                $bytes = array_sum(array_map(static fn (mixed $v): int => is_string($v) ? strlen($v) : 8, $binds));
                if (!self::fits($bytes, self::headroom())) {   // the driver copies each value once
                    throw new LengthException('A row is too large for the memory left.');
                }
                $insert ??= $pg ? $this->pgInsert($sql, $t) : $this->sqliteInsert($sql, $t);
                $insert($binds);
            }
        }
    }

    /** @return Closure(list<int|float|string|null>):void */
    private function pgInsert(string $sql, string $t): Closure
    {
        $conn = $this->pgConnection();
        self::pgResult($conn, @pg_send_prepare($conn, '', $sql), 'prepare ' . $t);

        return static function (array $binds) use ($conn, $t): void {
            self::pgResult($conn, @pg_send_execute($conn, '', array_map(static fn (mixed $v): ?string => $v === null ? null : (string)$v, $binds)), 'insert ' . $t);
        };
    }

    /** @return Closure(list<int|float|string|null>):void */
    private function sqliteInsert(string $sql, string $t): Closure
    {
        $conn = $this->db->connID;
        if (!$conn instanceof SQLite3) {
            throw new WriteFailed('Database write failed: ' . $t);
        }
        try {
            $stmt = @$conn->prepare($sql);
        } catch (Throwable) {
            $stmt = false;
        }
        if ($stmt === false) {
            throw new WriteFailed('Database write failed: prepare ' . $t);
        }

        return static function (array $binds) use ($stmt, $t): void {
            try {
                $stmt->reset();
                $stmt->clear();
                foreach ($binds as $i => $v) {
                    $stmt->bindValue($i + 1, $v, match (true) {
                        $v === null => SQLITE3_NULL,
                        is_int($v) => SQLITE3_INTEGER,
                        is_float($v) => SQLITE3_FLOAT,
                        default => SQLITE3_TEXT,
                    });
                }
                $ok = @$stmt->execute();
            } catch (Throwable) {
                $ok = false;
            }
            if ($ok === false) {
                throw new WriteFailed('Database write failed: insert ' . $t);
            }
            $ok->finalize();
        };
    }

    private function pgConnection(): PgConnection
    {
        $conn = $this->db->connID;
        if (!$conn instanceof PgConnection) {
            throw new WriteFailed('Database write failed: connection');
        }

        return $conn;
    }

    /**
     * One statement on PostgreSQL.
     *
     * @param list<string|null>|null $params
     */
    private static function pg(PgConnection $conn, string $sql, ?array $params, string $step): void
    {
        self::pgResult($conn, $params === null ? @pg_send_query($conn, $sql) : @pg_send_query_params($conn, $sql, $params), $step);
    }

    /** The results of what was sent; an error throws WriteFailed, with CONFLICT_CODE for a deadlock or a serialization failure. */
    private static function pgResult(PgConnection $conn, bool $sent, string $step): void
    {
        if (!$sent) {
            throw new WriteFailed('Database write failed: ' . $step);
        }
        $state = null;
        while (($res = pg_get_result($conn)) !== false) {
            if (pg_result_status($res) === PGSQL_FATAL_ERROR) {
                $state ??= (string)pg_result_error_field($res, PGSQL_DIAG_SQLSTATE);
            }
        }
        if ($state !== null) {
            throw new WriteFailed('Database write failed: ' . $step, in_array($state, ['40P01', '40001'], true) ? self::CONFLICT_CODE : 0);
        }
    }

    /**
     * Never hand out an id again: past the highest restored id and, for
     * items, past every id in the history (the activity log outlives
     * deleted items).
     *
     * @param list<string> $tables
     */
    private function advanceSequences(array $tables, bool $pg): void
    {
        foreach ($tables as $t) {
            if (!in_array('id', $this->backups->columns($t), true)) {
                continue;
            }
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
    }
}
