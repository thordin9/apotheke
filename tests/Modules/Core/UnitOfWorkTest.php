<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\ConnectionUnitOfWork;
use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TestDatabase;
use Tests\Support\UowSchema;

/**
 * UnitsOfWork::run() (ADR-002, P4-2) on every driver, as a real
 * transaction (the default on a connection): the callback reads, its writes are queued and run in
 * order at the end; a Ref gives later steps the id of a row the unit
 * inserts; a guard that does not hold keeps nothing of the unit, its holds
 * included; a nested unit joins the outer one.
 */
final class UnitOfWorkTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ' transaction' => [$driver, false];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    #[DataProvider('modes')]
    public function testQueuedWritesRunAtTheEndAndRefsResolve(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        $seen = null;
        $ref = $units->run('uw_parent', function (UnitOfWork $uow) use ($db, &$seen): Ref {
            $p = $uow->insert('uw_parent', ['name' => 'box'], 'parent');
            $uow->insert('uw_child', ['parent_id' => $p, 'name' => 'a'], 'child');
            $uow->write('INSERT INTO uw_child (parent_id, name) VALUES (?, ?)', [$p, 'b'], 'child', 1);
            $uow->update('uw_parent', ['name' => 'crate'], ['id' => $p], 'rename');
            $seen = (int)$db->query('SELECT COUNT(*) AS n FROM uw_parent')->getRow()->n;
            $this->assertCount(4, $uow->statements());

            return $p;
        });
        $this->assertSame(0, $seen, 'queued writes are not visible inside the callback');
        $this->assertSame([['id' => $ref->id(), 'name' => 'crate']], $this->parents($db));
        $this->assertSame(['a', 'b'], array_column($db->query('SELECT name FROM uw_child WHERE parent_id = ? ORDER BY id', [$ref->id()])->getResultArray(), 'name'));
        $this->assertSame(0, (int)$db->transDepth);
        $this->assertFalse($units->isOpen());
        $this->assertSame('0', (string)$db->query('SELECT COUNT(*) AS n FROM uow_refs')->getRow()->n, 'uow_refs is empty again');
    }

    #[DataProvider('modes')]
    public function testAFailedExpectGuardKeepsNothingAndThrowsItsError(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        try {
            $units->run('uw_parent', function (UnitOfWork $uow): void {
                $uow->hold('INSERT INTO uw_parent (name) VALUES (?)', ['held'], 'hold');
                $uow->insert('uw_parent', ['name' => 'queued'], 'parent');
                $uow->write('UPDATE uw_parent SET name = ? WHERE name = ?', ['x', 'nobody'], 'stale write', 1, static fn (): UserError => new UserError('Changed meanwhile.', 409));
                $uow->insert('uw_parent', ['name' => 'after'], 'parent');
            });
            $this->fail('the guard must fail the unit');
        } catch (UserError $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertSame([], $this->parents($db), 'the hold and the queued writes are rolled back');
        $this->assertSame(0, (int)$db->transDepth);
        $this->assertFalse($units->isOpen());
    }

    /** An expect guard holds for exactly its count: one row more or less fails the unit. */
    #[DataProvider('modes')]
    public function testAnExpectGuardCountsTheRowsOfItsWrite(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        $db->query("INSERT INTO uw_parent (name) VALUES ('a'), ('a'), ('b')");
        $units->run('uw_parent', static fn (UnitOfWork $uow) => $uow->write('UPDATE uw_parent SET name = ? WHERE name = ?', ['c', 'a'], 'two', 2));
        foreach ([1, 3] as $expect) {
            try {
                $units->run('uw_parent', static fn (UnitOfWork $uow) => $uow->write('UPDATE uw_parent SET name = ? WHERE name = ?', ['d', 'c'], 'count', $expect));
                $this->fail('expect ' . $expect . ' must fail');
            } catch (WriteFailed $e) {
                $this->assertSame('Database write failed: count', $e->getMessage());
            }
        }
        $this->assertSame(['c', 'c', 'b'], array_column($this->parents($db), 'name'));
    }

    #[DataProvider('modes')]
    public function testAFailedAssertKeepsNothing(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        try {
            $units->run('uw_parent', function (UnitOfWork $uow): void {
                $uow->insert('uw_parent', ['name' => 'one'], 'parent');
                $uow->assert('(SELECT COUNT(*) FROM uw_parent) < ?', [1], 'limit');
            });
            $this->fail('the assert must fail the unit');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: limit', $e->getMessage());
        }
        $this->assertSame([], $this->parents($db));
        $units->run('uw_parent', function (UnitOfWork $uow): void {
            $uow->assert('(SELECT COUNT(*) FROM uw_parent) < ?', [1], 'limit');
            $uow->insert('uw_parent', ['name' => 'one'], 'parent');
        });
        $this->assertCount(1, $this->parents($db), 'the next unit commits');
    }

    #[DataProvider('modes')]
    public function testAnExceptionInTheCallbackRunsNothing(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        try {
            $units->run('uw_parent', function (UnitOfWork $uow): void {
                $uow->insert('uw_parent', ['name' => 'one'], 'parent');
                throw new RuntimeException('refused');
            });
            $this->fail('the exception must pass');
        } catch (RuntimeException $e) {
            $this->assertSame('refused', $e->getMessage());
        }
        $this->assertSame([], $this->parents($db));
        $this->assertFalse($units->isOpen(), 'the registry is empty after a throw');
    }

    /** Inside an open transaction the unit joins it (flat, as Writes::transaction()): the outer rollback undoes it. */
    #[DataProvider('modes')]
    public function testAUnitJoinsAnOpenTransaction(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        try {
            Writes::transaction($db, function () use ($db, $units): void {
                $ref = $units->run('uw_parent', fn (UnitOfWork $uow): Ref => $uow->insert('uw_parent', ['name' => 'inner'], 'parent'));
                $this->assertSame(1, (int)$db->transDepth, 'still inside the outer transaction');
                $this->assertSame([['id' => $ref->id(), 'name' => 'inner']], $this->parents($db), 'the outer caller reads the unit\'s writes');
                throw new RuntimeException('outer fails');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->parents($db));
    }

    /**
     * L-1625: a unit inside a plain transaction is all or nothing, as a D1
     * batch: when its guard fails and the outer caller catches the error and
     * commits, nothing of the unit is kept, its hold included.
     */
    #[DataProvider('modes')]
    public function testAUnitInsideAPlainTransactionKeepsNothingWhenTheCallerCatches(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        Writes::transaction($db, function () use ($db, $units): void {
            $db->query('INSERT INTO uw_parent (name) VALUES (?)', ['outer']);
            try {
                $units->run('uw_parent', static function (UnitOfWork $uow): void {
                    $uow->hold('INSERT INTO uw_parent (name) VALUES (?)', ['held'], 'hold');
                    $uow->insert('uw_parent', ['name' => 'inner'], 'parent');
                    $uow->write('UPDATE uw_parent SET name = ? WHERE name = ?', ['x', 'nobody'], 'stale write', 1, static fn (): UserError => new UserError('Changed meanwhile.', 409));
                });
                $this->fail('the guard must fail the unit');
            } catch (UserError $e) {
                $this->assertSame(409, $e->getCode());
            }
            $this->assertSame(1, (int)$db->transDepth, 'the outer transaction carries on');
            $db->query('INSERT INTO uw_parent (name) VALUES (?)', ['after']);
        });
        $this->assertSame(['outer', 'after'], array_column($this->parents($db), 'name'));
        $this->assertSame(0, (int)$db->transDepth);
    }

    /** L-1625: a failed statement inside a joined unit is rolled back with the unit; the outer transaction still commits. */
    #[DataProvider('modes')]
    public function testAFailedStatementInAJoinedUnitLeavesTheOuterTransactionUsable(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        Writes::transaction($db, function () use ($db, $units): void {
            $db->query('INSERT INTO uw_parent (name) VALUES (?)', ['outer']);
            try {
                $units->run('uw_parent', static function (UnitOfWork $uow): void {
                    $uow->insert('uw_parent', ['name' => 'inner'], 'parent');
                    $uow->write('INSERT INTO uw_parent (name) VALUES (?)', [null], 'no name');
                });
                $this->fail('the statement must fail the unit');
            } catch (WriteFailed $e) {
                $this->assertSame('Database write failed: no name', $e->getMessage());
            }
            $db->query('INSERT INTO uw_parent (name) VALUES (?)', ['after']);
        });
        $this->assertSame(['outer', 'after'], array_column($this->parents($db), 'name'));
    }

    /** L-1626: a unit kept past its run() refuses every further call instead of dropping the write. */
    #[DataProvider('modes')]
    public function testAUnitRefusesWritesAfterItsRunEnded(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        $ran = $units->run('uw_parent', static function (UnitOfWork $uow): UnitOfWork {
            $uow->insert('uw_parent', ['name' => 'one'], 'parent');

            return $uow;
        });
        $threw = null;
        try {
            $units->run('uw_parent', static function (UnitOfWork $uow) use (&$threw): void {
                $threw = $uow;
                throw new RuntimeException('refused');
            });
        } catch (RuntimeException) {
        }
        $this->assertInstanceOf(UnitOfWork::class, $threw);
        $late = [
            'hold' => static fn (UnitOfWork $u) => $u->hold('INSERT INTO uw_parent (name) VALUES (?)', ['late'], 'late'),
            'insert' => static fn (UnitOfWork $u) => $u->insert('uw_parent', ['name' => 'late'], 'late'),
            'update' => static fn (UnitOfWork $u) => $u->update('uw_parent', ['name' => 'late'], ['name' => 'one'], 'late'),
            'write' => static fn (UnitOfWork $u) => $u->write('INSERT INTO uw_parent (name) VALUES (?)', ['late'], 'late'),
            'assert' => static fn (UnitOfWork $u) => $u->assert('1 = 1', [], 'late'),
            'flush' => static fn (ConnectionUnitOfWork $u) => $u->flush(),
        ];
        foreach (['after run' => $ran, 'after a throw' => $threw] as $when => $uow) {
            foreach ($late as $call => $f) {
                try {
                    $f($uow);
                    $this->fail($call . ' ' . $when . ' must throw');
                } catch (LogicException $e) {
                    $this->assertSame('The unit of work has already run: queue its writes inside its run() callback.', $e->getMessage());
                }
            }
        }
        $this->assertSame(['one'], array_column($this->parents($db), 'name'));
    }

    /** L-1626: a flushed unit runs once, and a discarded one refuses further writes too. */
    public function testAFlushedUnitRunsOnceAndADiscardedOneRefuses(): void
    {
        [$db] = $this->wire('sqlite', false);
        $ran = new ConnectionUnitOfWork($db);
        $ran->insert('uw_parent', ['name' => 'once'], 'once');
        $ran->flush();
        $discarded = new ConnectionUnitOfWork($db);
        $discarded->insert('uw_parent', ['name' => 'x'], 'x');
        $discarded->discard();
        $refusals = [
            [$ran, LogicException::class, 'The unit of work has already run: queue its writes inside its run() callback.'],
            [$discarded, WriteFailed::class, 'Database write failed: the unit of work was discarded'],
        ];
        foreach ($refusals as [$uow, $class, $message]) {
            foreach ([static fn () => $uow->insert('uw_parent', ['name' => 'late'], 'late'), static fn () => $uow->flush()] as $f) {
                try {
                    $f();
                    $this->fail('a closed unit must refuse');
                } catch (LogicException|WriteFailed $e) {
                    $this->assertInstanceOf($class, $e);
                    $this->assertSame($message, $e->getMessage());
                }
            }
        }
        $this->assertSame(['once'], array_column($this->parents($db), 'name'));
    }

    /** A unit started inside an open one joins it: one commit, Refs resolve with the outer unit. */
    #[DataProvider('modes')]
    public function testANestedUnitJoinsTheOuterOne(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        $inner = null;
        $outer = $units->run('uw_parent', function (UnitOfWork $uow) use ($db, $units, &$inner): Ref {
            $a = $uow->insert('uw_parent', ['name' => 'outer'], 'parent');
            $joined = null;
            $inner = $units->run('uw_parent', function (UnitOfWork $in) use (&$joined, $a): Ref {
                $joined = $in;
                $in->insert('uw_child', ['parent_id' => $a, 'name' => 'from inner'], 'child');

                return $in->insert('uw_parent', ['name' => 'inner'], 'parent');
            });
            $this->assertSame($uow, $joined, 'the inner callback gets the outer unit');
            $this->assertTrue($units->isOpen());
            $this->assertFalse($inner->resolved(), 'nothing ran yet');
            $this->assertSame([], $this->parents($db), 'the inner unit did not commit on its own');

            return $a;
        });
        $this->assertSame([['id' => $outer->id(), 'name' => 'outer'], ['id' => $inner->id(), 'name' => 'inner']], $this->parents($db));
        $this->assertSame($outer->id(), (int)$db->query('SELECT parent_id FROM uw_child')->getRow()->parent_id);
        $this->assertFalse($units->isOpen());
    }

    /** A joined unit that fails fails the outer one, even when the outer callback catches it: all or nothing, as a batch. */
    #[DataProvider('modes')]
    public function testAFailedNestedUnitFailsTheOuterOne(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        try {
            $units->run('uw_parent', function (UnitOfWork $uow) use ($units): void {
                $uow->insert('uw_parent', ['name' => 'outer'], 'parent');
                try {
                    $units->run('uw_parent', function (UnitOfWork $in): void {
                        $in->insert('uw_parent', ['name' => 'inner'], 'parent');
                        throw new UserError('Refused inside.', 422);
                    });
                } catch (UserError) {
                    // the outer caller carries on
                }
                $uow->insert('uw_parent', ['name' => 'after'], 'parent');
            });
            $this->fail('the outer unit must fail');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: a joined unit of work failed', $e->getMessage());
            $this->assertInstanceOf(UserError::class, $e->getPrevious());
        }
        $this->assertSame([], $this->parents($db), 'nothing of either unit is kept');
        $this->assertSame(0, (int)$db->transDepth);
        $units->run('uw_parent', fn (UnitOfWork $uow): Ref => $uow->insert('uw_parent', ['name' => 'next'], 'parent'));
        $this->assertCount(1, $this->parents($db), 'the next unit stands alone again');
    }

    /** An uncaught inner failure rolls back the outer unit and passes through unchanged. */
    #[DataProvider('modes')]
    public function testAnUncaughtNestedGuardPassesThrough(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        try {
            $units->run('uw_parent', function (UnitOfWork $uow) use ($units): void {
                $uow->insert('uw_parent', ['name' => 'outer'], 'parent');
                $units->run('uw_parent', static function (UnitOfWork $in): void {
                    $in->write('UPDATE uw_parent SET name = ? WHERE name = ?', ['x', 'nobody'], 'stale', 1, static fn (): UserError => new UserError('Changed meanwhile.', 409));
                });
            });
            $this->fail('the guard must fail the unit');
        } catch (UserError $e) {
            $this->assertSame(409, $e->getCode(), 'a guard of the joined unit runs at the outer commit');
        }
        $this->assertSame([], $this->parents($db));
    }

    /** On SQLite the callback reads under the write lock (legacy "write first"): another client cannot start a write. */
    public function testOnSqliteTheCallbackHoldsTheWriteLock(): void
    {
        [$db, $units] = $this->wire('sqlite', false);
        $other = new \SQLite3((string)$db->database);
        $other->busyTimeout(0);
        $other->enableExceptions(false);
        try {
            $units->run('uw_parent', function () use ($other): void {
                $this->assertFalse(@$other->exec('BEGIN IMMEDIATE'), 'the unit holds the write lock while it reads');
            });
            $this->assertTrue($other->exec('BEGIN IMMEDIATE'), 'and releases it at the end');
            $other->exec('ROLLBACK');
        } finally {
            $other->close();
        }
    }

    public function testARefIsResolvedOnce(): void
    {
        $ref = new Ref('items');
        $ref->resolve(4);
        $ref->resolve(4);
        $this->assertSame(4, $ref->id());
        $this->expectException(\LogicException::class);
        $ref->resolve(5);
    }

    public function testARefHasNoIdBeforeTheUnitRan(): void
    {
        $ref = new Ref('items');
        $this->assertFalse($ref->resolved());
        $this->expectException(\LogicException::class);
        $ref->id();
    }

    /** @return list<array{id:int,name:string}> */
    private function parents(BaseConnection $db): array
    {
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $db->query('SELECT id, name FROM uw_parent ORDER BY id')->getResultArray());
    }

    /** @return array{0:BaseConnection<object|resource, object|resource>,1:UnitsOfWork} */
    private function wire(string $driver, bool $batch): array
    {
        [$this->database, $db] = UowSchema::create($driver);
        $exec = new ConnectionExecutor($db);

        $this->assertFalse($batch, 'the batch path comes with BatchUnitOfWork');

        return [$db, UnitsOfWork::on($exec)];
    }
}
