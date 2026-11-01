<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\WriteFailed;
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
