<?php

declare(strict_types=1);

namespace Tests\Migrations;

use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\D1Pdo;
use Apotheke\Core\Database\D1Sql;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Migrations\D1MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PDO;
use RuntimeException;

require_once __DIR__ . '/D1SqliteStandIn.php';

/** P4-3 PR 3: the D1 migration dialect and the real migration set end to end. */
final class D1MigrationRunnerTest extends CIUnitTestCase
{
    private function realMigrationsDir(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    /** Records exec/query/run calls and returns canned rows, so no real workerd is needed. */
    private function fakeExecutor(array $queryRows = [], ?callable $onExec = null): D1HttpExecutor
    {
        return new D1HttpExecutor('http://d1.internal', static function (string $m, string $url, string $body) use ($queryRows, $onExec): string {
            $path = parse_url($url, PHP_URL_PATH);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if ($path === '/batch') {
                foreach ($payload as $statement) {
                    if ($onExec !== null) {
                        $onExec($statement['sql']);
                    }
                }
            }
            if ($path === '/query') {
                return json_encode(['ok' => true, 'rows' => $queryRows], JSON_THROW_ON_ERROR);
            }
            $n = is_array($payload) ? count($payload) : 1;

            return json_encode(['ok' => true, 'results' => array_fill(0, $n, ['rows' => []])], JSON_THROW_ON_ERROR);
        });
    }

    // ---------------------------------------------------------------------
    // M-1800 / L-1801 / L-1803: every real migration file, end to end.
    // ---------------------------------------------------------------------

    public function testEveryRealMigrationAppliesAndRerunIsNoOp(): void
    {
        $standIn = new D1SqliteStandIn();
        $runner = new D1MigrationRunner($this->realMigrationsDir());

        $applied = $runner->migrate($standIn);
        $available = $runner->available();
        $recorded = $runner->applied($standIn);

        $this->assertSame(array_keys($available), $applied, 'every real migration file applies');
        $this->assertSame(
            array_keys($available),
            array_keys($recorded),
            'every version is recorded in schema_migrations (0028 iterates; 0013/0022/0023 use cfg())',
        );

        // 0028 reads PRAGMA table_info(users) via foreach (M-1800) and adds session_epoch:
        // reaching this version at all proves iteration did not throw an uninitialized Error.
        $cols = $standIn->query('PRAGMA table_info(users)');
        $names = array_column($cols, 'name');
        $this->assertContains('session_epoch', $names, '0028 iterated table_info and added session_epoch');

        // A rerun records no new work and changes nothing.
        $rerun = $runner->migrate($standIn);
        $this->assertSame([], $rerun, 'rerun is a no-op');
        $this->assertSame(array_keys($available), array_keys($runner->applied($standIn)));
    }

    /** Real workerd: the complete migrations, not only the SQLite stand-in, and the rerun no-op. */
    public function testAllMigrationsRunOnLocalWorkerd(): void
    {
        if (!getenv('TEST_D1_URL')) {
            $this->markTestSkipped('TEST_D1_URL selects the local workerd harness.');
        }
        $d1 = new D1HttpExecutor(getenv('TEST_D1_URL'));
        // The local job's other real-D1 tests use shared migration-owned fixtures.
        // Clear only those fixtures: every migration must create its own object now.
        $d1->exec('DROP TABLE IF EXISTS uow_refs; DROP TABLE IF EXISTS uow_guard');
        $runner = new D1MigrationRunner($this->realMigrationsDir());
        $applied = $runner->migrate($d1);
        $this->assertSame(array_keys($runner->available()), $applied);
        $this->assertSame([], $runner->migrate($d1));
        $users = $d1->query('PRAGMA table_info(users)');
        $this->assertContains('session_epoch', array_column($users, 'name'));
        $this->assertSame(count($applied), count($runner->applied($d1)));
        $this->assertSame([['name' => 'search_fts'], ['name' => 'search_fts_v']],
            $d1->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('search_fts', 'search_fts_v') ORDER BY name"));
        $d1->exec('DROP TABLE schema_migrations');
    }

    public function testResultsAreIterableForeach(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor([['a' => 1], ['a' => 2]]));
        $st = $pdo->query('SELECT a FROM t');
        $seen = [];
        foreach ($st as $row) {
            $seen[] = $row['a'];
        }
        $this->assertSame([1, 2], $seen, 'foreach iterates a query result without an uninitialized-statement Error');
    }

    // ---------------------------------------------------------------------
    // N-1805: a rejected FTS statement takes the substring fallback.
    // ---------------------------------------------------------------------

    public function testRejectedFtsTakesSubstringFallback(): void
    {
        $standIn = new D1SqliteStandIn();
        // Simulate a SQLite build without FTS5: reject the fts5 statements.
        $standIn->reject = static fn (string $sql): bool => stripos($sql, 'fts5') !== false;

        $runner = new D1MigrationRunner($this->realMigrationsDir());
        $applied = $runner->migrate($standIn);

        $this->assertContains('0019', $applied, '0019 completes via the fallback');
        $docs = $standIn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='search_docs'");
        $this->assertNotSame([], $docs, 'search_docs is created (non-FTS part runs)');
        $fts = $standIn->query("SELECT name FROM sqlite_master WHERE name='search_fts'");
        $this->assertSame([], $fts, 'the rejected FTS table is absent');
    }

    public function testWriteFailedIsPdoExceptionCompatible(): void
    {
        $e = new WriteFailed('Database write failed: the batch');
        $this->assertInstanceOf(\PDOException::class, $e, 'catch (PDOException) catches the adapter failure');
    }

    // ---------------------------------------------------------------------
    // L-1802: classifier positives (must skip / must refuse).
    // ---------------------------------------------------------------------

    public function testConnectionPragmasAndUserVersionAreSkipped(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA user_version = 3');
        $pdo->exec("VACUUM INTO '/tmp/backup.sqlite'");
        $this->assertSame([], $ran, 'no connection/user_version/VACUUM statement reaches D1');
        $this->assertCount(6, $pdo->skippedStatements());
    }

    public function testQuotedSchemaQualifiedAndCommentPrefixedUserVersionSkipped(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('PRAGMA "user_version" = 5');
        $pdo->exec('PRAGMA `user_version` = 5');
        $pdo->exec('PRAGMA [user_version] = 5');
        $pdo->exec('PRAGMA main.user_version = 5');
        $pdo->exec('PRAGMA "main"."user_version" = 5');
        $pdo->exec("/* leading */ PRAGMA user_version = 5");
        $this->assertSame([], $ran, 'quoted/schema-qualified/comment-prefixed user_version are all skipped');
        $this->assertCount(6, $pdo->skippedStatements());
    }

    public function testDriverFailureTextMatchesProduction(): void
    {
        $standIn = new D1SqliteStandIn();
        try {
            $standIn->exec('CREATE TABLE');
            $this->fail('the invalid statement must fail');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: the batch', $e->getMessage());
            $this->assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
        $pdo = new D1Pdo($standIn);
        try {
            $pdo->query('SELECT * FROM missing_table');
            $this->fail('the invalid query must fail');
        } catch (\PDOException $e) {
            $this->assertSame('Database write failed: the batch', $e->getMessage());
        }
    }

    public function testSkippedPragmaAnswersPerPragma(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $uv = $pdo->query('PRAGMA user_version')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['user_version' => 0], $uv, 'user_version reports 0');
        $fk = $pdo->query('PRAGMA foreign_keys')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['foreign_keys' => 1], $fk, 'foreign_keys reports 1 (D1 enforces FKs)');
        $quoted = $pdo->query('PRAGMA "main"."foreign_keys"')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['foreign_keys' => 1], $quoted, 'quoted schema-qualified foreign_keys reports 1');
        foreach (['journal_mode', 'busy_timeout', 'synchronous'] as $name) {
            $this->assertSame([$name => null], $pdo->query('PRAGMA ' . $name)->fetch(PDO::FETCH_ASSOC));
        }
    }

    public function testSkippedPragmaMixedWithRealSqlRunsTheRest(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('PRAGMA user_version = 3; CREATE TABLE t (id INT);');
        $this->assertSame(['CREATE TABLE t (id INT)'], $ran, 'the pragma is stripped and the rest runs');
        $this->assertCount(1, $pdo->skippedStatements());
    }

    public function testVacuumIntoWithSemicolonInPathIsSkipped(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec("VACUUM INTO '/tmp/a;b.sqlite'");
        $this->assertSame([], $ran, 'VACUUM INTO with a semicolon inside the quoted path is skipped whole');
        $this->assertCount(1, $pdo->skippedStatements());
    }

    // ---------------------------------------------------------------------
    // L-1802: parent-DROP positives on exec, query and prepare.
    // ---------------------------------------------------------------------

    /** @return iterable<string,array{string}> */
    public static function parentDropProvider(): iterable
    {
        yield 'plain' => ['DROP TABLE items'];
        yield 'if exists' => ['DROP TABLE IF EXISTS items'];
        yield 'double-quoted' => ['DROP TABLE "items"'];
        yield 'backtick' => ['DROP TABLE `items`'];
        yield 'bracket' => ['DROP TABLE [items]'];
        yield 'schema-qualified' => ['DROP TABLE main.items'];
        yield 'quoted schema-qualified' => ['DROP TABLE "main"."items"'];
        yield 'temp-qualified' => ['DROP TABLE temp.items'];
        yield 'comment-prefixed' => ["-- drop it\nDROP TABLE items"];
        yield 'after other sql' => ['SELECT 1; DROP TABLE items'];
    }

    #[DataProvider('parentDropProvider')]
    public function testParentDropRefusedOnExec(string $sql): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $this->expectException(RuntimeException::class);
        $pdo->exec($sql);
    }

    public function testParentDropRefusedOnQuery(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $this->expectException(RuntimeException::class);
        $pdo->query('DROP TABLE main."items"');
    }

    public function testParentDropRefusedOnPreparedExecute(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $stmt = $pdo->prepare('DROP TABLE "items"');
        $this->expectException(RuntimeException::class);
        $stmt->execute();
    }

    // ---------------------------------------------------------------------
    // L-1802: negatives (must NOT fire / must run).
    // ---------------------------------------------------------------------

    public function testParentDropDoesNotFireInsideCommentsOrLiterals(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        // A literal and a comment that mention DROP TABLE items must not be refused.
        $pdo->exec("INSERT INTO log (msg) VALUES ('DROP TABLE items')");
        $pdo->exec("-- DROP TABLE items\nCREATE TABLE t (id INT)");
        $pdo->exec("/* DROP TABLE items */ CREATE TABLE u (id INT)");
        $this->assertCount(3, $ran, 'DROP TABLE inside a literal or comment is not a parent drop');
    }

    public function testNonParentDropIsAllowed(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('DROP TABLE IF EXISTS items_backup');
        $this->assertSame(['DROP TABLE IF EXISTS items_backup'], $ran, 'a non-parent table drop is allowed');
    }

    public function testTokenizerSplitsTriggerBodyWhole(): void
    {
        $sql = "CREATE TRIGGER trg AFTER INSERT ON t BEGIN UPDATE t SET id = 1; END; CREATE TABLE x (id INT)";
        $parts = D1Sql::split($sql);
        $this->assertCount(2, $parts, 'the trigger body semicolon does not split');
        $this->assertStringContainsString('CREATE TRIGGER', $parts[0]);
        $this->assertStringContainsString('CREATE TABLE x', $parts[1]);
    }
    public function testTriggerInnerEndAndUnterminatedDropAreHandled(): void
    {
        $inner = 'CREATE TRIGGER trg AFTER INSERT ON t BEGIN UPDATE t SET id = CASE WHEN id > 0 THEN id ELSE 0 END; END; DROP TABLE items';
        $this->expectException(RuntimeException::class);
        (new D1Pdo($this->fakeExecutor()))->exec($inner);
    }

    public function testUnterminatedTriggerCannotHideParentDrop(): void
    {
        $this->expectException(RuntimeException::class);
        (new D1Pdo($this->fakeExecutor()))->exec('CREATE TRIGGER trg AFTER INSERT ON t BEGIN UPDATE t SET id = 1; DROP TABLE items');
    }

    public function testTokenizerKeepsCaseAndTriggerConditionsInsideBody(): void
    {
        $case = 'CREATE TRIGGER trg AFTER INSERT ON t BEGIN UPDATE t SET id = CASE WHEN id > 0 THEN id ELSE 0 END; END; DROP TABLE items';
        $condition = 'CREATE TRIGGER trg AFTER INSERT ON t WHEN (CASE WHEN NEW.id > 0 THEN 1 ELSE 0 END) = 1 BEGIN UPDATE t SET id = 1; END; DROP TABLE items';
        foreach ([$case, $condition] as $sql) {
            $parts = D1Sql::split($sql);
            $this->assertCount(2, $parts);
            $this->assertStringStartsWith('CREATE TRIGGER', $parts[0]);
            $this->assertSame('DROP TABLE items', $parts[1]);
        }
    }

    // ---------------------------------------------------------------------
    // M-1846: D1Pdo queues writes in a transaction and refuses mid-queue reads.
    // ---------------------------------------------------------------------

    public function testTransactionSendsQueuedWritesAsOneBatch(): void
    {
        $batches = [];
        $pdo = new D1Pdo(new D1HttpExecutor('http://d1.internal', static function (string $m, string $url, string $body) use (&$batches): string {
            $path = parse_url($url, PHP_URL_PATH);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if ($path === '/batch') {
                $batches[] = array_column($payload, 'sql');
            }
            $n = is_array($payload) ? count($payload) : 1;

            return json_encode(['ok' => true, 'results' => array_fill(0, $n, ['rows' => []])], JSON_THROW_ON_ERROR);
        }));
        $pdo->beginTransaction();
        $pdo->exec('CREATE TABLE t (x INTEGER)');
        $pdo->exec('CREATE TABLE u (x INTEGER)');
        $this->assertSame([], $batches, 'writes stay queued until commit');
        $pdo->commit();
        $this->assertSame([['CREATE TABLE t (x INTEGER)', 'CREATE TABLE u (x INTEGER)']], $batches);
        $this->assertFalse($pdo->inTransaction());
    }

    public function testAReadAfterAQueuedWriteIsRefusedAndNothingIsSent(): void
    {
        $batches = [];
        $pdo = new D1Pdo(new D1HttpExecutor('http://d1.internal', static function (string $m, string $url, string $body) use (&$batches): string {
            $path = parse_url($url, PHP_URL_PATH);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if ($path === '/batch') {
                $batches[] = array_column($payload, 'sql');
            }
            $n = is_array($payload) ? count($payload) : 1;

            return json_encode(['ok' => true, 'results' => array_fill(0, $n, ['rows' => [['x' => 1]]])], JSON_THROW_ON_ERROR);
        }));
        $pdo->beginTransaction();
        $pdo->exec('CREATE TABLE t (x INTEGER)');
        try {
            $pdo->query('SELECT x FROM t');
            $this->fail('a read after a queued write must be refused');
        } catch (\LogicException $e) {
            $this->assertSame('D1 cannot read after a queued write in the same transaction; use a unit of work.', $e->getMessage());
        }
        $this->assertSame([], $batches, 'the queued write was not sent');
        $pdo->rollBack();
    }

    public function testReturningAndLastInsertIdAreRefusedWhileQueued(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $pdo->beginTransaction();
        $pdo->exec('CREATE TABLE t (x INTEGER)');
        try {
            $pdo->query('UPDATE t SET x = 1 RETURNING x');
            $this->fail('RETURNING inside a transaction must be refused');
        } catch (\LogicException $e) {
            $this->assertSame('D1 cannot return rows from a write inside a transaction; use a unit of work.', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('D1 does not know the insert id while writes are queued or after a batch; use a unit of work.');
        $pdo->lastInsertId();
    }

    public function testPreviewReadsSeeQueuedWritesAndStillCommitAsOneBatch(): void
    {
        $standIn = new D1SqliteStandIn();
        $standIn->run([['sql' => 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)', 'params' => []]]);
        $standIn->run([['sql' => "INSERT INTO t (id, name) VALUES (1, 'a')", 'params' => []]]);
        $batches = $standIn->batches;
        $pdo = new D1Pdo($standIn);
        $pdo->beginTransaction();
        $pdo->previewReads();
        $pdo->exec("ALTER TABLE t ADD COLUMN sort_key TEXT NOT NULL DEFAULT ''");
        $rows = $pdo->query('SELECT id, name FROM t')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame([1 => 'a'], $rows);
        $pdo->prepare('UPDATE t SET sort_key = ? WHERE id = ?')->execute(['a', 1]);
        $this->assertSame($batches, $standIn->batches, 'writes stay queued until commit');
        $pdo->commit();
        $this->assertSame(['a'], array_column($standIn->query('SELECT sort_key FROM t', []), 'sort_key'));
        $last = $standIn->batches[count($standIn->batches) - 1];
        $this->assertSame("ALTER TABLE t ADD COLUMN sort_key TEXT NOT NULL DEFAULT ''", $last[0]);
        $this->assertSame('UPDATE t SET sort_key = ? WHERE id = ?', $last[1]);
        $this->assertCount(2, $last);
    }

    public function testPreviewSkipsD1InternalTables(): void
    {
        $standIn = new D1SqliteStandIn();
        $this->assertSame([['name' => '_cf_METADATA']],
            $standIn->query("SELECT name FROM sqlite_master WHERE name LIKE '%METADATA'", []), 'the stand-in lists it like D1');
        // AUTOINCREMENT adds sqlite_sequence, which SQLite will not let the preview create.
        $standIn->run([['sql' => 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)', 'params' => []]]);
        $standIn->run([['sql' => 'INSERT INTO t (id) VALUES (1)', 'params' => []]]);
        $pdo = new D1Pdo($standIn);
        $pdo->beginTransaction();
        $pdo->previewReads();
        $pdo->exec('INSERT INTO t (id) VALUES (2)');
        $this->assertSame([['id' => 1], ['id' => 2]], $pdo->query('SELECT id FROM t ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        $pdo->commit();
    }

    /** N-1850: like local D1, a _cf_ table is refused in any case or quoting, on every path. */
    public static function internalNameStatements(): array
    {
        return [
            'bare' => ['query', 'SELECT * FROM _cf_METADATA'],
            'double quoted' => ['query', 'SELECT * FROM "_cf_METADATA"'],
            'upper case' => ['query', 'SELECT * FROM "_CF_METADATA"'],
            'mixed case' => ['query', 'SELECT * FROM _Cf_METADATA'],
            'backticks' => ['query', 'SELECT * FROM `_cf_METADATA`'],
            'brackets' => ['query', 'SELECT * FROM [_cf_METADATA]'],
            'other _cf_ name' => ['query', 'CREATE TABLE _cf_user (a)'],
            'name after a literal' => ['query', "SELECT '_cf_' AS s FROM _cf_METADATA"],
            'batch' => ['run', 'SELECT * FROM "_CF_METADATA"'],
            'exec' => ['exec', 'SELECT 1; SELECT * FROM "_CF_METADATA"'],
            // N-1898: a ' or -- or /* inside a quoted name starts no string or comment.
            "' in \"name\"" => ['query', "SELECT \"a'\" FROM _cf_METADATA WHERE key='b'"],
            "' in `name`" => ['query', "SELECT key AS `a'` FROM _cf_METADATA WHERE key='b'"],
            "' in [name]" => ['query', "SELECT key AS [a'] FROM _cf_METADATA WHERE key='b'"],
            '-- in "name"' => ['query', 'SELECT key AS "a--" FROM _cf_METADATA'],
            '-- in `name`' => ['query', 'SELECT key AS `a--` FROM _cf_METADATA'],
            '-- in [name]' => ['query', 'SELECT key AS [a--] FROM _cf_METADATA'],
            '/* in "name"' => ['query', 'SELECT key AS "a/*" FROM _cf_METADATA'],
            "quoted _cf_ after \"a'\"" => ['query', "SELECT key AS \"a'\" FROM \"_cf_METADATA\" WHERE key='b'"],
            "quoted _cf_ after [a']" => ['query', "SELECT key AS [a'] FROM [_cf_METADATA]"],
            'quoted _cf_ after `a--`' => ['exec', 'SELECT key AS `a--` FROM `_cf_METADATA`'],
        ];
    }

    #[DataProvider('internalNameStatements')]
    public function testStandInRefusesD1InternalNames(string $path, string $sql): void
    {
        $standIn = new D1SqliteStandIn();
        $this->expectException(WriteFailed::class);
        match ($path) {
            'query' => $standIn->query($sql, []),
            'run' => $standIn->run([['sql' => $sql, 'params' => []]]),
            'exec' => $standIn->exec($sql),
        };
    }

    /** N-1850: like local D1, _cf_ in a string literal, a comment or a longer name is not refused. */
    public function testStandInAcceptsCfTextThatIsNotAName(): void
    {
        $standIn = new D1SqliteStandIn();
        $this->assertSame([['s' => '_cf_METADATA']], $standIn->query("SELECT '_cf_METADATA' AS s", []));
        $this->assertSame([['name' => '_cf_METADATA']],
            $standIn->query("SELECT name FROM sqlite_master WHERE name LIKE '_cf_%'", []));
        $this->assertSame([['1' => 1]], $standIn->query("SELECT 1 AS \"1\" -- _cf_METADATA", []));
        $this->assertSame([['1' => 1]], $standIn->query('SELECT 1 AS "1" /* _cf_METADATA */', []));
        $this->assertSame(['count' => 3], $standIn->exec(
            "CREATE TABLE items_cf_extra (a); CREATE TABLE items\$_cf_extra (a); INSERT INTO items_cf_extra VALUES ('_cf_x')",
        ));
    }

    /** N-1898: like local D1, names quoted with ' -- or /* inside still leave later literals alone. */
    public function testStandInAcceptsCfLiteralsAfterQuotedNames(): void
    {
        $standIn = new D1SqliteStandIn();
        $standIn->query("CREATE TABLE t (\"a'\" TEXT, [b--] TEXT, `c/*` TEXT)", []);
        $standIn->run([['sql' => "INSERT INTO t VALUES ('_cf_1', '_cf_2', '_cf_3')", 'params' => []]]);
        $this->assertSame([["a'" => '_cf_1', 'b--' => '_cf_2', 'c/*' => '_cf_3']], $standIn->query(
            "SELECT \"a'\", [b--], `c/*` FROM t WHERE \"a'\" = '_cf_1' AND [b--] = '_cf_2' AND `c/*` LIKE '_cf_%'",
            [],
        ));
    }

}
