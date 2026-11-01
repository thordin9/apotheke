<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\DatabaseUnavailable;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Migrations\PdoMigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;
use Tests\Support\UowSchema;

/**
 * The executor adapter's default, ConnectionExecutor (ADR-002, P4-2), on
 * every driver: an atomic batch, failure codes that pass no database
 * message on, and one list parameter for long id lists (D1 allows 100
 * bound parameters per statement).
 */
final class SqlExecutorTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function testABatchRunsInOrderAndReturnsRowsPerStatement(string $driver): void
    {
        [$this->database, $db] = UowSchema::create($driver);
        $exec = new ConnectionExecutor($db);
        $this->assertSame($driver, $exec->dialect()->name);
        $this->assertSame($db, $exec->connection());
        $out = $exec->run([
            ['sql' => 'INSERT INTO uw_parent (name) VALUES (?)', 'params' => ['a']],
            ['sql' => 'INSERT INTO uw_parent (name) VALUES (?)', 'params' => ['b']],
            ['sql' => 'SELECT name FROM uw_parent ORDER BY id', 'params' => []],
        ]);
        $this->assertSame([[], [], [['name' => 'a'], ['name' => 'b']]], $out);
        $this->assertSame([['n' => 2]], array_map(static fn (array $r): array => ['n' => (int)$r['n']], $exec->query('SELECT COUNT(*) AS n FROM uw_parent WHERE name <> ?', ['c'])));
        $this->assertSame(0, (int)$db->transDepth);
    }

    /** A failing statement is a plain batch failure, a guard has its own code, and neither passes the database's message on. */
    #[DataProvider('drivers')]
    public function testAFailedStatementFailsTheWholeBatch(string $driver): void
    {
        [$this->database, $db] = UowSchema::create($driver);
        $exec = new ConnectionExecutor($db);
        $cases = [
            [[['sql' => 'INSERT INTO uw_child (parent_id, name) VALUES (?, ?)', 'params' => [999, 'secret-value']]], 0, 'the batch'],
            [[['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL', 'params' => []]], BatchExecutor::GUARD_FAILED, 'a guard in the unit'],
        ];
        foreach ($cases as [$tail, $code, $step]) {
            try {
                $exec->run([['sql' => 'INSERT INTO uw_parent (name) VALUES (?)', 'params' => ['kept?']], ...$tail]);
                $this->fail('the batch must fail');
            } catch (WriteFailed $e) {
                $this->assertSame($code, $e->getCode());
                $this->assertSame('Database write failed: ' . $step, $e->getMessage());
            }
        }
        try {
            $exec->query('SELECT nope FROM uw_parent WHERE name = ?', ['secret-value']);
            $this->fail('a failed read must throw');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: read', $e->getMessage());
        }
        $this->assertSame([], Writes::rows($db, 'SELECT * FROM uw_parent'), 'nothing of either batch is kept');
        $this->assertSame(0, (int)$db->transDepth);
    }

    /**
     * N-1621: only the guard's own NOT NULL violation is GUARD_FAILED. A
     * stored value that names the guard table, or even spells out the
     * guard's message, in another error (PostgreSQL puts row values in its
     * DETAIL line and in some primary messages) is an ordinary failure, with
     * DBDebug off (the error array) and on (the exception).
     */
    #[DataProvider('drivers')]
    public function testOnlyTheGuardItselfIsAGuardFailure(string $driver): void
    {
        [$this->database, $db] = UowSchema::create($driver);
        $db->query('CREATE TABLE uw_unique (v TEXT NOT NULL UNIQUE, n INTEGER NOT NULL CHECK (n > 0))');
        $forged = 'null value in column "ok" of relation "' . 'uow_guard' . '" violates not-null constraint';
        $db->query('INSERT INTO uw_unique (v, n) VALUES (?, ?)', ['uow_guard', 1]);
        $db->query('INSERT INTO uw_unique (v, n) VALUES (?, ?)', [$forged, 1]);
        $cases = [
            'the guard' => ['INSERT INTO uow_guard (ok) SELECT NULL', [], BatchExecutor::GUARD_FAILED],
            'the guard, bound' => ['INSERT INTO uow_guard (ok) SELECT CASE WHEN ? = 1 THEN 1 END', [0], BatchExecutor::GUARD_FAILED],
            'a unique value naming the table' => ['INSERT INTO uw_unique (v, n) VALUES (?, ?)', ['uow_guard', 2], 0],
            'a unique value spelling the message' => ['INSERT INTO uw_unique (v, n) VALUES (?, ?)', [$forged, 2], 0],
            'a check with the value' => ['INSERT INTO uw_unique (v, n) VALUES (?, ?)', [$forged . "\n" . $forged, 0], 0],
            'a cast of the message' => ['SELECT CAST(? AS INTEGER) AS n FROM uw_unique WHERE n = 1 AND v = ? AND CAST(? AS INTEGER) = 1', [$forged, $forged, $forged], $driver === 'pgsql' ? 0 : null],
            'another not-null column' => ['INSERT INTO uw_parent (name) SELECT NULL', [], 0],
            'the guard table, another error' => ['INSERT INTO uow_guard (ok) SELECT CAST(? AS INTEGER) WHERE CAST(? AS INTEGER) = 1', [$forged, $forged], $driver === 'pgsql' ? 0 : null],
        ];
        foreach ([false, true] as $debug) {
            (new \ReflectionProperty($db, 'DBDebug'))->setValue($db, $debug);
            $exec = new ConnectionExecutor($db);
            foreach ($cases as $name => [$sql, $params, $code]) {
                try {
                    $exec->run([['sql' => $sql, 'params' => $params]]);
                    $this->assertNull($code, $name . ' must fail');
                } catch (WriteFailed $e) {
                    $this->assertSame($code, $e->getCode(), $name . ($debug ? ', DBDebug on' : ''));
                    $this->assertSame('Database write failed: ' . ($code === BatchExecutor::GUARD_FAILED ? 'a guard in the unit' : 'the batch'), $e->getMessage());
                }
            }
        }
        $this->assertSame(0, (int)$db->transDepth);
    }

    /**
     * LocalBatchExecutor holds every statement to D1's limits before any
     * runs (no transaction statement, undocumented pragma or sqlite_*()
     * call, at most 100 parameters), has no connection, and keeps a failed
     * batch out of an open transaction, which carries on.
     */
    #[DataProvider('drivers')]
    public function testTheLocalBatchExecutorKeepsToD1sLimits(string $driver): void
    {
        [$this->database, $db] = UowSchema::create($driver);
        $exec = new LocalBatchExecutor($db);
        $this->assertNull($exec->connection());
        $this->assertSame($driver, $exec->dialect()->name);
        $ok = ['sql' => 'INSERT INTO uw_parent (name) VALUES (?)', 'params' => ['kept?']];
        $refused = [
            ['BEGIN', []], [' savepoint x', []], ['RELEASE x', []], ['ROLLBACK', []], ['COMMIT', []], ['END', []],
            ['PRAGMA journal_mode', []], ['SELECT sqlite_version()', []],
            ['SELECT ' . implode(' + ', array_fill(0, 101, '?')) . ' AS n', array_fill(0, 101, 1)],
        ];
        foreach ($refused as [$sql, $params]) {
            try {
                $exec->run([$ok, ['sql' => $sql, 'params' => $params]]);
                $this->fail($sql . ' must be refused');
            } catch (LogicException) {
            }
        }
        try {
            $exec->query('PRAGMA journal_mode');
            $this->fail('a pragma read must be refused');
        } catch (LogicException) {
        }
        $this->assertSame([['n' => 100]], array_map(static fn (array $r): array => ['n' => (int)$r['n']], $exec->run([['sql' => 'SELECT ' . implode(' + ', array_fill(0, 100, '?')) . ' AS n', 'params' => array_fill(0, 100, 1)]])[0]));
        Writes::transaction($db, function () use ($db, $exec, $ok): void {
            $db->query('INSERT INTO uw_parent (name) VALUES (?)', ['outer']);
            $this->assertCount(1, Writes::rows($db, 'SELECT name FROM uw_parent'), 'a read just before the guard (its result is released first)');
            try {
                $exec->run([$ok, ['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL', 'params' => []]]);
                $this->fail('the guard must fail the batch');
            } catch (WriteFailed $e) {
                $this->assertSame(BatchExecutor::GUARD_FAILED, $e->getCode());
            }
            $exec->run([['sql' => 'INSERT INTO uw_parent (name) VALUES (?)', 'params' => ['batch']]]);
        });
        $this->assertSame(['outer', 'batch'], array_column(Writes::rows($db, 'SELECT name FROM uw_parent ORDER BY id'), 'name'));
    }

    /** @return iterable<string,array{0:string,1:string,2:list<scalar|null>,3:bool}> driver, statement, params, refused */
    public static function batchStatements(): iterable
    {
        $insert = 'INSERT INTO uw_parent (name) VALUES (?)';
        $trigger = 'CREATE TRIGGER IF NOT EXISTS uw_t AFTER INSERT ON uw_parent BEGIN ';
        $refused = [
            'both' => [
                'begin' => 'BEGIN', 'begin after a line comment' => "-- note\nBEGIN", 'commit after a block comment' => '/* note */ COMMIT',
                'rollback after both comments' => "/* a */ -- b\n  ROLLBACK", 'end' => 'END', 'savepoint' => ' savepoint x', 'release' => 'RELEASE x',
                'start transaction' => 'START TRANSACTION', 'abort' => 'ABORT', 'pragma not documented' => 'PRAGMA journal_mode',
                'pragma not documented, after a comment' => '/**/PRAGMA writable_schema = on', 'pragma optimize with a value' => 'PRAGMA optimize(-1)',
                'pragma with a second value' => 'PRAGMA foreign_keys = on off', 'attach' => "ATTACH DATABASE ':memory:' AS x",
                'detach' => 'DETACH DATABASE x', 'vacuum' => 'VACUUM', 'analyze' => 'ANALYZE', 'reindex' => 'REINDEX',
                'sqlite function' => 'SELECT sqlite_version()', 'sqlite function, space' => "SELECT sqlite_version \n()",
                'sqlite function, comment' => 'SELECT sqlite_version/* x */()', 'sqlite function, quoted' => 'SELECT "sqlite_version"()',
                'sqlite function next to sqlite_sequence' => 'SELECT seq, sqlite_version() FROM sqlite_sequence',
                'second statement' => 'SELECT 1; SELECT 2', 'second statement after a literal' => "SELECT 'a;b'; BEGIN",
                'second statement after a quoted name' => 'SELECT 1 AS "a;b"; COMMIT', 'nested comment' => '/* a /* b */ ; */ COMMIT',
                'two trailing semicolons' => 'SELECT 1;;', 'only a comment' => '-- nothing', 'empty' => '',
                'unclosed block comment' => 'SELECT 1 /* x', 'unclosed literal' => "SELECT 'a", 'unclosed quoted name' => 'SELECT 1 AS "a',
                'trigger, then a commit' => $trigger . 'SELECT 1; END; COMMIT', 'trigger body with a commit' => $trigger . 'SELECT 1; COMMIT; END',
                'trigger without a body' => $trigger . 'END; COMMIT', 'trigger, empty body statement' => $trigger . 'SELECT 1;; END',
                'not a trigger' => 'CREATE TABLE uw_x (a INT); COMMIT', 'trigger named begin' => 'CREATE TRIGGER "begin" AFTER INSERT ON uw_parent COMMIT; END',
            ],
            'sqlite' => [
                'a quote in a [ name' => "SELECT 1 AS [a'b]; COMMIT", 'bracketed sqlite function' => 'SELECT [sqlite_version]()',
                'backtick sqlite function' => 'SELECT `sqlite_version`()', 'a semicolon in a backtick name' => 'SELECT 1 AS `a;b`; COMMIT',
                'unclosed [ name' => 'SELECT 1 AS [a', 'unclosed backtick name' => 'SELECT 1 AS `a',
            ],
            'pgsql' => [
                'an escaped quote in an E literal' => "SELECT E'\\'', 1; COMMIT; SELECT ''", 'a quote in a dollar quote' => 'SELECT $$\'$$; COMMIT; SELECT $$\'$$',
                'a quote in a tagged dollar quote' => 'SELECT $t$\'$t$; COMMIT; SELECT $t$\'$t$', 'unclosed dollar quote' => 'SELECT $$a',
                'a backtick' => 'SELECT 1 AS `a`', 'unclosed E literal' => "SELECT E'a\\'",
            ],
        ];
        $allowed = [
            'both' => [
                'an insert' => [$insert, ['a']],
                'one trailing semicolon' => [$insert . ';', ['a']],
                'a trailing semicolon and comment' => [$insert . " ; -- done; really\n", ['a']],
                'a literal holding refused words' => ["INSERT INTO uw_parent (name) VALUES ('BEGIN; VACUUM; sqlite_version() -- /*')", []],
                'a doubled quote' => ["INSERT INTO uw_parent (name) VALUES ('it''s; ATTACH')", []],
                'a backslash in a standard literal' => ["SELECT 'a\\' AS a", []],
                'a leading comment' => ["/* why */ -- and\n" . $insert, ['a']],
                'a semicolon in a comment' => ["SELECT name FROM uw_parent /* ; */ WHERE name = 'end' -- ;", []],
                'a quoted name' => ['SELECT name AS "begin;end" FROM uw_parent', []],
                '100 parameters' => ['SELECT ' . implode(' + ', array_fill(0, 100, '?')) . ' AS n', array_fill(0, 100, 1)],
            ],
            'sqlite' => [
                'bracket and backtick names' => ['SELECT 1 AS [a;b], 2 AS `c;``d`', []],
                'pragma foreign_keys' => ['PRAGMA foreign_keys', []], 'pragma defer_foreign_keys' => ['PRAGMA defer_foreign_keys = on', []],
                'pragma table_info' => ["PRAGMA table_info('uw_parent')", []], 'pragma table_info, quoted' => ['PRAGMA main.table_info("uw_parent")', []],
                'pragma optimize' => ['PRAGMA optimize', []],
                'a write to sqlite_sequence' => ["UPDATE sqlite_sequence SET seq = seq WHERE name = 'uw_seq'", []],
                'an insert into sqlite_sequence' => ["INSERT INTO sqlite_sequence(name, seq) SELECT 'uw_none', 0 WHERE 0", []],
                'a trigger' => [$trigger . "UPDATE uw_parent SET note = 'a;b' WHERE id = NEW.id; DELETE FROM uw_child WHERE 0; END;", []],
                'a temp trigger' => ['CREATE TEMP TRIGGER IF NOT EXISTS uw_tt AFTER DELETE ON uw_parent BEGIN SELECT 1; END', []],
            ],
            'pgsql' => [
                'an escaped quote in an E literal' => ["SELECT E'it\\'s; ATTACH' AS a, e'\\\\' AS b", []],
                'dollar quotes' => ['SELECT $$a; COMMIT$$ AS a, $t$ $$ ; $t$ AS b', []],
                'a nested comment' => ['SELECT 1 /* a /* ; */ b */ AS a', []],
            ],
        ];
        foreach (TestDatabase::drivers() as $driver) {
            foreach (['both', $driver] as $set) {
                foreach ($refused[$set] as $name => $sql) {
                    yield $driver . ' refused: ' . $name => [$driver, $sql, [], true];
                }
                foreach ($allowed[$set] as $name => [$sql, $params]) {
                    yield $driver . ' allowed: ' . $name => [$driver, $sql, $params, false];
                }
            }
            yield $driver . ' refused: 101 parameters' => [$driver, 'SELECT ' . implode(' + ', array_fill(0, 101, '?')) . ' AS n', array_fill(0, 101, 1), true];
        }
    }

    /**
     * L-1637, L-1643, N-1644, N-1646: LocalBatchExecutor reads a statement
     * with each dialect's quoting and comments, so a comment or quote hides
     * no refused word or second statement, a literal is only a value, an
     * unreadable statement is refused, and what D1 documents is let through.
     *
     * @param list<scalar|null> $params
     */
    #[DataProvider('batchStatements')]
    public function testTheLocalBatchExecutorModelsD1sStatementRules(string $driver, string $sql, array $params, bool $refused): void
    {
        [$this->database, $db] = UowSchema::create($driver);
        if ($driver === 'sqlite') {
            $db->query('CREATE TABLE uw_seq (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        }
        $exec = new LocalBatchExecutor($db);
        foreach (['run' => static fn () => $exec->run([['sql' => $sql, 'params' => $params]]), 'query' => static fn () => $exec->query($sql, $params)] as $call => $f) {
            try {
                $f();
                $this->assertFalse($refused, $call . ' must refuse it');
            } catch (LogicException $e) {
                $this->assertTrue($refused, $call . ' refused an allowed statement: ' . $e->getMessage());
                $this->assertStringStartsWith('D1 ', $e->getMessage());
            }
        }
        $this->assertSame(0, (int)$db->transDepth);
    }

    /** N-1623: a text list with invalid UTF-8 cannot be bound; that is a WriteFailed, not a JsonException. */
    public function testAListWithInvalidUtf8IsAWriteFailure(): void
    {
        $this->assertSame('["a","b"]', Dialect::list(['a', 'b']));
        try {
            Dialect::list(['ok', 'bad ' . chr(0xC3) . chr(0x28)]);
            $this->fail('invalid UTF-8 must not bind');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: a list value is not valid UTF-8', $e->getMessage());
            $this->assertNull($e->getPrevious(), 'the value is not passed on');
        }
    }

    /** N-1623: SQLite older than 3.38 (no built-in json_each) is refused with the versions only. */
    public function testAnSqliteOlderThan338IsRefused(): void
    {
        foreach (['3.38.0', '3.38.5', '3.46.1', '3.100.0', (string)\SQLite3::version()['versionString']] as $ok) {
            Dialect::requireSqlite($ok);
        }
        foreach (['3.37.2', '3.31.1', '3.9.0'] as $old) {
            try {
                Dialect::requireSqlite($old);
                $this->fail($old . ' must be refused');
            } catch (DatabaseUnavailable $e) {
                $this->assertSame('SQLite 3.38.0 or newer is required (JSON functions); this PHP has SQLite ' . $old . '.', $e->getMessage());
            }
        }
    }

    /**
     * N-1629: the migration runner checks SQLite before it opens the file, so
     * a refused migrate leaves no empty database behind, and a runner that
     * cannot ask for the version (D1) turns the check off.
     */
    public function testTheMigrationRunnerChecksSqliteBeforeCreatingTheFile(): void
    {
        $dir = PdoMigrationRunner::repoRoot() . '/migrations';
        $path = sys_get_temp_dir() . '/apotheke-sqlite-min-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = ['driver' => 'sqlite', 'sqlite_path' => $path];
        $old = static fn (): string => '3.37.2';
        try {
            (new PdoMigrationRunner($dir, sqliteVersion: $old))->connect($db);
            $this->fail('an old SQLite must be refused');
        } catch (DatabaseUnavailable $e) {
            $this->assertSame('SQLite 3.38.0 or newer is required (JSON functions); this PHP has SQLite 3.37.2.', $e->getMessage());
        }
        $this->assertFileDoesNotExist($path, 'a refused migrate creates no database file');
        try {
            $pdo = (new PdoMigrationRunner($dir, false, $old))->connect($db);
            $this->assertSame('1', (string)$pdo->query('SELECT 1')->fetchColumn(), 'with the check off the runner connects');
            unset($pdo);
            (new PdoMigrationRunner($dir))->connect($db);
        } finally {
            array_map('unlink', glob($path . '*') ?: []);
        }
    }

    /** One list parameter for 5,000 ids (SearchIndex, Stocktakes, ItemList), on every driver. */
    #[DataProvider('drivers')]
    public function testAnInListTakesThousandsOfIdsInOneParameter(string $driver): void
    {
        [$this->database, $db] = UowSchema::create($driver);
        $db->query("INSERT INTO uw_parent (name) VALUES ('a'), ('b'), ('c')");
        $ids = [...range(10_000, 14_997), 1, 3];
        $rows = Writes::rows($db, 'SELECT name FROM uw_parent WHERE id IN ' . Dialect::of($db)->inList() . ' ORDER BY id', [Dialect::list($ids)]);
        $this->assertSame(['a', 'c'], array_column($rows, 'name'));
        $names = Writes::rows($db, 'SELECT name FROM uw_parent WHERE name IN ' . Dialect::of($db)->inList('text') . ' ORDER BY id', [Dialect::list(['b', "it's"])]);
        $this->assertSame(['b'], array_column($names, 'name'));
        $this->assertSame([], Writes::rows($db, 'SELECT name FROM uw_parent WHERE id IN ' . Dialect::of($db)->inList(), [Dialect::list([])]));
    }
}
