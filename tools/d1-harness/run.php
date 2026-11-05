<?php

declare(strict_types=1);

// Runs the P4(a) cases against local D1 (wrangler dev --local). Usage: php run.php [worktree]
require ($argv[1] ?? '/workspace/coder2/wt-p4a') . '/vendor/autoload.php';
require __DIR__ . '/HttpBatchExecutor.php';

use Apotheke\Core\Database\BatchUnitOfWork;
use Apotheke\Core\Exceptions\WriteFailed;

$d1 = new HttpBatchExecutor();
$fails = 0;
$check = static function (string $case, bool $ok, string $note = '') use (&$fails): void {
    $fails += $ok ? 0 : 1;
    printf("%-4s %-60s %s\n", $ok ? 'PASS' : 'FAIL', $case, $note);
};
$one = static fn (string $sql, array $p = []): mixed => array_values($d1->query($sql, $p)[0] ?? [null])[0];
$reset = static function () use ($d1): void {
    $s = static fn (string $sql): array => ['sql' => $sql, 'params' => []];
    $d1->run(array_map($s, [
        'DROP TABLE IF EXISTS item_tags', 'DROP TABLE IF EXISTS tags', 'DROP TABLE IF EXISTS item_photos', 'DROP TABLE IF EXISTS items',
        'DROP TABLE IF EXISTS uow_guard', 'DROP TABLE IF EXISTS uow_refs', ...BatchUnitOfWork::SCHEMA,
        'CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL, image TEXT, updated_at TEXT)',
        'CREATE TABLE item_photos (id INTEGER PRIMARY KEY, item_id INTEGER NOT NULL REFERENCES items (id) ON DELETE CASCADE, file TEXT NOT NULL, position INTEGER NOT NULL)',
        'CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT NOT NULL, name_key TEXT NOT NULL UNIQUE)',
        'CREATE TABLE item_tags (item_id INTEGER NOT NULL REFERENCES items (id) ON DELETE CASCADE, tag_id INTEGER NOT NULL REFERENCES tags (id), PRIMARY KEY (item_id, tag_id))',
        "INSERT INTO items (id, name) VALUES (7, 'existing')",
    ]));
};
$err = static function (callable $fn): ?Throwable {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
};

$reset();
// (a) plain atomic batch, and a failing statement mid-batch
$u = new BatchUnitOfWork();
$item = $u->insert('items', ['name' => 'lamp', 'image' => null], 'add item');
$u->insert('item_photos', ['item_id' => $item, 'file' => 'a.jpg', 'position' => 0], 'add photo');
$u->update('items', ['image' => 'a.jpg'], ['id' => $item], 'cover');
$u->commit($d1);
$check('a1 atomic batch commits, Refs resolve', $one('SELECT image FROM items WHERE id = ?', [$item->id()]) === 'a.jpg'
    && $one('SELECT item_id FROM item_photos WHERE file = ?', ['a.jpg']) === $item->id(), 'item id ' . $item->id());
$u = new BatchUnitOfWork();
$u->insert('items', ['name' => 'half'], 'add item');
$u->insert('item_photos', ['item_id' => 999, 'file' => 'x.jpg', 'position' => 0], 'bad fk');
$e = $err(fn () => $u->commit($d1));
$check('a2 FK failure mid-batch keeps nothing', $e instanceof WriteFailed && $one("SELECT COUNT(*) FROM items WHERE name = 'half'") === 0,
    $e?->getPrevious()?->getMessage() ?? '');

// (b) guards abort the whole batch
$u = new BatchUnitOfWork();
$u->insert('items', ['name' => 'guarded'], 'add item');
$u->write('UPDATE items SET name = ? WHERE id = ? AND name = ?', ['x', 7, 'stale'], 'cas', 1);
$e = $err(fn () => $u->commit($d1));
$check('b1 expect guard (changes() <> 1) aborts batch', $e instanceof WriteFailed && $one("SELECT COUNT(*) FROM items WHERE name = 'guarded'") === 0
    && $one('SELECT COUNT(*) FROM uow_guard') === 0 && $one('SELECT COUNT(*) FROM uow_refs') === 0, $e?->getMessage() . ' / ' . ($e?->getPrevious()?->getMessage() ?? ''));
