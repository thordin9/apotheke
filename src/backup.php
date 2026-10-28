<?php
declare(strict_types=1);

/*
 * Apotheke – portable backup & restore.
 *
 * A backup is a zip with
 *   backup.json   {"format":"apotheke-backup","version":1,"created_at":…,"app":…,"driver":…,"schema":[migration versions],
 *                  "tables":{"<table>":{"columns":[…],"rows":[[…],…]}, …}}
 *   images/<hex>.jpg, thumbs/<hex>.jpg   every served photo (stripped JPEG)
 *   originals/index.json                 served name → original basename, when a camera file was kept
 *   originals/<64 hex>.<ext>             that camera file, byte-identical (not a sharing export)
 *   files/<hex>.<pdf|jpg|png|webp>       every attachment referenced by the data
 * The JSON dump is driver-neutral, so a backup taken on SQLite restores into PostgreSQL and vice versa.
 * Restore replaces ALL catalog data in one transaction; photo files are written first (random names, so
 * nothing existing is overwritten) and files no longer referenced are kept (harmless, never deleted).
 */

const BACKUP_FORMAT  = 'apotheke-backup';
const BACKUP_VERSION = 1;
const BACKUP_MAX_UNPACKED = 4 * 1024 * 1024 * 1024;   // zip-bomb guard: 4 GB unpacked
const BACKUP_MAX_ENTRIES  = 100_000;
const BACKUP_MAX_ENTRY    = 200 * 1024 * 1024;   // one file in the zip, unpacked (backup.json aside): the largest photo or attachment
const BACKUP_RESTORE_FAILED = 'The backup could not be restored. Nothing was changed.';
const BACKUP_RESTORE_BUSY   = 'Another restore is running. Try again when it has finished.';
const BACKUP_RESTORE_LOCK   = 'restore.lock';   // in DATA_DIR/tmp, held for the whole restore

/** Tables in foreign-key order (parents first). Restore deletes in reverse. */
function backup_tables(): array
{
    return ['locations', 'categories', 'tags', 'items', 'item_photos', 'item_tags', 'item_attachments', 'app_settings', 'activity', 'saved_filters', 'stocktakes', 'stocktake_lines'];
}

function backup_table_exists(PDO $pdo, string $driver, string $t): bool
{
    $sql = $driver === 'pgsql'
        ? 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?'
        : "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
}

