<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\DatabaseUnavailable;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Migrations\PdoMigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
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