$u = new BatchUnitOfWork();
$u->write('UPDATE items SET name = ? WHERE id = ? AND name = ?', ['renamed', 7, 'existing'], 'cas', 1);
$u->insert('items', ['name' => 'ok'], 'add item');
$u->commit($d1);
$check('b2 passing expect guard commits, guard writes nothing', $one('SELECT name FROM items WHERE id = 7') === 'renamed' && $one('SELECT COUNT(*) FROM uow_guard') === 0);
$d1->run([['sql' => "INSERT INTO item_photos (item_id, file, position) VALUES (7, 'p1', 0), (7, 'p2', 1)", 'params' => []]]);
$u = new BatchUnitOfWork();
$u->assert('(SELECT COUNT(*) FROM item_photos WHERE item_id = ?) < ?', [7, 2], 'photo limit', static fn () => new DomainException('limit'));
$u->insert('item_photos', ['item_id' => 7, 'file' => 'p3', 'position' => 2], 'add photo');
$e = $err(fn () => $u->commit($d1));
$check('b3 assert guard aborts, failure named after rollback', $e instanceof DomainException && $one('SELECT COUNT(*) FROM item_photos WHERE item_id = 7') === 2, $e?->getMessage() ?? '');
// concurrency: 10 adds at once against a limit of 5 -> exactly 3 more get in (2 already there)
$d1->run([['sql' => 'DELETE FROM item_photos', 'params' => []]]);
$d1->run([['sql' => "INSERT INTO item_photos (item_id, file, position) VALUES (7, 'p1', 0), (7, 'p2', 1)", 'params' => []]]);
$procs = [];
for ($i = 0; $i < 10; $i++) {
    $code = '<?php require "' . ($argv[1] ?? '/workspace/coder2/wt-p4a') . '/vendor/autoload.php"; require "' . __DIR__ . '/HttpBatchExecutor.php";'
        . '$u = new Apotheke\Core\Database\BatchUnitOfWork(); $u->hold("UPDATE items SET updated_at = ? WHERE id = ?", [(string)microtime(true), 7], "hold");'
        . '$u->assert("(SELECT COUNT(*) FROM item_photos WHERE item_id = ?) < ?", [7, 5], "limit");'
        . '$u->insert("item_photos", ["item_id" => 7, "file" => "c' . $i . '", "position" => 9], "add");'
        . 'try { $u->commit(new HttpBatchExecutor()); echo "in"; } catch (Throwable $e) { echo "out"; }';
    $f = '/tmp/coder2-d1h-' . $i . '.php';
    file_put_contents($f, $code);
    $procs[] = [popen('php8.4 ' . $f, 'r'), $f];
}
$res = [];
foreach ($procs as [$p, $f]) {
    $res[] = stream_get_contents($p);
    pclose($p);
    unlink($f);
}
$in = count(array_filter($res, static fn ($r) => $r === 'in'));
$check('b4 10 concurrent guarded adds, limit 5 (2 present)', $in === 3 && $one('SELECT COUNT(*) FROM item_photos WHERE item_id = 7') === 5, "$in got in");