/** @return string[] */
function backup_columns(PDO $pdo, string $driver, string $t): array
{
    if ($driver === 'pgsql') {
        $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position');
        $st->execute([$t]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_column($pdo->query('PRAGMA table_info(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
}

/** Plain-array dump of every catalog table. */
function backup_dump(PDO $pdo, string $driver): array
{
    $tables = [];
    foreach (backup_tables() as $t) {
        if (!backup_table_exists($pdo, $driver, $t)) continue;
        $cols = backup_columns($pdo, $driver, $t);
        $order = in_array('id', $cols, true) ? 'id' : implode(', ', array_slice($cols, 0, 2));
        $rows = $pdo->query('SELECT ' . implode(', ', $cols) . " FROM $t ORDER BY $order")->fetchAll(PDO::FETCH_NUM);
        $tables[$t] = ['columns' => $cols, 'rows' => $rows];
    }
    return [
        'format'     => BACKUP_FORMAT,
        'version'    => BACKUP_VERSION,
        'created_at' => now_utc(),
        'app'        => APP_NAME,
        'driver'     => $driver,
        'schema'     => array_keys(migrations_applied($pdo, $driver)),
        'tables'     => $tables,
        // Control Panel settings (not the password, not the logo file); restored when present
        'settings'   => settings_export()['settings'],
    ];
}

/** Photo file names referenced by a dump. */
function backup_photo_files(array $dump): array
{
    $files = [];
    foreach (['item_photos' => 'file', 'items' => 'image'] as $t => $col) {
        $tb = $dump['tables'][$t] ?? null;
        if (!$tb) continue;
        $i = array_search($col, $tb['columns'], true);
        if ($i === false) continue;
        foreach ($tb['rows'] as $r) if (valid_image_name($r[$i] ?? null)) $files[$r[$i]] = true;
    }
    ksort($files);
    return array_keys($files);
}

/**
 * Served photo name → original basename, for photos that still have a camera file.
 * @param string[] $photoNames
 * @return array<string,string>
 */
function backup_original_index(array $photoNames): array
{
    $index = [];
    foreach ($photoNames as $f) {
        $path = image_original_path($f);
        if ($path === null) continue;
        $base = basename($path);
        if (!image_original_basename_ok($base)) continue;
        $index[$f] = $base;
    }
    ksort($index);
    return $index;
}

/** Attachment file names referenced by a dump. */
function backup_attachment_files(array $dump): array
{
    $tb = $dump['tables']['item_attachments'] ?? null;
    if (!$tb) return [];
    $i = array_search('file', $tb['columns'], true);
    if ($i === false) return [];
    $files = [];
    foreach ($tb['rows'] as $r) if (valid_attachment_name($r[$i] ?? null)) $files[$r[$i]] = true;
    ksort($files);
    return array_keys($files);
}

/**
 * Write a backup zip.
 * @return array{tables:array<string,int>,photos:int,missing:string[],bytes:int}
 */
function backup_create(PDO $pdo, string $driver, string $dataDir, string $zipPath): array
{
    $dump = backup_dump($pdo, $driver);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException("Cannot write $zipPath");
    $zip->addFromString('backup.json', json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    $missing = [];
    $n = 0;
    foreach (backup_photo_files($dump) as $f) {
        $full = "$dataDir/images/$f";
        if (!is_file($full)) { $missing[] = $f; continue; }
        $zip->addFile($full, "images/$f");
        $zip->setCompressionName("images/$f", ZipArchive::CM_STORE);   // JPEGs don't compress
        if (is_file("$dataDir/thumbs/$f")) {
            $zip->addFile("$dataDir/thumbs/$f", "thumbs/$f");
            $zip->setCompressionName("thumbs/$f", ZipArchive::CM_STORE);
        }
        $n++;
    }
    $index = backup_original_index(backup_photo_files($dump));
    if ($index) {
        $zip->addFromString('originals/index.json', json_encode($index, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        foreach ($index as $base) {
            $full = ORIGINALS_DIR . '/' . $base;
            if (!is_file($full)) { $missing[] = $base; continue; }
            $zip->addFile($full, "originals/$base");
            $zip->setCompressionName("originals/$base", ZipArchive::CM_STORE);
        }
    }
    foreach (backup_attachment_files($dump) as $f) {
        $full = "$dataDir/files/$f";
        if (!is_file($full)) { $missing[] = $f; continue; }
        $zip->addFile($full, "files/$f");
    }
    // Catalog files are added by name. The data directory is not walked, and a key file is not an entry.
    backup_drop_key_entries($zip);
    if (!$zip->close()) throw new RuntimeException("Cannot write $zipPath");
    return ['tables' => array_map(fn($t) => count($t['rows']), $dump['tables']), 'photos' => $n, 'missing' => $missing, 'bytes' => (int)filesize($zipPath)];
}

/** Drop a key file if one was ever added under that name. */
function backup_drop_key_entries(ZipArchive $zip): void
{
    for ($i = $zip->numFiles - 1; $i >= 0; $i--) {
        $n = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($n === 'app.key' || str_ends_with($n, '/app.key')) $zip->deleteIndex($i);
    }
}

/**
 * Open and validate a backup zip without touching the database.
 * @return array{dump:array,zip:ZipArchive,tables:array<string,int>,photos:int,created_at:string,driver:string,warnings:string[]}
 */
function backup_open(string $zipPath, PDO $pdo, string $driver): array
{
    $zip = new ZipArchive();
    if (!is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) throw new UserError('That file is not a zip archive.');
    if ($zip->numFiles > BACKUP_MAX_ENTRIES) throw new UserError('The backup has too many files.');
    $total = 0;
    $largest = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $s = $zip->statIndex($i);
        $size = (int)($s['size'] ?? 0);
        $total += $size;
        $name = (string)($s['name'] ?? '');
        if ($name !== 'backup.json' && $name !== 'originals/index.json'
            && !preg_match('#^(images|thumbs)/[a-f0-9]{32}\.jpg$#D', $name) && !preg_match('#^(images|thumbs|files|originals)/$#D', $name)
            && !preg_match('#^files/[a-f0-9]{32}\.(pdf|jpg|png|webp)$#D', $name)
            && !preg_match('#^originals/[a-f0-9]{64}\.(jpg|png|webp|gif|heic)$#D', $name)) {
            throw new UserError('Unexpected file in backup: ' . mb_substr($name, 0, 80));
        }
        // from the zip's directory, before anything is read
        if ($size > BACKUP_MAX_ENTRY && $name !== 'backup.json') $largest ??= $name;
    }
    if ($total > BACKUP_MAX_UNPACKED) throw new UserError('The backup is too large to unpack.');
    if ($largest !== null) throw new UserError('A file in the backup is too large to restore: ' . mb_substr($largest, 0, 80));
    $json = $zip->getFromName('backup.json');
    if ($json === false) throw new UserError('This is not an Apotheke backup (backup.json is missing).');
    try {
        $dump = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new UserError('backup.json is not valid JSON.');
    }
    if (!is_array($dump) || ($dump['format'] ?? null) !== BACKUP_FORMAT) throw new UserError('This is not an Apotheke backup.');
    if (!is_int($dump['version'] ?? null) || $dump['version'] > BACKUP_VERSION) throw new UserError('This backup was made by a newer version of Apotheke.');
    if (!is_array($dump['tables'] ?? null) || !is_array($dump['schema'] ?? null)) throw new UserError('The backup is incomplete.');
    $known = array_keys(migrations_available());
    $unknown = array_diff(array_map('strval', $dump['schema']), $known);
    if ($unknown) throw new UserError('This backup needs a newer version of Apotheke (schema ' . implode(', ', $unknown) . ').');

    $warnings = [];
    $allowed = backup_tables();
    foreach ($dump['tables'] as $t => $tb) {
        if (!in_array($t, $allowed, true)) throw new UserError('Unknown table in backup: ' . mb_substr((string)$t, 0, 40));
        if (!is_array($tb['columns'] ?? null) || !is_array($tb['rows'] ?? null)) throw new UserError("Table $t is malformed.");
        $have = backup_columns($pdo, $driver, $t);
        foreach ($tb['columns'] as $c) {
            if (!is_string($c) || !in_array($c, $have, true)) throw new UserError("Unknown column $t." . mb_substr((string)$c, 0, 40) . ' in backup.');
        }
        $n = count($tb['columns']);
        foreach ($tb['rows'] as $r) {
            if (!is_array($r) || count($r) !== $n) throw new UserError("A row in $t has the wrong number of values.");
            foreach ($r as $v) if (!is_scalar($v) && $v !== null) throw new UserError("A value in $t is not a plain value.");
        }
    }
    foreach (['locations', 'categories', 'items'] as $t) if (!isset($dump['tables'][$t])) throw new UserError("The backup has no $t table.");
    $photos = 0;
    foreach (backup_photo_files($dump) as $f) {
        if ($zip->locateName("images/$f") === false) { $warnings[] = "Photo $f is missing from the archive."; continue; }
        $photos++;
    }
    foreach (backup_attachment_files($dump) as $f) {
        if ($zip->locateName("files/$f") === false) $warnings[] = "Attachment $f is missing from the archive.";
    }
    return ['dump' => $dump, 'zip' => $zip, 'tables' => array_map(fn($t) => count($t['rows']), $dump['tables']), 'photos' => $photos,
            'created_at' => (string)($dump['created_at'] ?? ''), 'driver' => (string)($dump['driver'] ?? '?'), 'warnings' => $warnings];
}

/** Current row counts, for the dry-run comparison. */
function backup_counts(PDO $pdo, string $driver): array
{
    $o = [];
    foreach (backup_tables() as $t) if (backup_table_exists($pdo, $driver, $t)) $o[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    return $o;
}

/**
 * Replace all catalog data with the backup's.
 *
 * One restore runs at a time (web and CLI): the whole restore, files and rows, holds an exclusive lock on
 * DATA_DIR/tmp/restore.lock, and a second one is refused at once (code 409). A restore that fails at any point
 * removes every file it wrote, and the camera-original links are rewritten only after the rows commit, so the
 * files, the links and the data are as they were.
 * @return array{tables:array<string,int>,photos:int,warnings:string[]}
 */
function backup_restore(PDO $pdo, string $driver, string $dataDir, string $zipPath): array
{
    $lock = backup_restore_lock($dataDir);
    try {
        return backup_restore_locked($pdo, $driver, $dataDir, $zipPath);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * DATA_DIR/tmp/restore.lock, held exclusively. Not waited for: while another restore holds it, the answer is busy.
 * Only ever a plain file, never through a link: one that is made is made without following a link, and the file
 * that was opened must be the plain file at the name. A lock file another user made (say a root CLI restore) is opened
 * read-only; flock() works on a read handle too. $afterCheck runs between the check of the name and the open, and
 * $afterOpen between the open and the check of what was opened (test seams).
 * @param ?Closure():void $afterCheck
 * @param ?Closure():void $afterOpen
 * @return resource
 */
function backup_restore_lock(string $dataDir, ?Closure $afterCheck = null, ?Closure $afterOpen = null)
{
    $dir = "$dataDir/tmp";
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $path = $dir . '/' . BACKUP_RESTORE_LOCK;
    $named = @lstat($path);
    if ($named !== false && !backup_plain_file($named)) throw new UserError(BACKUP_RESTORE_FAILED);
    if ($afterCheck !== null) $afterCheck();
    if ($named === false) backup_restore_lock_create($path);
    clearstatcache(true, $path);
    $fh = @fopen($path, 'r');
    if ($fh === false) throw new UserError(BACKUP_RESTORE_FAILED);
    if ($afterOpen !== null) $afterOpen();
    clearstatcache(true, $path);
    $open = fstat($fh);
    $now = @lstat($path);
    if ($open === false || $now === false || !backup_plain_file($now) || $open['dev'] !== $now['dev'] || $open['ino'] !== $now['ino']) {
        fclose($fh);
        throw new UserError(BACKUP_RESTORE_FAILED);
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        throw new UserError(BACKUP_RESTORE_BUSY, 409);
    }
    return $fh;
}

/**
 * Make the lock file when nothing is at $path. fopen() follows a link at the name even with 'x', so the file is made
 * under a random name and hard-linked into place: link() never follows a link and fails when the name is taken. Only
 * where the file system has no hard links is 'x' used.
 */
function backup_restore_lock_create(string $path): void
{
    $tmp = dirname($path) . '/.' . basename($path) . '-' . bin2hex(random_bytes(8));
    $fh = @fopen($tmp, 'x');
    if ($fh === false) return;   // the open that follows fails
    fclose($fh);
    try {
        if (!@link($tmp, $path) && @lstat($path) === false && ($fh = @fopen($path, 'x')) !== false) fclose($fh);
    } finally {
        @unlink($tmp);
    }
}

/** @param array<int|string,int> $stat from lstat() */
function backup_plain_file(array $stat): bool
{
    return ($stat['mode'] & 0170000) === 0100000;
}

/**
 * An entry's bytes, or null when the zip has no such entry. One that is there but cannot be read, or whose bytes do
 * not match its checksum (not every PHP version checks it), fails the restore.
 */
function backup_restore_entry(ZipArchive $zip, string $name): ?string
{
    $s = $zip->statName($name);
    if ($s === false) return null;
    $data = @$zip->getFromName($name);
    if ($data === false || strlen($data) !== (int)$s['size'] || crc32($data) !== (int)$s['crc']) throw new UserError(BACKUP_RESTORE_FAILED);
    return $data;
}

/**
 * Is a plain file at $path already, so the restore keeps it? A link there, to a file or to nowhere, stops the restore
 * and is left as it is (N-565), as at a camera file's name.
 */
function backup_restore_kept(string $path): bool
{
    if (is_link($path)) throw new UserError(BACKUP_RESTORE_FAILED);
    return is_file($path);
}

/**
 * Write a file that is not there yet, whole or not at all, and note it so a failed restore can remove it again.
 * $mode, when given, is the new file's mode from the start (through the umask), so nothing changes it by name later.
 * @param list<string> $created
 */
function backup_restore_create(string $path, string $bytes, array &$created, ?int $mode = null): void
{
    $tmp = backup_restore_temp($path, $bytes, $mode);
    backup_restore_place($tmp, $path, $created);
}

/** $bytes in a new temp file next to $path (mode $mode when given); a failed write fails the restore. */
function backup_restore_temp(string $path, string $bytes, ?int $mode = null): string
{
    $tmp = dirname($path) . '/.restore-' . bin2hex(random_bytes(6));
    $umask = $mode === null ? null : umask(~$mode & 0777);
    $fh = @fopen($tmp, 'x');
    if ($umask !== null) umask($umask);
    // every byte, flushed and closed: a short write (a full disk) fails the restore
    $ok = $fh !== false && @fwrite($fh, $bytes) === strlen($bytes) && @fflush($fh);
    if ($fh !== false) $ok = @fclose($fh) && $ok;
    if (!$ok) {
        @unlink($tmp);
        throw new UserError(BACKUP_RESTORE_FAILED);
    }
    return $tmp;
}

/**
 * Move the temp file $tmp to $path, which must be free: anything there, a link too (even one that points nowhere),
 * stops the restore and is left as it is. link() never replaces or follows what is at the name; only where the file
 * system has no hard links is it renamed, after a check. Noted so a failed restore can remove it again.
 * @param list<string> $created
 */
function backup_restore_place(string $tmp, string $path, array &$created): void
{
    try {
        if (!@link($tmp, $path) && (@lstat($path) !== false || !@rename($tmp, $path))) throw new UserError(BACKUP_RESTORE_FAILED);
    } finally {
        @unlink($tmp);
    }
    $created[] = $path;
}

/**
 * @param list<string> $created
 */
function backup_restore_discard(array $created): void
{
    foreach (array_reverse($created) as $path) @unlink($path);   // best effort: the name is random and no row refers to it
}

/** @return array{tables:array<string,int>,photos:int,warnings:string[]} */
function backup_restore_locked(PDO $pdo, string $driver, string $dataDir, string $zipPath): array
{
    $b = backup_open($zipPath, $pdo, $driver);
    $dump = $b['dump'];
    /** @var ZipArchive $zip */
    $zip = $b['zip'];
    // files this restore creates, removed again if it fails; the original links, written after the rows commit
    $created = [];
    $links = [];
    $written = 0;
    // A fatal error (out of memory, the time limit) skips the catch below: the files are removed on shutdown instead,
    // unless the rows are in by then.
    $pending = true;
    register_shutdown_function(static function () use (&$created, &$pending): void {
        if ($pending) backup_restore_discard($created);
    });
    try {
        try {
            // 1) photo files first: decode-check each one, never overwrite (names are random)
            foreach (['images', 'thumbs'] as $sub) if (!is_dir("$dataDir/$sub")) mkdir("$dataDir/$sub", 0775, true);
            foreach (backup_photo_files($dump) as $f) {
                $data = backup_restore_entry($zip, "images/$f");
                if ($data === null) continue;
                $info = @getimagesizefromstring($data);
                if (!$info || $info[2] !== IMAGETYPE_JPEG) { $b['warnings'][] = "Photo $f is not a JPEG and was skipped."; continue; }
                if (!backup_restore_kept("$dataDir/images/$f")) backup_restore_create("$dataDir/images/$f", $data, $created);
                if (!backup_restore_kept("$dataDir/thumbs/$f")) {
                    $thumb = backup_restore_entry($zip, "thumbs/$f");
                    if ($thumb !== null && ($ti = @getimagesizefromstring($thumb)) && $ti[2] === IMAGETYPE_JPEG) {
                        backup_restore_create("$dataDir/thumbs/$f", $thumb, $created);
                    } elseif (($im = @imagecreatefromstring($data)) !== false) {
                        ob_start();   // regenerate a missing thumbnail
                        imagejpeg(image_fit($im, image_thumb_max()), null, 76);
                        backup_restore_create("$dataDir/thumbs/$f", (string)ob_get_clean(), $created);
                    }
                }
                $written++;
            }
            if (!is_dir("$dataDir/files")) mkdir("$dataDir/files", 0770, true);
            foreach (backup_attachment_files($dump) as $f) {
                $data = backup_restore_entry($zip, "files/$f");
                if ($data === null || backup_restore_kept("$dataDir/files/$f")) continue;
                $tmp = backup_restore_temp("$dataDir/files/$f", $data);
                if (attach_sniff($tmp) !== pathinfo($f, PATHINFO_EXTENSION)) { @unlink($tmp); $b['warnings'][] = "Attachment $f does not match its type and was skipped."; continue; }
                backup_restore_place($tmp, "$dataDir/files/$f", $created);
            }
            backup_restore_originals($zip, $dump, $b['warnings'], $created, $links);
        } finally {
            $zip->close();
        }

        // 2) data: one transaction, children deleted first, parents inserted first
        $tables = array_values(array_filter(backup_tables(), fn($t) => backup_table_exists($pdo, $driver, $t)));
        $pdo->beginTransaction();
        try {
            if ($driver === 'sqlite') $pdo->exec('PRAGMA defer_foreign_keys = ON');
            foreach (array_reverse($tables) as $t) $pdo->exec("DELETE FROM $t");
            foreach ($tables as $t) {
                $tb = $dump['tables'][$t] ?? null;
                if (!$tb || !$tb['rows']) continue;
                $cols = $tb['columns'];
                $ins = $pdo->prepare("INSERT INTO $t (" . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
                foreach ($tb['rows'] as $r) {
                    foreach ($r as $k => $v) $ins->bindValue($k + 1, $v, match (true) { $v === null => PDO::PARAM_NULL, is_int($v) => PDO::PARAM_INT, is_bool($v) => PDO::PARAM_BOOL, default => PDO::PARAM_STR });
                    $ins->execute();
                }
            }
            foreach ($tables as $t) {
                if (!in_array('id', backup_columns($pdo, $driver, $t), true)) continue;
                // Never hand out an id again: past the highest restored id and, for items, past every id in the history
                // (the activity log outlives deleted items).
                $max = "SELECT MAX(id) AS m FROM $t" . ($t === 'items' && in_array('activity', $tables, true) ? ' UNION ALL SELECT MAX(item_id) AS m FROM activity' : '');
                $next = (int)$pdo->query("SELECT COALESCE(MAX(m), 0) FROM ($max) x")->fetchColumn();
                if ($driver === 'pgsql') {
                    // identity columns don't advance on explicit ids
                    $pdo->query("SELECT setval(pg_get_serial_sequence('$t', 'id'), $next + 1, false)");
                } else {
                    $pdo->prepare('DELETE FROM sqlite_sequence WHERE name = ?')->execute([$t]);
                    $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$t, $next]);
                }
            }
            $pdo->commit();
            $pending = false;
        } catch (Throwable $e) {
            $pdo->rollBack();
            after_commit_end(false);
            if ($e instanceof PDOException) throw new UserError('The backup could not be restored (' . preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 200)) . '). Nothing was changed.');
            throw $e;
        }
    } catch (Throwable $e) {
        $pending = false;
        backup_restore_discard($created);
        throw $e;
    }
    after_commit_end(true);
    backup_restore_link_originals($links, $b['warnings'], $created);
    if (is_array($dump['settings'] ?? null)) {
        $r = settings_import((string)json_encode(['format' => 'apotheke-settings', 'settings' => $dump['settings']]));
        foreach ($r['errors'] as $k => $msg) $b['warnings'][] = "Setting $k was not restored: $msg";
        $tokenCleared = $r['token_cleared'];
    }
    // undo tokens refer to the data that was just replaced
    if (backup_table_exists($pdo, $driver, 'undo_tokens')) undo_prune(true);
    return ['tables' => $b['tables'], 'photos' => $written, 'warnings' => $b['warnings'], 'token_cleared' => $tokenCleared ?? false];
}

/** Human summary lines: "12 items, 3 locations, …". */
function backup_summary_text(array $counts, int $photos): string
{
    $labels = ['items' => 'items', 'locations' => 'locations', 'categories' => 'categories', 'tags' => 'tags', 'activity' => 'history entries'];
    $parts = [];
    foreach ($labels as $t => $l) if (isset($counts[$t])) $parts[] = $counts[$t] . ' ' . $l;
    $parts[] = $photos . ' photos';
    return implode(', ', $parts);
}

/**
 * Put camera files back next to the served photos. Bytes are written only when missing (names are random), and
 * noted in $created. The map entry that points the served name at the file from this zip goes into $links, for
 * backup_restore_link_originals() once the rows commit. A backup with no index is unchanged.
 * @param list<string> $warnings
 * @param list<string> $created
 * @param array<string,string> $links map path => original path
 */
function backup_restore_originals(ZipArchive $zip, array $dump, array &$warnings, array &$created, array &$links): void
{
    $raw = backup_restore_entry($zip, 'originals/index.json');
    if (!is_string($raw) || $raw === '') return;
    try {
        $index = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $warnings[] = 'originals/index.json was skipped.';
        return;
    }
    if (!is_array($index)) return;
    if (!image_originals_prepare()) {
        $warnings[] = 'Camera files could not be restored (originals directory is not writable).';
        return;
    }
    $photos = array_flip(backup_photo_files($dump));
    foreach ($index as $served => $base) {
        if (!is_string($served) || !valid_image_name($served) || !isset($photos[$served])) continue;
        if (!is_string($base) || !image_original_basename_ok($base)) continue;
        $data = backup_restore_entry($zip, 'originals/' . $base);
        if ($data === null) { $warnings[] = "Original $base is missing from the archive."; continue; }
        $dest = ORIGINALS_DIR . '/' . $base;
        // a link at a camera file's name, to anything or nowhere, stops the restore: its mode is set once the rows are in
        if (is_link($dest)) throw new UserError(BACKUP_RESTORE_FAILED);
        if (!is_file($dest)) {
            try {
                backup_restore_create($dest, $data, $created, 0640);   // 0640 from the start (N-582)
            } catch (UserError) {
                $warnings[] = 'Camera files could not be restored (originals directory is not writable).';
                return;
            }
        }
        $links[ORIGINALS_DIR . '/map/' . substr($served, 0, 32)] = $dest;
    }
}

/**
 * The camera-original links, once the rows are in. Each map file is written to a new 0640 temp file in the map folder
 * and renamed into place, so it is never written through a link. A map name that is a link, or anything but a plain file,
 * is skipped with the camera-files warning and left as it is: the rows are in by now, so it no longer stops the restore.
 * @param array<string,string> $links map path => original path
 * @param list<string> $warnings
 * @param list<string> $created the files this restore wrote
 */
function backup_restore_link_originals(array $links, array &$warnings, array $created): void
{
    $warning = 'Camera files could not be restored (originals directory is not writable).';
    foreach ($links as $map => $dest) {
        // A camera file this restore wrote is 0640 from the start and is left alone (N-682). Only one kept from an older
        // install with another mode is changed, by name, while it is a plain file (never through a link). The restore
        // runs as the data folder's owner (bin/restore.php switches to that user), so this changes nothing that user
        // could not change (N-582).
        $file = in_array($dest, $created, true) ? false : @lstat($dest);
        if ($file !== false && backup_plain_file($file) && ($file['mode'] & 0777) !== 0640) @chmod($dest, 0640);
        $named = @lstat($map);
        if ($named !== false && !backup_plain_file($named)) {
            if (!in_array($warning, $warnings, true)) $warnings[] = $warning;
            continue;
        }
        $tmp = dirname($map) . '/.map-' . bin2hex(random_bytes(6));
        $umask = umask(0137);   // the new file is 0640 from the start
        $fh = @fopen($tmp, 'x');
        umask($umask);
        $text = basename($dest);
        $ok = $fh !== false && @fwrite($fh, $text) === strlen($text);
        if ($fh !== false) $ok = fclose($fh) && $ok;
        if (!$ok || !@rename($tmp, $map)) {
            @unlink($tmp);
            $warnings[] = $warning;
            return;
        }
    }
}

/** Old restore uploads are removed after an hour. */
function backup_tmp_dir(string $dataDir): string
{
    $d = "$dataDir/tmp";
    if (!is_dir($d)) mkdir($d, 0770, true);
    foreach (glob("$d/restore-*.zip") ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
    return $d;
}
