<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\D1\Connection;
use Apotheke\Core\Database\D1\Result;
use Apotheke\Core\Database\D1Limits;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use SQLite3;

/** A d1.internal stand-in: a real SQLite, batches all or nothing, failures as the bridge's generic JSON. */
final class FakeD1Bridge
{
    public SQLite3 $db;
    /** @var list<array{0:string,1:mixed}> path and decoded body of every request */
    public array $calls = [];
    /** @var null|callable(string):?string answers instead of the database (transport faults) */
    public $fault = null;

    public function __construct()
    {
        $this->db = new SQLite3(':memory:');
        $this->db->enableExceptions(true);
    }

    public function __invoke(string $method, string $url, string $json): string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $body = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->calls[] = [$path, $body];
        if ($this->fault !== null && ($out = ($this->fault)($path)) !== null) {
            return $out;
        }
        try {
            if ($path === '/query') {
                return json_encode(['ok' => true, 'rows' => $this->rows($body['sql'], $body['params'] ?? [])], JSON_THROW_ON_ERROR);
            }
            if ($path === '/exec') {
                $this->db->exec($body['sql']);

                return '{"ok":true,"count":1}';
            }
            $this->db->exec('BEGIN');
            try {
                $results = [];
                foreach ($body as $s) {
                    $results[] = ['rows' => $this->rows($s['sql'], $s['params'] ?? [])];
                }
                $this->db->exec('COMMIT');
            } catch (\Throwable $e) {
                $this->db->exec('ROLLBACK');
                throw $e;
            }

            return json_encode(['ok' => true, 'results' => $results], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $guard = str_contains($e->getMessage(), 'NOT NULL constraint failed: uow_guard.ok');

            return json_encode(['ok' => false, 'code' => $guard ? 'guard_failed' : 'write_failed', 'error' => $guard ? 'NOT NULL constraint failed: uow_guard.ok' : 'the batch'], JSON_THROW_ON_ERROR);
        }
    }

    /** @param list<scalar|null> $params @return list<array<string,mixed>> */
    private function rows(string $sql, array $params): array
    {
        $st = $this->db->prepare($sql);
        foreach ($params as $i => $p) {
            $st->bindValue($i + 1, $p);
        }
        $r = $st->execute();
        $rows = [];
        while ($r !== false && $r->numColumns() > 0 && ($row = $r->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function count(string $path): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => $c[0] === $path));
    }
}

