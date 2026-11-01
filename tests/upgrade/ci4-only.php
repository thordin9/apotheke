<?php
declare(strict_types=1);

/*
 * The CI4-only additions (upg_ci4_only(): migrations 0029 to 0031, the jobs, import_rows, uow_guard and uow_refs
 * tables) on a real install, for the
 * upgrade/rollback job. The snapshots leave them out, so this checks them on their own.
 *   php tests/upgrade/ci4-only.php seed          after CI4 migrated: adds two jobs rows (one done, one queued) and
 *                                                two import_rows rows
 *   php tests/upgrade/ci4-only.php check LABEL   every CI4-only migration is recorded, every CI4-only table is there,
 *                                                and the seeded rows are unchanged
 *   php tests/upgrade/ci4-only.php zip LABEL Z   the backup zip Z (made by CI4 on that install) names no CI4-only
 *                                                migration in its schema and carries no CI4-only table, so 2.6.x takes it
 * check exits 1 with a FAIL line naming what is missing, so a rollback or a 2.6.x restore that dropped or emptied
 * the table, or forgot the migration, fails the job.
 */

if (PHP_SAPI !== 'cli') exit(2);
require __DIR__ . '/lib.php';
$pdo = upg_pdo();
$only = upg_ci4_only();
const UPG_JOBS = [
    ['upgrade.check', 'done', '{"n":1}', '{"ok":true}', 1],
    ['upgrade.check', 'queued', '{"n":2}', null, 0],
];
const UPG_IMPORT_ROWS = [
    [9001, 2, 'new', '', '{"location":["Shed"]}'],
    [9001, 3, 'error', 'Name is empty.', '{}'],
];

$mode = $argv[1] ?? '';
if ($mode === 'seed' && $argc === 2) {
    $st = $pdo->prepare("INSERT INTO jobs (type, status, payload, result, attempts, run_after, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    foreach (UPG_JOBS as $row) $st->execute($row);
    $st = $pdo->prepare('INSERT INTO import_rows (import_id, line, state, error, made) VALUES (?, ?, ?, ?, ?)');
    foreach (UPG_IMPORT_ROWS as $row) $st->execute($row);
    echo 'ok ci4-only seed: ' . count(UPG_JOBS) . ' jobs rows, ' . count(UPG_IMPORT_ROWS) . " import_rows rows\n";
    exit(0);
}
if ($mode === 'zip' && $argc === 4) {
    $zip = new ZipArchive();
    if ($zip->open($argv[3]) !== true) { echo "FAIL {$argv[2]}: cannot open {$argv[3]}\n"; exit(1); }
    $dump = json_decode((string)$zip->getFromName('backup.json'), true);
    $zip->close();
    $bad = [];
    foreach (array_intersect(array_map('strval', (array)($dump['schema'] ?? [])), $only['versions']) as $v) $bad[] = "the backup names CI4-only migration $v";
    foreach (array_intersect(array_keys((array)($dump['tables'] ?? [])), $only['tables']) as $t) $bad[] = "the backup carries CI4-only table $t";
    if (!is_array($dump) || ($dump['schema'] ?? []) === []) $bad[] = 'backup.json has no schema';
    foreach ($bad as $b) echo "FAIL {$argv[2]}: $b\n";
    if ($bad === []) printf("ok %s: %d migrations named, none CI4-only\n", $argv[2], count($dump['schema']));
    exit($bad ? 1 : 0);
}
if ($mode !== 'check' || $argc !== 3) { fwrite(STDERR, "usage: ci4-only.php seed | check LABEL | zip LABEL FILE\n"); exit(2); }
$label = $argv[2];
$bad = [];
$applied = array_map('strval', $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
foreach ($only['versions'] as $v) {
    if (!in_array($v, $applied, true)) $bad[] = "migration $v is not recorded";
}
foreach ($only['tables'] as $t) {
    try {
        $pdo->query('SELECT 1 FROM "' . $t . '" LIMIT 1');
    } catch (PDOException) {
        $bad[] = "table $t is missing";
    }
}
if ($bad === []) {
    $rows = $pdo->query('SELECT type, status, payload, result, attempts FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    $rows = array_map(static fn (array $r): array => [(string)$r[0], (string)$r[1], (string)$r[2], $r[3] === null ? null : (string)$r[3], (int)$r[4]], $rows);
    if ($rows !== UPG_JOBS) $bad[] = 'table jobs rows changed: ' . json_encode($rows);
    $rows = $pdo->query('SELECT import_id, line, state, error, made FROM import_rows ORDER BY import_id, line')->fetchAll(PDO::FETCH_NUM);
    $rows = array_map(static fn (array $r): array => [(int)$r[0], (int)$r[1], (string)$r[2], (string)$r[3], (string)$r[4]], $rows);
    if ($rows !== UPG_IMPORT_ROWS) $bad[] = 'table import_rows rows changed: ' . json_encode($rows);
}
foreach ($bad as $b) echo "FAIL $label: $b\n";
if ($bad === []) printf("ok %s: CI4-only %s recorded, %s kept with %d rows\n", $label, implode(', ', $only['versions']), implode(', ', $only['tables']), count(UPG_JOBS) + count(UPG_IMPORT_ROWS));
exit($bad ? 1 : 0);