// (c) in-batch ids
$reset();
$u = new BatchUnitOfWork();
$refs = [];
for ($i = 0; $i < 3; $i++) {
    $refs[$i] = $u->insert('items', ['name' => "n$i"], 'add');
    $u->insert('item_photos', ['item_id' => $refs[$i], 'file' => "f$i", 'position' => 0], 'photo');
}
$u->commit($d1);
$ok = true;
foreach ($refs as $i => $r) {
    $ok = $ok && $one('SELECT name FROM items WHERE id = ?', [$r->id()]) === "n$i" && $one('SELECT item_id FROM item_photos WHERE file = ?', ["f$i"]) === $r->id();
}
$check('c1 uow_refs + last_insert_rowid() across interleaved inserts', $ok, implode(',', array_map(static fn ($r) => $r->id(), $refs)));
$raw = $d1->raw([['sql' => "INSERT INTO items (name) VALUES ('ret') RETURNING id", 'params' => []], ['sql' => 'SELECT last_insert_rowid() AS r', 'params' => []]]);
$retId = $raw['results'][0]['rows'][0]['id'] ?? null;
$check('c2 INSERT ... RETURNING returns the id inside a batch', is_int($retId) && $retId === $raw['results'][1]['rows'][0]['r'] && $raw['results'][0]['last_row_id'] === $retId, "id $retId, meta.last_row_id " . $raw['results'][0]['last_row_id']);
$tagSql = 'INSERT INTO tags (name, name_key) VALUES (?, ?) ON CONFLICT (name_key) DO NOTHING';
$raw = $d1->raw([
    ['sql' => $tagSql, 'params' => ['Red', 'red']],
    ['sql' => $tagSql, 'params' => ['RED', 'red']],
    ['sql' => 'SELECT changes() AS c, last_insert_rowid() AS r', 'params' => []],
    ['sql' => 'INSERT INTO item_tags (item_id, tag_id) SELECT ?, id FROM tags WHERE name_key = ?', 'params' => [$refs[0]->id(), 'red']],
    ['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL WHERE changes() <> ?', 'params' => [1]],
]);
$c = $raw['results'][2]['rows'][0];
$check('c3 natural-key upsert + INSERT..SELECT by name_key', $one('SELECT COUNT(*) FROM tags') === 1 && $one('SELECT COUNT(*) FROM item_tags') === 1,
    "after DO NOTHING: changes()={$c['c']}, last_insert_rowid() stays {$c['r']} (stale)");

// (d) bound parameters and json_each
$ph = static fn (int $n): string => implode(',', array_fill(0, $n, '?'));
$e100 = $err(fn () => $d1->query("SELECT COUNT(*) FROM items WHERE id IN ({$ph(100)})", range(1, 100)));
$e101 = $err(fn () => $d1->query("SELECT COUNT(*) FROM items WHERE id IN ({$ph(101)})", range(1, 101)));
$check('d1 100 bound parameters OK, 101 refused', $e100 === null && $e101 !== null, $e101?->getMessage() ?? 'no error at 101');
$e101b = $err(fn () => $d1->run([['sql' => 'SELECT 1', 'params' => []], ['sql' => "SELECT COUNT(*) FROM items WHERE id IN ({$ph(101)})", 'params' => range(1, 101)]]));
$check('d2 the limit is per statement inside a batch too', $e101b !== null, $e101b?->getMessage() ?? '');
$ids = range(1, 5000);
$n = $one('SELECT COUNT(*) FROM items WHERE id IN (SELECT value FROM json_each(?))', [json_encode($ids)]);
$check('d3 json_each(?) with 5,000 ids in one parameter', $n === $one('SELECT COUNT(*) FROM items'), "matched $n");
$u = new BatchUnitOfWork();
for ($i = 0; $i < 150; $i++) {
    $u->insert('items', ['name' => "bulk$i"], 'add');
}
$e = $err(fn () => $u->commit($d1));
$check('d4 BatchUnitOfWork with 150 Refs (ids read back via one json_each param)', $e === null && $one("SELECT COUNT(*) FROM items WHERE name LIKE 'bulk%'") === 150
    && $one('SELECT COUNT(*) FROM uow_refs') === 0, $e?->getMessage() ?? '');

// (e) the lock-taking first write
$raw = $d1->raw([['sql' => 'DELETE FROM items WHERE 0 = 1', 'params' => []], ['sql' => "INSERT INTO items (name) VALUES ('after lock')", 'params' => []]]);
$check('e1 no-op first write is accepted and harmless', $raw['results'][0]['changes'] === 0 && $one("SELECT COUNT(*) FROM items WHERE name = 'after lock'") === 1);
$t = microtime(true);
for ($i = 0; $i < 20; $i++) {
    $d1->run([['sql' => "INSERT INTO items (name) VALUES ('t')", 'params' => []]]);
}
$plain = (microtime(true) - $t) / 20;
$t = microtime(true);
for ($i = 0; $i < 20; $i++) {
    $d1->run([['sql' => 'DELETE FROM items WHERE 0 = 1', 'params' => []], ['sql' => "INSERT INTO items (name) VALUES ('t')", 'params' => []]]);
}
$locked = (microtime(true) - $t) / 20;
$check('e2 cost of the extra statement (local)', true, sprintf('%.1f ms vs %.1f ms per batch', $locked * 1000, $plain * 1000));

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