final class D1DriverTest extends CIUnitTestCase
{
    private FakeD1Bridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new FakeD1Bridge();
        $this->bridge->db->exec('CREATE TABLE uow_guard (ok INTEGER NOT NULL); CREATE TABLE item (id INTEGER PRIMARY KEY, name TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1)');
    }

    /** @param array<string,mixed> $over */
    private function db(array $over = []): Connection
    {
        $db = Database::connect($over + [
            'DBDriver' => 'Apotheke\\Core\\Database\\D1', 'hostname' => 'http://d1.internal', 'database' => '',
            'DBDebug' => false, 'transport' => $this->bridge,
        ], false);
        $this->assertInstanceOf(Connection::class, $db);

        return $db;
    }

    private function rows(string $sql): array
    {
        $r = $this->bridge->db->query($sql);
        $out = [];
        while (($row = $r->fetchArray(SQLITE3_ASSOC)) !== false) {
            $out[] = $row;
        }

        return $out;
    }

    public function testBuilderWritesAndReadsThroughTheBridgeWithExactIdsAndCounts(): void
    {
        $db = $this->db();
        $this->assertTrue($db->table('item')->insert(['name' => "O'Brien", 'qty' => 2]));
        $this->assertSame(1, $db->insertID());
        $db->table('item')->insert(['name' => 'b', 'qty' => 2]);
        $this->assertSame(2, $db->insertID());
        $this->assertTrue($db->table('item')->where('qty', 2)->update(['qty' => 3]));
        $this->assertSame(2, $db->affectedRows());
        $rows = $db->table('item')->select('name, qty')->orderBy('id')->get();
        $this->assertInstanceOf(Result::class, $rows);
        $this->assertSame([['name' => "O'Brien", 'qty' => 3], ['name' => 'b', 'qty' => 3]], $rows->getResultArray());
        $this->assertSame(['name', 'qty'], $rows->getFieldNames());
        $this->assertSame("O'Brien", $db->table('item')->where('id', 1)->get()->getRow()->name);
        $this->assertSame([], $db->query('SELECT * FROM item WHERE id = 99')->getResultArray());
        $this->assertSame(0, $this->bridge->count('/exec'));
    }

    public function testAWriteOutsideATransactionIsOneBatchWithItsOwnChangesAndId(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO item (name) VALUES ('a'), ('b'), ('c')");
        $this->assertSame([3, 3], [$db->affectedRows(), $db->insertID()]);
        $this->assertSame(1, $this->bridge->count('/batch'));
        $this->assertStringStartsWith('SELECT changes()', $this->bridge->calls[0][1][1]['sql']);
        $r = $db->query("UPDATE item SET qty = qty + 1 WHERE name <> 'a' RETURNING id");
        $this->assertSame([['id' => 2], ['id' => 3]], $r->getResultArray());
        $this->assertSame(2, $db->affectedRows());
    }

    public function testATransactionSendsNothingUntilCommitThenOneBatch(): void
    {
        $db = $this->db();
        $out = Writes::transaction($db, function () use ($db): string {
            $db->table('item')->insert(['name' => 'x']);
            $db->query("UPDATE item SET qty = qty + 5 WHERE name = 'x'");
            $this->assertSame([], $this->bridge->calls, 'queued, not sent');
            $this->assertSame([], $this->rows('SELECT * FROM item'));

            return 'done';
        });
        $this->assertSame('done', $out);
        $this->assertSame(1, $this->bridge->count('/batch'));
        $this->assertCount(2, $this->bridge->calls[0][1]);
        $this->assertSame([['name' => 'x', 'qty' => 5]], $this->rows('SELECT name, qty FROM item'));
        $this->assertSame(0, $db->transDepth);
    }

    public function testRollbackAndAThrowingCallerSendNothing(): void
    {
        $db = $this->db();
        $db->transBegin();
        $db->table('item')->insert(['name' => 'x']);
        $db->transRollback();
        try {
            Writes::transaction($db, function () use ($db): void {
                $db->table('item')->insert(['name' => 'y']);

                throw new \RuntimeException('caller changed its mind');
            });
            $this->fail('the caller error must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('caller changed its mind', $e->getMessage());
        }
        $this->assertSame([], $this->bridge->calls);
        Writes::transaction($db, fn () => $db->table('item')->insert(['name' => 'z']));
        $this->assertCount(1, $this->bridge->calls[0][1], 'the next transaction sends only its own write');
        $this->assertSame([['name' => 'z']], $this->rows('SELECT name FROM item'), 'the queue was dropped, not carried over');
    }

    public function testAFailingStatementInTheBatchKeepsNothing(): void
    {
        $db = $this->db();
        $db->table('item')->insert(['name' => 'keep', 'qty' => 1]);
        try {
            Writes::transaction($db, function () use ($db): void {
                $db->query("UPDATE item SET qty = 100 WHERE name = 'keep'");
                $db->query('INSERT INTO item (name) VALUES (NULL)');
            });
            $this->fail('the commit must fail');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: commit', $e->getMessage());
        }
        $this->assertSame([['qty' => 1]], $this->rows('SELECT qty FROM item'));
        $this->assertSame(0, $db->transDepth);
        $this->assertTrue($db->transStatus(), 'the next transaction can write again');
    }

    public function testReadsInATransaction(): void
    {
        $db = $this->db();
        $db->table('item')->insert(['name' => 'a', 'qty' => 1]);
        $this->bridge->calls = [];
        try {
            Writes::transaction($db, function () use ($db): void {
                $this->assertSame([['qty' => 1]], Writes::rows($db, 'SELECT qty FROM item'), 'a read before any write runs at once');
                $db->query('UPDATE item SET qty = 9');
                Writes::rows($db, 'SELECT qty FROM item');
            });
            $this->fail('a read after a queued write must be refused');
        } catch (WriteFailed $e) {
            $this->assertSame('Database read failed', $e->getMessage());
        }
        $this->assertSame([['qty' => 1]], $this->rows('SELECT qty FROM item'), 'the queued write was not sent');
        $this->assertSame(['/query'], array_column($this->bridge->calls, 0));
    }

    public function testUnknownAnswersInATransactionAreRefusedNotGuessed(): void
    {
        $db = $this->db(['DBDebug' => true]);
        $db->transBegin();
        $db->table('item')->insert(['name' => 'a']);
        foreach (['insertID', 'affectedRows'] as $m) {
            try {
                $db->{$m}();
                $this->fail($m . ' must be refused while queued');
            } catch (DatabaseException $e) {
                $this->assertStringContainsString('unit of work', $e->getMessage());
            }
        }
        // Inside a transaction CodeIgniter turns a failed statement into false and a failed transaction.
        $this->assertFalse($db->query("UPDATE item SET qty = 1 RETURNING id"), 'RETURNING inside a transaction must be refused');
        $this->assertFalse($db->transStatus());
        $this->assertStringContainsString('cannot return rows', $db->error()['message']);
        $db->transRollback();
        $this->assertSame([], $this->bridge->calls);
    }

    public function testTransactionAndUnsupportedStatementsAreRefusedBeforeTransport(): void
    {
        $db = $this->db();
        foreach (['BEGIN', 'BEGIN IMMEDIATE', 'COMMIT', 'ROLLBACK', 'SAVEPOINT apotheke_uow', 'RELEASE SAVEPOINT apotheke_uow', 'PRAGMA journal_mode = WAL', "ATTACH DATABASE 'x' AS x"] as $sql) {
            $this->assertFalse($db->query($sql), $sql);
        }
        Writes::transaction($db, function () use ($db): void {
            $this->assertFalse($db->query('SAVEPOINT apotheke_uow'));
            $db->resetTransStatus();
        });
        try {
            Writes::transaction($db, fn () => Writes::savepoint($db, fn () => null));
            $this->fail('a savepoint on the bridge must fail loudly');
        } catch (WriteFailed) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], $this->bridge->calls);
        $this->assertSame(0, $db->transDepth);
    }

    public function testAStatementTheBridgeWouldRefuseFailsWhenQueuedNotAtCommit(): void
    {
        $db = $this->db();
        $db->transBegin();
        $this->assertFalse($db->query("INSERT INTO item (name) VALUES ('a'); DROP TABLE item"));
        $this->assertFalse($db->transStatus(), 'the transaction is already failed');
        $db->transRollback();
        $this->assertSame([], $this->bridge->calls);
    }

    public function testTransportFailuresFailClosedWithoutTheDatabaseText(): void
    {
        $secret = 'value-' . bin2hex(random_bytes(6));
        foreach ([
            static fn (): string => throw new \RuntimeException('connect to ' . $secret),
            static fn (): string => '<html>502 ' . $secret . '</html>',
            static fn (): string => json_encode(['ok' => 'true', 'rows' => [['n' => 1]]]),
            static fn (): string => json_encode(['ok' => false, 'error' => 'UNIQUE constraint failed: item.name ' . $secret]),
        ] as $i => $fault) {
            $this->bridge->fault = $fault;
            $db = $this->db();
            $this->assertFalse($db->query('SELECT 1 AS n'), "read $i");
            $this->assertFalse($db->query("INSERT INTO item (name) VALUES ('x')"), "write $i");
            $this->assertStringNotContainsString($secret, json_encode($db->error()));
            $debug = $this->db(['DBDebug' => true]);
            try {
                $debug->query('SELECT 1 AS n');
                $this->fail("debug $i");
            } catch (DatabaseException $e) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
            $db->transBegin();
            $db->query("INSERT INTO item (name) VALUES ('x')");
            $this->assertFalse($db->transCommit(), "commit $i");
            $db->transRollback();
        }
        $this->bridge->fault = null;
        $this->assertSame([], $this->rows('SELECT * FROM item'));
    }

    public function testOnlyTheBridgeHostOrLoopbackIsAccepted(): void
    {
        foreach (['http://evil.example', 'http://d1.internal.evil.example', 'http://user@d1.internal', 'http://d1.internal/path'] as $host) {
            $db = $this->db(['hostname' => $host, 'DBDebug' => true]);
            try {
                $db->query('SELECT 1 AS n');
                $this->fail($host);
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->bridge->calls);
    }

    /** Two requests read the same version, then race their guarded compare-and-swap batches. */
    public function testRacingGuardedWritesOnlyOneWins(): void
    {
        $this->bridge->db->exec("INSERT INTO item (id, name, qty, version) VALUES (1, 'a', 0, 1)");
        $a = $this->db();
        $b = $this->db();
        $cas = static function (Connection $db, int $qty): void {
            $v = (int)Writes::rows($db, 'SELECT version FROM item WHERE id = 1')[0]['version'];
            $db->query('UPDATE item SET qty = ' . $qty . ', version = version + 1 WHERE id = 1 AND version = ' . $v);
            $db->query('INSERT INTO uow_guard (ok) SELECT NULL WHERE changes() <> 1');
        };
        $a->transBegin();
        $b->transBegin();
        $cas($a, 10);
        $cas($b, 20);
        $this->assertTrue($b->transCommit());
        $this->assertFalse($a->transCommit());
        $this->assertSame(BatchExecutor::GUARD_FAILED, $a->error()['code']);
        $a->transRollback();
        $this->assertSame([['qty' => 20, 'version' => 2]], $this->rows('SELECT qty, version FROM item'));
        $this->assertSame([['n' => 0]], $this->rows('SELECT COUNT(*) AS n FROM uow_guard'));
    }

    public function testRacingRelativeWritesAllLand(): void
    {
        $this->bridge->db->exec("INSERT INTO item (id, name, qty) VALUES (1, 'a', 0)");
        $dbs = array_map(fn () => $this->db(), range(1, 6));
        foreach ($dbs as $db) {
            $db->transBegin();
            $db->query('UPDATE item SET qty = qty + 1 WHERE id = 1');
        }
        foreach (array_reverse($dbs) as $db) {
            $this->assertTrue($db->transCommit());
        }
        $this->assertSame([['qty' => 6]], $this->rows('SELECT qty FROM item'));
    }

    /**
     * Leading comments and whitespace the statement guard strips (D1Limits): both comment styles, mixed and
     * multi-line, CRLF, no space after a comment, SQLite's non-nesting block comment, and a CTE.
     *
     * @return array<string,array{string,int}> the write and the rows it changes in setUp's three-row table
     */
    public static function commentedWrites(): array
    {
        return [
            'line comment' => ["-- note\nUPDATE item SET qty = 7", 3],
            'block comment' => ["/* note */ UPDATE item SET qty = 7 WHERE id > 1", 2],
            'no space after a comment' => ['/**/DELETE FROM item WHERE id = 1', 1],
            'mixed, multi-line, tabs' => ["/* a */\n\t-- b\n  /* c\n d */\t INSERT INTO item (name) VALUES ('x')", 1],
            'CRLF line comment' => ["\r\n -- c\r\nREPLACE INTO item (id, name) VALUES (9, 'x')", 1],
            'block comments do not nest' => ["/* outer /* inner */ UPDATE item SET qty = 7 WHERE id = 2", 1],
            'empty line comment' => ["--\nINSERT INTO item (name) VALUES ('x')", 1],
            'CTE after a comment' => ["-- c\nWITH n(v) AS (SELECT 1) INSERT INTO item (name) SELECT 'x' FROM n", 1],
            'comment inside a CTE' => ["WITH n(v) AS (SELECT 1) /* c */ UPDATE item SET qty = (SELECT v FROM n)", 3],
            'a word RETURNING in a literal' => ["/* c */ INSERT INTO item (name) VALUES (' RETURNING ')", 1],
            'lower case after a WITH' => ["/* c */ with n(v) as (select 1) insert into item (name) select 'x' from n", 1],
        ];
    }

    private function seed(): void
    {
        $this->bridge->db->exec("INSERT INTO item (name) VALUES ('a'), ('b'), ('c')");
    }

    #[DataProvider('commentedWrites')]
    public function testACommentedWriteInATransactionIsQueuedAndARollbackSendsNothing(string $sql, int $changes): void
    {
        $this->seed();
        $before = $this->rows('SELECT * FROM item ORDER BY id');
        $db = $this->db();
        $db->transBegin();
        $this->assertTrue($db->query($sql), 'a queued write answers true');
        $this->assertSame([], $this->bridge->calls, 'queued, not sent');
        $db->transRollback();
        $this->assertSame([], $this->bridge->calls);
        $this->assertSame($before, $this->rows('SELECT * FROM item ORDER BY id'), 'the rollback left no row');
        $db->transBegin();
        $db->query($sql);
        $this->assertTrue($db->transCommit());
        $this->assertSame(['/batch'], array_column($this->bridge->calls, 0), 'the commit sends it as the batch');
        $this->assertNotSame($before, $this->rows('SELECT * FROM item ORDER BY id'));
    }

    #[DataProvider('commentedWrites')]
    public function testACommentedWriteOutsideATransactionReportsItsOwnCounts(string $sql, int $changes): void
    {
        $this->seed();
        $db = $this->db();
        $this->assertTrue($db->query($sql), 'a write answers true, not a result');
        $this->assertSame($changes, $db->affectedRows());
        $this->assertSame(['/batch'], array_column($this->bridge->calls, 0), 'one batch with changes(), no bare query');
        $this->assertStringStartsWith('SELECT changes()', $this->bridge->calls[0][1][1]['sql']);
    }

    /** The other direction: a read whose comments, literals or quoted names look like a write stays a read. */
    public function testAReadThatLooksLikeAWriteIsReadInATransaction(): void
    {
        $this->seed();
        $db = $this->db();
        foreach ([
            "WITH n(v) AS (SELECT 1) SELECT v FROM n WHERE 'a' <> ' DELETE '",
            'WITH n(v) AS (SELECT 1) SELECT v FROM n /* DELETE */',
            "-- INSERT INTO item\nSELECT 1 AS v",
            'WITH "update"(v) AS (SELECT 1) SELECT v FROM "update"',
            'WITH my_update(v) AS (SELECT 1) SELECT v FROM my_update',
        ] as $sql) {
            $this->bridge->calls = [];
            $db->transBegin();
            $r = $db->query($sql);
            $this->assertInstanceOf(Result::class, $r, $sql);
            $this->assertSame([['v' => 1]], $r->getResultArray(), $sql);
            $this->assertSame(['/query'], array_column($this->bridge->calls, 0), $sql);
            $db->transRollback();
        }
    }

    public function testACommentedWriteWithRowsIsRefusedInATransactionAndAnsweredOutside(): void
    {
        $this->seed();
        $this->bridge->db->exec('CREATE TABLE r ("returning" TEXT)');
        $db = $this->db(['DBDebug' => true]);
        $db->transBegin();
        $this->assertFalse($db->query("/* c */\nUPDATE item SET qty = 1 WHERE id = 1 RETURNING id"), 'rows from a queued write would be guessed');
        $this->assertFalse($db->transStatus());
        $this->assertSame('D1 cannot return rows from a write inside a transaction; use a unit of work.', $db->error()['message']);
        $this->assertSame([], $this->bridge->calls);
        $db->transRollback();
        $db->transBegin();
        $this->assertTrue($db->query('-- c' . "\n" . 'INSERT INTO r ("returning") VALUES (\'x\')'), 'a quoted name is not RETURNING');
        $this->assertTrue($db->transCommit());
        $r = $db->query("-- c\nUPDATE item SET qty = 2 WHERE id > 1 RETURNING id");
        $this->assertSame([['id' => 2], ['id' => 3]], $r->getResultArray());
        $this->assertSame(2, $db->affectedRows());
    }

    public function testAnUnclosedLeadingCommentIsRefusedBeforeAnythingIsSent(): void
    {
        $unreadable = 'D1 batch statements must be readable: a block comment is not closed.';
        $db = $this->db(['DBDebug' => true]);
        try {
            $db->query('/* INSERT INTO item (name) VALUES (1)');
            $this->fail('an unreadable statement must not run');
        } catch (DatabaseException $e) {
            $this->assertSame($unreadable, $e->getMessage());
        }
        $db->transBegin();
        $this->assertFalse($db->query('/* x */ INSERT INTO item (name) VALUES (1) /* open'));
        $this->assertFalse($db->transStatus());
        $this->assertSame($unreadable, $db->error()['message']);
        $db->transRollback();
        $this->assertSame([], $this->bridge->calls);
    }

    /** The guard and the classifier read one text: comments and whitespace on both ends are gone for both. */
    public function testTheGuardAndTheClassifierReadTheSameText(): void
    {
        $sql = "\t-- c\n/* d */ PRAGMA table_list \n\t-- e\n";
        $this->assertSame('PRAGMA table_list', D1Limits::leading($sql));
        D1Limits::refuse($sql, 0);
        $db = $this->db(['DBDebug' => true]);
        $this->assertFalse($db->isWriteType($sql));
        $this->assertNotSame([], $db->query($sql)->getResultArray(), 'a documented PRAGMA with comments around it is read');
        $this->assertSame(['/query'], array_column($this->bridge->calls, 0));
    }
}
