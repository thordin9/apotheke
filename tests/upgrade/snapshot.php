<?php
declare(strict_types=1);

/*
 * PLAN step 16 part 2: a normalised snapshot of one install, for the upgrade/rollback job.
 *   php tests/upgrade/snapshot.php [--scope=all|backup] > snapshot.json
 * Reads DATA_DIR, ORIGINALS_DIR, SQLITE_PATH and DB_* from the environment, as both apps do, without booting either
 * app (so it runs on PHP 8.3 and 8.4 alike). Output: the columns and rows of every table (rows sorted, values as
 * strings), and path => sha256 for every file under images/, thumbs/, originals/ (map/ included). File modes are
 * listed separately; the job reports a mode difference but fails only on content.
 * --scope=backup keeps the tables a backup zip carries (src/backup.php backup_tables()) and the settings rows; a
 * restore writes each setting again, so `settings.updated_at` is the one value left out there.
 */

if (PHP_SAPI !== 'cli') exit(1);
$opts = getopt('', ['scope:']);
$scope = $opts['scope'] ?? 'all';
$dataDir = rtrim((string)getenv('DATA_DIR'), '/');
if ($dataDir === '' || !is_dir($dataDir)) { fwrite(STDERR, "snapshot: DATA_DIR is not a directory\n"); exit(2); }
$originals = rtrim((string)(getenv('ORIGINALS_DIR') ?: $dataDir . '/originals'), '/');

if (getenv('DB_DRIVER') === 'pgsql') {
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'apotheke'),
        (string)getenv('DB_USER'), (string)getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = (string)getenv('DB_SCHEMA');
    if ($schema !== '') $pdo->exec('SET search_path TO "' . str_replace('"', '""', $schema) . '"');
    $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    $columns = static function (string $t) use ($pdo): array {
        $st = $pdo->prepare('SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position');
        $st->execute([$t]);
        return array_map(static fn (array $c): string => implode(' ', array_map('strval', $c)), $st->fetchAll(PDO::FETCH_ASSOC));
    };
} else {
    $pdo = new PDO('sqlite:' . (getenv('SQLITE_PATH') ?: $dataDir . '/catalog.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $columns = static fn (string $t): array => array_map(static fn (array $c): string => implode(' ', [$c['name'], $c['type'], $c['notnull'], (string)$c['dflt_value']]),
        $pdo->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC));
}

$keep = ['locations', 'categories', 'tags', 'items', 'item_photos', 'item_tags', 'item_attachments', 'app_settings', 'activity', 'saved_filters', 'stocktakes', 'stocktake_lines', 'settings'];
$out = ['schema' => [], 'tables' => [], 'files' => [], 'modes' => []];
foreach ($tables as $t) {
    if (!preg_match('/^[a-z0-9_]+$/', (string)$t) || ($scope === 'backup' && !in_array($t, $keep, true))) continue;
    $out['schema'][$t] = $columns($t);
    $rows = array_map(static function (array $r) use ($scope, $t): string {
        ksort($r);
        if ($scope === 'backup' && $t === 'settings') unset($r['updated_at']);
        return (string)json_encode(array_map(static fn ($v) => $v === null ? null : (string)$v, $r), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }, $pdo->query('SELECT * FROM "' . $t . '"')->fetchAll(PDO::FETCH_ASSOC));
    sort($rows, SORT_STRING);
    $out['tables'][$t] = $rows;
}
foreach (['images' => $dataDir . '/images', 'thumbs' => $dataDir . '/thumbs', 'originals' => $originals] as $label => $dir) {
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = $label . substr($f->getPathname(), strlen($dir));
        $out['modes'][$rel] = sprintf('%04o', $f->getPerms() & 0o7777);
        if ($f->isFile()) $out['files'][$rel] = hash_file('sha256', $f->getPathname());
    }
}
ksort($out['files']);
ksort($out['modes']);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
