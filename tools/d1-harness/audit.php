<?php

declare(strict_types=1);

// §3c D1 function audit: every SQLite function / SQL feature the app uses, run on local D1 and on PHP's SQLite3 side by side.
// Usage: ./run.sh [worktree] audit.php   (wrangler dev --local only; never remote)
require ($argv[1] ?? '/workspace/coder2/wt-p4a') . '/vendor/autoload.php';
require __DIR__ . '/HttpBatchExecutor.php';

$d1 = new HttpBatchExecutor();
$tmp = sys_get_temp_dir() . '/d1-audit-' . getmypid();
@mkdir($tmp, 0700);
$lite = new SQLite3(':memory:');
$lite->enableExceptions(true);
$lite->exec('PRAGMA foreign_keys = ON');

/** Run statements as one D1 batch; returns rows of the last one. */
$onD1 = static function (array $stmts) use ($d1): array {
    $r = $d1->run(array_map(static fn ($s) => is_array($s) ? ['sql' => $s[0], 'params' => $s[1]] : ['sql' => $s, 'params' => []], $stmts));

    return end($r) ?: [];
};
$onLite = static function (array $stmts) use ($lite): array {
        foreach ($stmts as $s) {
        [$sql, $p] = is_array($s) ? $s : [$s, []];
        $st = $lite->prepare($sql);
        foreach ($p as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? SQLITE3_INTEGER : (is_float($v) ? SQLITE3_FLOAT : (is_null($v) ? SQLITE3_NULL : SQLITE3_TEXT)));
        }
        $res = $st->execute();
        $rows = [];
        while ($res instanceof SQLite3Result && $res->numColumns() > 0 && ($row = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }
    }

    return $rows;
};
$try = static function (callable $f, array $stmts): array {
    try {
        return [true, $f($stmts)];
    } catch (Throwable $e) {
        return [false, preg_replace('/\s+/', ' ', $e->getMessage())];
    }
};
$show = static fn (array $rows): string => substr(json_encode($rows, JSON_UNESCAPED_UNICODE), 0, 90);

$schema = [
    'DROP TABLE IF EXISTS child', 'DROP TABLE IF EXISTS parent', 'DROP TABLE IF EXISTS seq_t', 'DROP TABLE IF EXISTS docs', 'DROP TABLE IF EXISTS parent_v2',
    'CREATE TABLE parent (id INTEGER PRIMARY KEY, name TEXT NOT NULL, name_key TEXT UNIQUE, created_at TEXT, q INTEGER NOT NULL DEFAULT 1 CHECK (q >= 0))',
    'CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parent(id) ON DELETE CASCADE)',
    'CREATE TABLE seq_t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)',
    'CREATE TABLE docs (item_id INTEGER PRIMARY KEY, name_f TEXT)',
    "INSERT INTO parent (id, name, name_key, created_at) VALUES (1, 'Café Lamp', 'cafe lamp', '2026-10-30 22:30:00'), (2, 'bowl', 'bowl', '2026-10-01 10:00:00')",
    "INSERT INTO docs VALUES (1, '50% off_x'), (2, '50 off')",
];

