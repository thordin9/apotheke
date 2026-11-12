<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\D1\Connection;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Tests\Support\TestDatabase;
use Throwable;

require_once __DIR__ . '/D1DriverTest.php';

/**
 * Writes::rowsMany(): several independent reads in one batch on the D1 driver, one after another on SQLite and
 * PostgreSQL, with the rows, the order and the failures of Writes::rows() (or typedRows()).
 */
final class RowsManyTest extends CIUnitTestCase
{
    private const QUERIES = [
        ['SELECT id, name FROM item WHERE qty >= ? ORDER BY id', [2]],
        ['SELECT COUNT(*) AS n FROM item WHERE name = ?', ["O'Brien"]],
        ['SELECT name FROM item WHERE id = ? OR name IS ?', [99, null]],
    ];

    private function d1(FakeD1Bridge $bridge): Connection
    {
        $bridge->db->exec("CREATE TABLE uow_guard (ok INTEGER NOT NULL); CREATE TABLE item (id INTEGER PRIMARY KEY, name TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 0);
            INSERT INTO item (name, qty) VALUES ('O''Brien', 2), ('b', 1), ('c', 3)");
        $db = Database::connect(['DBDriver' => 'Apotheke\\Core\\Database\\D1', 'hostname' => 'http://d1.internal', 'database' => '',
            'DBDebug' => false, 'transport' => $bridge], false);
        $this->assertInstanceOf(Connection::class, $db);

        return $db;
    }

    /** @return list<list<array<string,mixed>>> */
    private static function oneByOne(\CodeIgniter\Database\BaseConnection $db): array
    {
        return array_map(static fn (array $q): array => Writes::rows($db, $q[0], $q[1]), self::QUERIES);
    }

    public function testTheD1DriverSendsTheReadsAsOneBatch(): void
    {
        $bridge = new FakeD1Bridge();
        $db = $this->d1($bridge);
        $expected = self::oneByOne($db);
        $bridge->calls = [];
        $this->assertSame($expected, Writes::rowsMany($db, self::QUERIES));
        $this->assertSame([['/batch', [
            ['sql' => self::QUERIES[0][0], 'params' => [2]],
            ['sql' => self::QUERIES[1][0], 'params' => ["O'Brien"]],
            ['sql' => self::QUERIES[2][0], 'params' => [99, null]],
        ]]], $bridge->calls);
        $this->assertSame([[['id' => 1, 'name' => "O'Brien"], ['id' => 3, 'name' => 'c']], [['n' => 1]], []], $expected);
        $bridge->calls = [];
        $this->assertSame([[['n' => 1]], [['x' => 1]]], Writes::rowsMany($db, [['SELECT COUNT(*) AS n FROM item WHERE qty = ?', [true]], ['SELECT 1 AS x', []]]));
        $this->assertSame([1], $bridge->calls[0][1][0]['params'], 'a bool bind is sent as 1');
    }

    public function testTheD1DriverReadsOneByOneWhenABatchWouldNotDo(): void
    {
        $bridge = new FakeD1Bridge();
        $db = $this->d1($bridge);
        $expected = self::oneByOne($db);
        // a statement that is no SELECT is never sent in a read batch
        $bridge->calls = [];
        $this->assertSame([[['n' => 3]], []], Writes::rowsMany($db, [['SELECT COUNT(*) AS n FROM item', []], ['WITH x AS (SELECT 1 AS n) SELECT n FROM x WHERE n = 2', []]]));
        $this->assertSame([2, 0], [$bridge->count('/query'), $bridge->count('/batch')]);
        // a failed batch (here the bridge refuses it) is read again one statement at a time
        $bridge->calls = [];
        $bridge->fault = static fn (string $path): ?string => $path === '/batch' ? '{"ok":false,"code":"write_failed","error":"the batch"}' : null;
        $this->assertSame($expected, Writes::rowsMany($db, self::QUERIES));
        $this->assertSame([1, 3], [$bridge->count('/batch'), $bridge->count('/query')]);
        $bridge->fault = null;
        // a queued write in an open transaction: the reads behave as rows() does there
        $db->transBegin();
        try {
            $this->assertTrue((bool)$db->query("UPDATE item SET qty = 9 WHERE id = 2"));
            $this->assertSame(self::failure(static fn () => Writes::rows($db, self::QUERIES[0][0], self::QUERIES[0][1])),
                self::failure(static fn () => Writes::rowsMany($db, self::QUERIES)));
        } finally {
            $db->transRollback();
        }
    }

    public function testAFailingStatementFailsAsRowsDoes(): void
    {
        $bridge = new FakeD1Bridge();
        $db = $this->d1($bridge);
        $bad = [['SELECT 1 AS x', []], ['SELECT nope FROM item', []]];
        $one = self::failure(static fn () => Writes::rows($db, 'SELECT nope FROM item'));
        $this->assertSame(WriteFailed::class, $one[0]);
        $this->assertSame($one, self::failure(static fn () => Writes::rowsMany($db, $bad)));
    }

    public function testSqliteAndPostgresqlReadOneAfterAnother(): void
    {
        foreach (TestDatabase::drivers() as $driver) {
            if ($driver === 'd1') {
                continue;
            }
            $t = TestDatabase::create($driver);
            try {
                $db = $t->db;
                $this->assertTrue((bool)$db->query('CREATE TABLE item (id INTEGER PRIMARY KEY, name TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 0)'));
                $this->assertTrue((bool)$db->query("INSERT INTO item (id, name, qty) VALUES (1, 'O''Brien', 2), (2, 'b', 1), (3, 'c', 3)"));
                $this->assertSame(self::oneByOne($db), Writes::rowsMany($db, self::QUERIES), $driver);
                $typed = Writes::rowsMany($db, [['SELECT id, qty FROM item WHERE id = ?', [3]], ['SELECT name FROM item WHERE id = ?', [1]]], true);
                $this->assertSame([[['id' => 3, 'qty' => 3]], [['name' => "O'Brien"]]], $typed, $driver . ': typed as typedRows()');
                $this->assertSame(self::failure(static fn () => Writes::rows($db, 'SELECT nope FROM item')),
                    self::failure(static fn () => Writes::rowsMany($db, [['SELECT 1 AS x', []], ['SELECT nope FROM item', []]])), $driver);
                $this->assertSame([], Writes::rowsMany($db, []));
            } finally {
                $t->drop();
            }
        }
    }

    /** @return array{0:class-string<Throwable>,1:string}|null */
    private static function failure(callable $fn): ?array
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return [$e::class, $e->getMessage()];
        }

        return null;
    }
}
