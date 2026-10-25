<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\SQLite3\Connection as SQLite3Connection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TestDatabase;

/**
 * Writes::transaction() on the shipped connection (DBDebug off): a commit
 * the database refuses throws and keeps nothing (N-32), and nesting is flat
 * like legacy db_tx(): an inner failure the caller catches does not undo the
 * inner statements.
 */
final class WritesTransactionTest extends CIUnitTestCase
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

    /** A deferred foreign key fails only at COMMIT: the commit check must throw, and nothing is kept. */
    #[DataProvider('drivers')]
    public function testARefusedCommitThrowsAndKeepsNothing(string $driver): void
    {
        $db = $this->wire($driver);
        try {
            Writes::transaction($db, function () use ($db): void {
                Writes::check($db->table('wt_child')->insert(['id' => 1, 'parent_id' => 99]), 'child');
            });
            $this->fail('a refused commit must throw');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: commit', $e->getMessage());
        }
        $this->assertSame(0, (int)$db->transDepth, 'the transaction is closed');
        $this->assertSame([], $db->table('wt_child')->get()->getResultArray(), 'nothing is kept');

        Writes::transaction($db, function () use ($db): void {
            Writes::check($db->table('wt_parent')->insert(['id' => 99]), 'parent');
            Writes::check($db->table('wt_child')->insert(['id' => 1, 'parent_id' => 99]), 'child');
        });
        $this->assertCount(1, $db->table('wt_child')->get()->getResultArray(), 'the next transaction commits');
    }

    /** A driver that answers false to COMMIT (no exception) must also fail the write and keep nothing. */
    public function testACommitThatReturnsFalseThrowsAndKeepsNothing(): void
    {
        $db = new class (['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBDebug' => false]) extends SQLite3Connection {
            public bool $refuse = true;

            public function transCommit(): bool
            {
                if ($this->refuse && $this->transDepth === 1) {
                    return false;
                }

                return parent::transCommit();
            }
        };
        $db->query('CREATE TABLE wt_parent (id INTEGER PRIMARY KEY)');
        try {
            Writes::transaction($db, function () use ($db): void {
                Writes::check($db->query('INSERT INTO wt_parent (id) VALUES (1)'), 'parent');
            });
            $this->fail('a refused commit must throw');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: commit', $e->getMessage());
        }
        $this->assertSame(0, (int)$db->transDepth, 'the transaction is closed');
        $this->assertSame(0, $db->connID->querySingle('SELECT COUNT(*) FROM wt_parent'), 'nothing is kept');
        $db->refuse = false;
        Writes::transaction($db, function () use ($db): void {
            Writes::check($db->query('INSERT INTO wt_parent (id) VALUES (2)'), 'parent');
        });
        $this->assertSame(1, $db->connID->querySingle('SELECT COUNT(*) FROM wt_parent'), 'the next transaction commits');
        $db->close();
    }

    /** No SAVEPOINT: a caught inner failure leaves the inner statements in the outer commit, as db_tx() does. */
    #[DataProvider('drivers')]
    public function testNestingIsFlat(string $driver): void
    {
        $db = $this->wire($driver);
        Writes::transaction($db, function () use ($db): void {
            Writes::check($db->table('wt_parent')->insert(['id' => 1]), 'outer');
            try {
                Writes::transaction($db, function () use ($db): void {
                    Writes::check($db->table('wt_parent')->insert(['id' => 2]), 'inner');
                    throw new RuntimeException('refused');
                });
            } catch (RuntimeException) {
                // the caller carries on
            }
            $this->assertSame(1, (int)$db->transDepth, 'the inner call closed only its own level');
            Writes::check($db->table('wt_parent')->insert(['id' => 3]), 'outer');
        });
        $this->assertSame(0, (int)$db->transDepth);
        $ids = array_map(static fn (array $r): int => (int)$r['id'], $db->table('wt_parent')->orderBy('id')->get()->getResultArray());
        $this->assertSame([1, 2, 3], $ids);
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function wire(string $driver): BaseConnection
    {
        $this->database = TestDatabase::create($driver, false);
        $db = $this->database->db;
        $this->assertFalse((bool)$db->DBDebug, 'the shipped connection has DBDebug off');
        if ($db->DBDriver === 'SQLite3') {
            $db->query('PRAGMA foreign_keys = ON');
        }
        $db->query('CREATE TABLE wt_parent (id INTEGER PRIMARY KEY)');
        $db->query('CREATE TABLE wt_child (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES wt_parent (id) DEFERRABLE INITIALLY DEFERRED)');

        return $db;
    }
}