// [id, feature, where it is used, statements (last one is the probe), check on rows or null]
$P = [];
$P[] = ['t1', "date(x, '+N seconds') with bound modifier", 'Catalog/Stats.php:63', [["SELECT date(created_at, ?) AS k FROM parent WHERE id = 1", ['+7200 seconds']]], fn ($r) => $r[0]['k'] === '2026-10-31'];
$P[] = ['t2', "strftime('%Y-%m', x, ?)", 'Catalog/Stats.php:63', [["SELECT strftime('%Y-%m', created_at, ?) AS k FROM parent WHERE id = 1", ['+7200 seconds']]], fn ($r) => $r[0]['k'] === '2026-10'];
$P[] = ['t3', "datetime('now'), julianday(), unixepoch() (not used)", '-', ["SELECT datetime('now') IS NOT NULL AS a, julianday('2026-01-01') > 0 AS b, unixepoch('2026-01-01') AS c"], fn ($r) => $r[0]['c'] === 1767225600];
$P[] = ['j1', 'json_each(?) in IN (SELECT value ...)', 'Core/Database/BatchUnitOfWork.php:94-95', [['SELECT COUNT(*) AS n FROM parent WHERE id IN (SELECT value FROM json_each(?))', ['[1,2,3]']]], fn ($r) => $r[0]['n'] === 2];
$P[] = ['j2', 'json_extract / json_object / json_group_array (not used; for the D1 driver)', '-', ["SELECT json_extract('{\"a\":[1,2]}', '$.a[1]') AS a, json_object('k', 1) AS o, (SELECT json_group_array(id) FROM (SELECT id FROM parent ORDER BY id)) AS g"], fn ($r) => $r[0]['a'] === 2 && $r[0]['g'] === '[1,2]'];
$P[] = ['r1', 'last_insert_rowid()', 'BatchUnitOfWork.php:69', ["INSERT INTO parent (name) VALUES ('x')", 'SELECT last_insert_rowid() AS r'], fn ($r) => $r[0]['r'] === 3];
$P[] = ['r2', 'changes()', 'BatchUnitOfWork.php:85', ["UPDATE parent SET q = 2 WHERE id <= 2", 'SELECT changes() AS c'], fn ($r) => $r[0]['c'] === 2];
$P[] = ['l1', "LIKE ? ESCAPE '!'", 'Search/SearchIndex.php:169', [["SELECT item_id FROM docs WHERE name_f LIKE ? ESCAPE '!'", ['%50!% off!_%']]], fn ($r) => $r === [['item_id' => 1]]];
$P[] = ['l2', 'LIKE is ASCII case-insensitive (é not folded)', 'SearchIndex.php:169, UndoTokens.php:141', [["SELECT COUNT(*) AS n FROM parent WHERE name LIKE ?", ['%CAFÉ%']]], fn ($r) => $r[0]['n'] === 0];
$P[] = ['l3', 'GLOB, upper(), lower() (not used in SQL)', '-', ["SELECT 'abc' GLOB 'a*' AS g, upper('ab') AS u, lower('AB') AS l"], fn ($r) => $r[0]['g'] === 1 && $r[0]['u'] === 'AB'];
$P[] = ['c1', 'index ON t(name COLLATE NOCASE)', 'migrations/0001_initial_schema.php:35, LegacyFunctions.php:325', ['DROP INDEX IF EXISTS idx_p_name', 'CREATE INDEX idx_p_name ON parent(name COLLATE NOCASE)', "SELECT name FROM parent WHERE name = 'BOWL' COLLATE NOCASE"], fn ($r) => $r === [['name' => 'bowl']]];
$P[] = ['c2', 'expression index ON t(lower(name)) (pgsql branch only)', 'migrations/0001_initial_schema.php:69', ['DROP INDEX IF EXISTS idx_p_lower', 'CREATE INDEX idx_p_lower ON parent(lower(name))', 'SELECT 1 AS ok'], null];
$P[] = ['f1', "fts5 table, tokenize 'unicode61 remove_diacritics 2'", 'migrations/0019_search.php:30', ['DROP TABLE IF EXISTS s_fts_v', 'DROP TABLE IF EXISTS s_fts', "CREATE VIRTUAL TABLE s_fts USING fts5(name, tags, other, tokenize = 'unicode61 remove_diacritics 2')", 'SELECT 1 AS ok'], null];
$P[] = ['f2', "fts5vocab(s_fts, 'row')", 'migrations/0019_search.php:31', ["CREATE VIRTUAL TABLE s_fts_v USING fts5vocab(s_fts, 'row')", 'SELECT 1 AS ok'], null];
$P[] = ['f3', 'INSERT INTO fts (rowid, ...) / DELETE FROM fts', 'SearchIndex.php:141,147', ['DELETE FROM s_fts', "INSERT INTO s_fts (rowid, name, tags, other) VALUES (1, 'Café Lamp', 'kitchen', ''), (2, 'bowl', '', 'ceramic')", 'SELECT COUNT(*) AS n FROM s_fts'], fn ($r) => $r[0]['n'] === 2];
$P[] = ['f4', 'MATCH ? with "term"* prefix, diacritics folded', 'SearchIndex.php:181', [['SELECT rowid AS item_id FROM s_fts WHERE s_fts MATCH ?', ['"cafe"* AND "la"*']]], fn ($r) => $r === [['item_id' => 1]]];
$P[] = ['f5', 'bm25(fts, 10.0, 5.0, 1.0)', 'SearchIndex.php:341', [['SELECT rowid AS id, bm25(s_fts, 10.0, 5.0, 1.0) AS r FROM s_fts WHERE s_fts MATCH ?', ['"bowl"* OR "cer"*']]], fn ($r) => count($r) === 1 && $r[0]['r'] < 0];
$P[] = ['f6', 'fts5vocab read + length() + CAST(? AS INTEGER)', 'SearchIndex.php:216', [['SELECT term FROM s_fts_v WHERE length(term) BETWEEN CAST(? AS INTEGER) AND CAST(? AS INTEGER) ORDER BY term', [4, 7]]], fn ($r) => array_column($r, 'term') === ['bowl', 'cafe', 'ceramic', 'kitchen', 'lamp']];
$P[] = ['f7', 'probe SELECT rowid FROM fts LIMIT 0', 'SearchIndex.php:43', ['SELECT rowid FROM s_fts LIMIT 0'], fn ($r) => $r === []];
$P[] = ['f8', "fts5 highlight()/snippet() (not used; PHP builds snippets)", '-', [["SELECT highlight(s_fts, 0, '[', ']') AS h FROM s_fts WHERE s_fts MATCH ?", ['"cafe"*']]], fn ($r) => $r[0]['h'] === '[Café] Lamp'];
$P[] = ['p1', 'PRAGMA table_info(t)', 'Backup/Backups.php:377, migrations/0028_session_epoch.php:10', ['PRAGMA table_info(parent)'], fn ($r) => count($r) === 5];
$P[] = ['p2', 'PRAGMA defer_foreign_keys = ON', 'Backup/Restorer.php:1056', ['PRAGMA defer_foreign_keys = ON', 'INSERT INTO child (id, parent_id) VALUES (50, 9)', 'INSERT INTO parent (id, name) VALUES (9, \'late\')', 'SELECT COUNT(*) AS n FROM child WHERE id = 50'], fn ($r) => $r[0]['n'] === 1];
$P[] = ['p3', 'PRAGMA foreign_keys = ON', 'Core/Config/DatabaseFactory.php:71, PdoMigrationRunner.php:93', ['PRAGMA foreign_keys = ON', 'SELECT 1 AS ok'], null];
$P[] = ['p3b', 'PRAGMA foreign_keys = OFF, then read it back', 'not used (shows FKs cannot be turned off)', ['PRAGMA foreign_keys = OFF', 'PRAGMA foreign_keys'], null];
$P[] = ['p3c', 'PRAGMA foreign_keys = ON again (SQLite3 honours OFF, D1 does not)', '-', ['PRAGMA foreign_keys = ON', 'PRAGMA foreign_keys'], null];
$P[] = ['p4', 'PRAGMA journal_mode = WAL', 'DatabaseFactory.php:42,69, PdoMigrationRunner.php:93', ['PRAGMA journal_mode = WAL'], null];
$P[] = ['p5', 'PRAGMA busy_timeout = 5000', 'DatabaseFactory.php:68, PdoMigrationRunner.php:93', ['PRAGMA busy_timeout = 5000'], null];
$P[] = ['p6', 'PRAGMA synchronous = NORMAL', 'DatabaseFactory.php:70, PdoMigrationRunner.php:93', ['PRAGMA synchronous = NORMAL', 'SELECT 1 AS ok'], null];
$P[] = ['p7', 'PRAGMA user_version (read)', 'Migrations/PdoMigrationRunner.php:138,165', ['PRAGMA user_version'], null];
$P[] = ['p8', 'PRAGMA user_version = N (write)', 'PdoMigrationRunner.php:201', ['PRAGMA user_version = 7', 'SELECT 1 AS ok'], null];
$P[] = ['p9', 'PRAGMA foreign_key_check (for a chunked restore)', '-', ['PRAGMA foreign_key_check'], fn ($r) => $r === []];
$P[] = ['s1', 'SELECT ... FROM sqlite_master', 'Backups.php:91, BootMigration.php:97, PdoMigrationRunner.php:178,250', ["SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = 'parent'"], fn ($r) => $r[0]['n'] === 1];
$P[] = ['s2', 'DELETE/INSERT sqlite_sequence', 'Backup/Restorer.php:1231-1232', ["INSERT INTO seq_t (v) VALUES ('a')", "DELETE FROM sqlite_sequence WHERE name = 'seq_t'", "INSERT INTO sqlite_sequence (name, seq) VALUES ('seq_t', '41')", "INSERT INTO seq_t (v) VALUES ('b')", "SELECT id FROM seq_t WHERE v = 'b'"], fn ($r) => $r[0]['id'] === 42];
$P[] = ['s3', 'sqlite_version()', '- (known)', ['SELECT sqlite_version() AS v'], null];
$P[] = ['s4', 'sqlite_schema / sqlite_compileoption_used() (not used)', '-', ["SELECT (SELECT COUNT(*) FROM sqlite_schema) > 0 AS a, sqlite_compileoption_used('ENABLE_FTS5') AS b"], null];
$P[] = ['v1', "VACUUM INTO 'file'", 'PdoMigrationRunner.php:140 (legacy v1 upgrade backup)', ["VACUUM INTO '$tmp/v.sqlite'"], null];
$P[] = ['x1', 'SAVEPOINT / RELEASE / ROLLBACK TO', 'ImportExport/Imports.php:340-418, SearchIndex.php:57-61, migrations/0019 (pg branch)', ['SAVEPOINT sp1', 'RELEASE SAVEPOINT sp1'], null];
$P[] = ['x2', 'BEGIN / COMMIT', 'Writes::transaction() via CI4 transBegin()', ['BEGIN', 'COMMIT'], null];
$P[] = ['u1', 'INSERT ... ON CONFLICT (k) DO NOTHING', 'Taxonomy/TagRepository.php:70', ["INSERT INTO parent (name, name_key) VALUES ('Bowl', 'bowl') ON CONFLICT (name_key) DO NOTHING", 'SELECT changes() AS c'], fn ($r) => $r[0]['c'] === 0];
$P[] = ['u2', 'INSERT OR IGNORE', 'Migrations/LegacyFunctions.php:295', ["INSERT OR IGNORE INTO parent (name, name_key) VALUES ('Bowl', 'bowl')", 'SELECT changes() AS c'], fn ($r) => $r[0]['c'] === 0];
$P[] = ['u3', 'ON CONFLICT DO UPDATE SET x = excluded.x (not used yet)', '-', ["INSERT INTO parent (name, name_key) VALUES ('BOWL', 'bowl') ON CONFLICT (name_key) DO UPDATE SET name = excluded.name", "SELECT name FROM parent WHERE name_key = 'bowl'"], fn ($r) => $r[0]['name'] === 'BOWL'];
$P[] = ['u4', 'INSERT ... RETURNING id (pgsql branch only)', 'LegacyFunctions.php:162', ["INSERT INTO parent (name) VALUES ('ret') RETURNING id"], fn ($r) => is_int($r[0]['id'] ?? null)];
$P[] = ['w1', 'window function ROW_NUMBER() OVER (not used)', '-', ['SELECT id, ROW_NUMBER() OVER (ORDER BY id DESC) AS rn FROM parent ORDER BY id LIMIT 1'], fn ($r) => $r[0]['id'] === 1];
$P[] = ['k1', 'CHECK constraint violation', 'migrations/0001 (quantity >= 0)', ['UPDATE parent SET q = -1 WHERE id = 1'], null];
$P[] = ['k2', 'FK ON DELETE CASCADE', 'migrations 0003, 0004, 0014', ['INSERT INTO child (id, parent_id) VALUES (60, 2)', 'DELETE FROM parent WHERE id = 2', 'SELECT COUNT(*) AS n FROM child WHERE id = 60'], fn ($r) => $r[0]['n'] === 0];
$P[] = ['k3', 'ALTER TABLE ADD COLUMN ... REFERENCES ... ON DELETE SET NULL', 'migrations/0008_nested_locations.php:8', ['ALTER TABLE parent ADD COLUMN parent_ref INTEGER REFERENCES parent(id) ON DELETE SET NULL', 'SELECT 1 AS ok'], null];
$P[] = ['k4', 'table rebuild: CREATE t_v2, copy, DROP t, RENAME (children present)', 'LegacyFunctions.php:282-323 (v1 schema only)', ['CREATE TABLE parent_v2 (id INTEGER PRIMARY KEY, name TEXT NOT NULL, name_key TEXT UNIQUE, created_at TEXT, q INTEGER NOT NULL DEFAULT 1, parent_ref INTEGER)', 'INSERT INTO child (id, parent_id) VALUES (70, 1)', 'INSERT INTO parent_v2 SELECT * FROM parent', 'DROP TABLE parent', 'ALTER TABLE parent_v2 RENAME TO parent', 'SELECT (SELECT COUNT(*) FROM parent) AS parents, (SELECT COUNT(*) FROM child) AS children'], null];
$P[] = ['k5', 'same rebuild with PRAGMA defer_foreign_keys = ON first', 'fix for k4', ['DROP TABLE IF EXISTS parent_v2', 'PRAGMA defer_foreign_keys = ON', 'CREATE TABLE parent_v2 (id INTEGER PRIMARY KEY, name TEXT NOT NULL, name_key TEXT UNIQUE, created_at TEXT, q INTEGER NOT NULL DEFAULT 1, parent_ref INTEGER)', 'INSERT INTO child (id, parent_id) VALUES (71, 1)', 'INSERT INTO parent_v2 SELECT * FROM parent', 'DROP TABLE parent', 'ALTER TABLE parent_v2 RENAME TO parent', 'SELECT (SELECT COUNT(*) FROM parent) AS parents, (SELECT COUNT(*) FROM child) AS children'], null];
$P[] = ['q1', 'UNION ALL, EXISTS, CASE, derived table, COALESCE/SUM/MAX/COUNT', 'Restorer.php:1225, UndoTokens.php:182, ItemList.php:63,121, Stats.php', ["SELECT COALESCE(MAX(m), 0) AS n FROM (SELECT MAX(item_id) AS m FROM docs UNION ALL SELECT MAX(id) AS m FROM child) x WHERE EXISTS (SELECT 1 FROM docs) AND CASE WHEN 1 THEN 1 ELSE 0 END"], fn ($r) => $r[0]['n'] >= 2];
$P[] = ['q2', 'printf(), randomblob(), hex(), random() (not used)', '-', ["SELECT printf('%05d', 42) AS p, length(randomblob(8)) AS b, hex('A') AS h, typeof(random()) AS t"], fn ($r) => $r[0]['p'] === '00042' && $r[0]['h'] === '41'];
$ids = implode(',', range(1, 5000));
$case = 'CASE item_id ' . implode(' ', array_map(static fn ($i) => "WHEN $i THEN $i", range(1, 1000))) . ' ELSE 1000 END';
$P[] = ['n1', 'inlined IN (5,000 ids) + CASE with 1,000 WHENs (' . round(strlen("SELECT item_id FROM docs WHERE item_id IN ($ids) ORDER BY $case") / 1024) . ' KB)', 'Catalog/ItemList.php:49,100', ["SELECT item_id FROM docs WHERE item_id IN ($ids) ORDER BY $case"], fn ($r) => count($r) === 2];
$big = "SELECT '" . str_repeat('x', 100_100) . "' AS s";
$P[] = ['n2', 'one statement over 100,000 bytes', 'limit check', [$big], null];
$q = implode(',', array_fill(0, 500, '?'));
$P[] = ['n3', 'IN (500 bound parameters)', 'SearchIndex.php:317-318 (chunks of 500)', [["SELECT COUNT(*) AS n FROM docs WHERE item_id IN ($q)", range(1, 500)]], null];

