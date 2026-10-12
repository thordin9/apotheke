<?php
declare(strict_types=1);

/*
 * Apotheke – portable backup & restore.
 *
 * A backup is a zip with
 *   backup.json   {"format":"apotheke-backup","version":1,"created_at":…,"app":…,"driver":…,"schema":[migration versions],
 *                  "tables":{"<table>":{"columns":[…],"rows":[[…],…]}, …}}
 *   images/<hex>.jpg, thumbs/<hex>.jpg   every photo referenced by the data
 * The JSON dump is driver-neutral, so a backup taken on SQLite restores into PostgreSQL and vice versa.
 * Restore replaces ALL catalog data in one transaction; photo files are written first (random names, so
 * nothing existing is overwritten) and files no longer referenced are kept (harmless, never deleted).
 */

const BACKUP_FORMAT  = 'apotheke-backup';
const BACKUP_VERSION = 1;
const BACKUP_MAX_UNPACKED = 4 * 1024 * 1024 * 1024;   // zip-bomb guard: 4 GB unpacked
const BACKUP_MAX_ENTRIES  = 100_000;

/** Tables in foreign-key order (parents first). Restore deletes in reverse. */
function backup_tables(): array
{
    return ['locations', 'categories', 'tags', 'items', 'item_photos', 'item_tags', 'app_settings'];
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
    if (!$zip->close()) throw new RuntimeException("Cannot write $zipPath");
    return ['tables' => array_map(fn($t) => count($t['rows']), $dump['tables']), 'photos' => $n, 'missing' => $missing, 'bytes' => (int)filesize($zipPath)];
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
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $s = $zip->statIndex($i);
        $total += (int)($s['size'] ?? 0);
        $name = (string)($s['name'] ?? '');
        if ($name !== 'backup.json' && !preg_match('#^(images|thumbs)/[a-f0-9]{32}\.jpg$#D', $name) && !preg_match('#^(images|thumbs)/$#D', $name)) {
            throw new UserError('Unexpected file in backup: ' . mb_substr($name, 0, 80));
        }
    }
    if ($total > BACKUP_MAX_UNPACKED) throw new UserError('The backup is too large to unpack.');
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
 * @return array{tables:array<string,int>,photos:int,warnings:string[]}
 */
function backup_restore(PDO $pdo, string $driver, string $dataDir, string $zipPath): array
{
    $b = backup_open($zipPath, $pdo, $driver);
    $dump = $b['dump'];
    /** @var ZipArchive $zip */
    $zip = $b['zip'];

    // 1) photo files first: decode-check each one, never overwrite (names are random)
    $written = 0;
    foreach (['images', 'thumbs'] as $sub) if (!is_dir("$dataDir/$sub")) mkdir("$dataDir/$sub", 0775, true);
    foreach (backup_photo_files($dump) as $f) {
        $data = $zip->getFromName("images/$f");
        if ($data === false) continue;
        $info = @getimagesizefromstring($data);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) { $b['warnings'][] = "Photo $f is not a JPEG and was skipped."; continue; }
        if (!is_file("$dataDir/images/$f")) file_put_contents("$dataDir/images/$f", $data, LOCK_EX);
        if (!is_file("$dataDir/thumbs/$f")) {
            $thumb = $zip->getFromName("thumbs/$f");
            if ($thumb !== false && ($ti = @getimagesizefromstring($thumb)) && $ti[2] === IMAGETYPE_JPEG) {
                file_put_contents("$dataDir/thumbs/$f", $thumb, LOCK_EX);
            } elseif (($im = @imagecreatefromstring($data)) !== false) {
                imagejpeg(image_fit($im, THUMB_MAX), "$dataDir/thumbs/$f", 76);   // regenerate a missing thumbnail
            }
        }
        $written++;
    }
    $zip->close();

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
            if ($driver === 'pgsql' && in_array('id', backup_columns($pdo, $driver, $t), true)) {
                // identity columns don't advance on explicit ids: move the sequence past the highest restored id
                $pdo->query("SELECT setval(pg_get_serial_sequence('$t', 'id'), COALESCE((SELECT MAX(id) FROM $t), 0) + 1, false)");
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException) throw new UserError('The backup could not be restored (' . preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 200)) . '). Nothing was changed.');
        throw $e;
    }
    return ['tables' => $b['tables'], 'photos' => $written, 'warnings' => $b['warnings']];
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

/** Old restore uploads are removed after an hour. */
function backup_tmp_dir(string $dataDir): string
{
    $d = "$dataDir/tmp";
    if (!is_dir($d)) mkdir($d, 0770, true);
    foreach (glob("$d/restore-*.zip") ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
    return $d;
}
