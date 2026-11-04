<?php
declare(strict_types=1);

/*
 * PLAN step 16 part 2: a normalised snapshot of one install, for the upgrade/rollback job.
 *   php tests/upgrade/snapshot.php [--scope=all|backup] > snapshot.json
 * Reads DATA_DIR, ORIGINALS_DIR, SQLITE_PATH and DB_* from the environment, as both apps do, without booting either
 * app. Output: the columns and rows of every table (rows sorted, values as strings), path => sha256 for every file
 * under images/, thumbs/, originals/ (map/ included) and files/ (attachments), the mode of every path there, and
 * the carried tables (what a backup zip holds: backup_tables() of both apps, plus the settings rows).
 * What only CI4 adds (upg_ci4_only(): the jobs table and its migration's row) is left out of every snapshot.
 * --scope=backup keeps only the carried tables; a restore writes each setting again, so `settings.updated_at` is
 * the one value left out there.
 */

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/lib.php';
$opts = getopt('', ['scope:']);
$scope = $opts['scope'] ?? 'all';
$dataDir = upg_data_dir();
$originals = rtrim((string)(getenv('ORIGINALS_DIR') ?: $dataDir . '/originals'), '/');
$pdo = upg_pdo();

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
    $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    $columns = static function (string $t) use ($pdo): array {
        $st = $pdo->prepare('SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position');
        $st->execute([$t]);
        return array_map(static fn (array $c): string => implode(' ', array_map('strval', $c)), $st->fetchAll(PDO::FETCH_ASSOC));
    };
} else {
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $columns = static fn (string $t): array => array_map(static fn (array $c): string => implode(' ', [$c['name'], $c['type'], $c['notnull'], (string)$c['dflt_value']]),
        $pdo->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC));
}

$carried = [...upg_carried_tables(), 'settings'];
$ci4Only = upg_ci4_only();
$out = ['carried' => $carried, 'schema' => [], 'tables' => [], 'files' => [], 'modes' => []];
foreach ($tables as $t) {
    if (!preg_match('/^[a-z0-9_]+$/', (string)$t) || ($scope === 'backup' && !in_array($t, $carried, true)) || in_array($t, $ci4Only['tables'], true)) continue;
    $out['schema'][$t] = $columns($t);
    $rows = array_map(static function (array $r) use ($scope, $t): string {
        ksort($r);
        if ($scope === 'backup' && $t === 'settings') unset($r['updated_at']);
        return (string)json_encode(array_map(static fn ($v) => $v === null ? null : (string)$v, $r), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }, array_filter($pdo->query('SELECT * FROM "' . $t . '"')->fetchAll(PDO::FETCH_ASSOC),
        static fn (array $r): bool => $t !== 'schema_migrations' || !in_array((string)$r['version'], $ci4Only['versions'], true)));
    sort($rows, SORT_STRING);
    $out['tables'][$t] = $rows;
}
foreach (['images' => $dataDir . '/images', 'thumbs' => $dataDir . '/thumbs', 'originals' => $originals, 'files' => $dataDir . '/files'] as $label => $dir) {
    if (!is_dir($dir)) continue;
    $out['modes'][$label] = sprintf('%04o', fileperms($dir) & 0o7777);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = $label . substr($f->getPathname(), strlen($dir));
        $out['modes'][$rel] = sprintf('%04o', $f->getPerms() & 0o7777) . ($f->isDir() ? '/' : '');
        if ($f->isFile()) $out['files'][$rel] = hash_file('sha256', $f->getPathname());
    }
}
ksort($out['files']);
ksort($out['modes']);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