printf("%-3s %-8s %-8s %s\n", 'id', 'D1', 'SQLite3', 'feature / D1 detail');
$rows = [];
$d1->run(array_map(static fn ($s) => ['sql' => $s, 'params' => []], $schema));
$onLite($schema);
foreach ($P as [$id, $feature, $where, $stmts, $check]) {
    $a = $try($onD1, $stmts);
    $b = $try($onLite, $stmts);
    $verdict = static fn (array $x): string => !$x[0] ? 'ERROR' : ($check === null ? 'ok' : ($check($x[1]) ? 'ok' : 'DIFFERS'));
    $va = $verdict($a);
    $vb = $verdict($b);
    $detail = $a[0] ? $show($a[1]) : $a[1];
    printf("%-3s %-8s %-8s %s\n      D1: %s\n", $id, $va, $vb, $feature, $detail);
    $lb = $b[0] ? $show($b[1]) : $b[1];
    if ($lb !== $detail) {
        printf("      SQLite3: %s\n", $lb);
    }
}
// multi-statement strings (migration files run whole heredocs through PDO::exec)
foreach (['e1 DB.exec() with 2 statements' => fn () => $d1->exec("CREATE TABLE IF NOT EXISTS m1 (id INTEGER PRIMARY KEY);\nCREATE INDEX IF NOT EXISTS m1_i ON m1(id);"),
          'e3 VACUUM INTO through DB.exec()' => fn () => $d1->exec("VACUUM INTO '$tmp/v2.sqlite'"),
          'e2 prepare() with 2 statements' => fn () => $d1->query('SELECT 1 AS a; SELECT 2 AS b')] as $name => $f) {
    try {
        printf("%s: ok %s\n", $name, json_encode($f()));
    } catch (Throwable $e) {
        printf("%s: ERROR %s\n", $name, $e->getMessage());
    }
}
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
