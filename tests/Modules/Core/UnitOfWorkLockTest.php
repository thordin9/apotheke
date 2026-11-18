<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Barcode;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Stocktake\Stocktakes;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Tests\Support\TestDatabase;

/**
 * The PostgreSQL lock of a unit of work (L-1664, N-1665): a transaction
 * lock, so another client can take it as soon as the unit committed or
 * failed; a unit joined to a caller's transaction holds it until the outer
 * commit; a busy key answers 503 after the wait; other keys do not wait.
 */
final class UnitOfWorkLockTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @var BaseConnection<object|resource, object|resource>|null */
    private ?BaseConnection $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('pgsql', TestDatabase::drivers(), true)) {
            $this->markTestSkipped('PostgreSQL only');
        }
        $this->database = TestDatabase::create('pgsql');
        $this->other = $this->database->connectAgain();
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    public function testTheLockIsReleasedWhenTheUnitEnds(): void
    {
        $units = $this->units();
        $units->run('saved_filters', function (UnitOfWork $uow): void {
            $this->assertFalse($this->otherGets('saved_filters'), 'held while the unit runs');
        });
        $this->assertTrue($this->otherGets('saved_filters'), 'free right after the commit');
        try {
            $units->run('saved_filters', static function (UnitOfWork $uow): void {
                throw new RuntimeException('no');
            });
        } catch (RuntimeException) {
        }
        $this->assertTrue($this->otherGets('saved_filters'), 'free right after a failed unit');
    }

    public function testAJoinedUnitHoldsTheLockUntilTheOuterCommit(): void
    {
        $db = $this->db();
        $units = $this->units();
        Writes::transaction($db, function () use ($units): void {
            $units->run('items', function (UnitOfWork $uow): void {
                $this->assertFalse($this->otherGets('items', '7'), 'a joined unit takes the lock too');
            }, '7');
            $this->assertFalse($this->otherGets('items', '7'), 'and keeps it until the outer commit');
        });
        $this->assertTrue($this->otherGets('items', '7'));
    }

    public function testABusyKeyAnswers503AfterTheWaitAndOtherKeysDoNotWait(): void
    {
        $this->assertNotNull($this->database);
        $wait = $this->database->holdInAnotherClient([['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('recent_views', 'd:a')]], 1500);
        try {
            $noWait = UnitsOfWork::on(new ConnectionExecutor($this->db()), null, 0);
            $this->assertSame(1, $noWait->run('recent_views', function (UnitOfWork $uow): int {
                $this->assertFalse($this->otherGets('recent_views', 'd:b'), 'the unit holds its own key\'s lock while it runs');

                return 1;
            }, 'd:b'), 'another key does not wait: it gets its lock with no wait at all');
            $this->assertTrue($this->otherGets('recent_views', 'd:b'), 'and frees it after the unit');
            $this->refusedAtOnce(static fn (): int => $noWait->run('recent_views', static fn (UnitOfWork $uow): int => 1, 'd:a'));
            $units = UnitsOfWork::on(new ConnectionExecutor($this->db()), null, 150);
            $t = hrtime(true);
            try {
                $units->run('recent_views', static fn (UnitOfWork $uow): int => 1, 'd:a');
                $this->fail('a busy key must answer 503');
            } catch (UserError $e) {
                $this->assertSame([503, 'The database is busy. Try again in a moment.'], [$e->getCode(), $e->getMessage()]);
            }
            $this->assertGreaterThanOrEqual(150, (hrtime(true) - $t) / 1e6, 'it waits the whole budget');
            $this->assertFalse($this->otherGets('recent_views', 'd:a'), 'it gives up while the other client still holds the key');
            $this->assertSame(0, (int)$this->db()->transDepth);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame(1, $this->units()->run('recent_views', static fn (UnitOfWork $uow): int => 1, 'd:a'), 'free again once the other client committed');
    }

    /**
     * L-1743: a local batch on PostgreSQL takes the unit's lock before it
     * runs. While another process holds the key, the batch waits and then
     * answers 503 with nothing written; another key does not wait; once the
     * other process committed, the batch runs, and the lock is free after it.
     */
    public function testALocalBatchTakesTheUnitLock(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Lamp', 1, '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
        $set = static fn (int $q): \Closure => static function (UnitOfWork $uow) use ($q): int {
            $uow->write('UPDATE items SET quantity = ? WHERE id = 1', [$q], 'set quantity');

            return $q;
        };
        $quantity = static fn (): int => (int)Writes::rows($db, 'SELECT quantity FROM items WHERE id = 1')[0]['quantity'];
        $this->assertNotNull($this->database);
        $wait = $this->database->holdInAnotherClient([['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('items', '1')]], 1500);
        try {
            $noWait = UnitsOfWork::on(new LocalBatchExecutor($db), null, 0);
            $this->assertSame(2, $this->heldDuring('items', ['2'], static fn (): int => $noWait->run('items', $set(2), '2')),
                'another key does not wait: it gets its lock with no wait at all');
            $this->refusedAtOnce(static fn (): int => $noWait->run('items', $set(3), '1'));
            $units = UnitsOfWork::on(new LocalBatchExecutor($db), null, 150);
            $t = hrtime(true);
            try {
                $units->run('items', $set(3), '1');
                $this->fail('a busy key must answer 503 in a batch too');
            } catch (UserError $e) {
                $this->assertSame([503, 'The database is busy. Try again in a moment.'], [$e->getCode(), $e->getMessage()]);
            }
            $this->assertGreaterThanOrEqual(150, (hrtime(true) - $t) / 1e6, 'it waits the whole budget');
            $this->assertSame(2, $quantity(), 'the refused batch wrote nothing');
            $this->assertSame(0, (int)$db->transDepth);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame(4, UnitsOfWork::on(new LocalBatchExecutor($db))->run('items', $set(4), '1'));
        $this->assertSame(4, $quantity());
        $this->assertTrue($this->otherGets('items', '1'), 'free right after the batch');
    }

    /**
     * M-1771: a multi-key unit takes each key's lock; holding one of them
     * blocks the unit, and another key alone does not.
     */
    public function testAMultiKeyUnitTakesEachKeyLock(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Lamp', 1, '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
        $this->assertSame(['1', '2'], UnitsOfWork::normalizeKeys(['2', '1', '2']));
        $set = static fn (int $q): \Closure => static function (UnitOfWork $uow) use ($q): int {
            $uow->write('UPDATE items SET quantity = ? WHERE id = 1', [$q], 'set quantity');

            return $q;
        };
        $this->assertNotNull($this->database);
        $wait = $this->database->holdInAnotherClient([['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('items', '1')]], 1500);
        try {
            $noWait = UnitsOfWork::on(new LocalBatchExecutor($db), null, 0);
            $this->assertSame(7, $this->heldDuring('items', ['3', '4'], static fn (): int => $noWait->run('items', $set(7), ['3', '4'])),
                'unrelated keys do not wait: they get their locks with no wait at all');
            $this->refusedAtOnce(static fn (): int => $noWait->run('items', $set(8), ['2', '1']));
            $units = UnitsOfWork::on(new LocalBatchExecutor($db), null, 150);
            $t = hrtime(true);
            try {
                $units->run('items', $set(8), ['2', '1']);
                $this->fail('holding one of the keys must answer 503');
            } catch (UserError $e) {
                $this->assertSame(503, $e->getCode());
            }
            $this->assertGreaterThanOrEqual(150, (hrtime(true) - $t) / 1e6, 'it waits the whole budget');
            $this->assertSame(7, (int)Writes::rows($db, 'SELECT quantity FROM items WHERE id = 1')[0]['quantity']);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertTrue($this->otherGets('items', '1'));
        $this->assertTrue($this->otherGets('items', '2'));
    }

    /**
     * N-1778: a two-key unit shares one wait budget; blocked on the second
     * key after spending time on the first, it answers 503 within ~lockWaitMs
     * total (not lockWaitMs per key). Key 2 is free again before a fresh
     * per-key budget would end, so only the shared budget answers 503.
     */
    public function testAMultiKeyUnitSharesOneWaitBudget(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Lamp', 1, '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
        $set = static fn (int $q): \Closure => static function (UnitOfWork $uow) use ($q): int {
            $uow->write('UPDATE items SET quantity = ? WHERE id = 1', [$q], 'set quantity');

            return $q;
        };
        $this->assertNotNull($this->database);
        // Key 1 is held for 800 ms of the 1000 ms budget, key 2 for 1400 ms: the shared budget ends while key 2 is
        // still held (400 ms early), a fresh budget for key 2 would end 400 ms after key 2 is free and take it.
        $wait1 = $this->database->holdInAnotherClient([['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('items', '1')]], 800);
        $wait2 = $this->database->holdInAnotherClient([['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('items', '2')]], 1400);
        try {
            $units = UnitsOfWork::on(new LocalBatchExecutor($db), null, 1000);
            $t = hrtime(true);
            try {
                $units->run('items', $set(9), ['1', '2']);
                $this->fail('a busy second key must answer 503');
            } catch (UserError $e) {
                $this->assertSame(503, $e->getCode());
            }
            $this->assertGreaterThanOrEqual(1000, (hrtime(true) - $t) / 1e6, 'it waits the whole shared budget');
            $this->assertFalse($this->otherGets('items', '2'), 'it gives up while key 2 is still held, not after a fresh budget for it');
            $this->assertSame(1, (int)Writes::rows($db, 'SELECT quantity FROM items WHERE id = 1')[0]['quantity']);
        } finally {
            $this->assertStringContainsString('committed', $wait1());
            $this->assertStringContainsString('committed', $wait2());
        }
    }

    /**
     * L-1743: the lock is held while the batch runs. Another process keeps
     * the item's row locked, so the batch's update waits; a third process
     * that tries the unit's lock meanwhile does not get it.
     */
    public function testALocalBatchHoldsTheLockWhileItRuns(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Lamp', 1, '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
        $db->query('CREATE TABLE lock_probe (got boolean NOT NULL)');
        $this->assertNotNull($this->database);
        // The row is held long enough for the probe client to start and see the lock, also on a loaded runner.
        $row = $this->database->holdInAnotherClient([['UPDATE items SET quantity = 9 WHERE id = 1', []]], 2000);
        // L-1746: wait until the batch holds the unit lock (pg_locks), then probe; a fixed sleep was flaky.
        $keys = UnitsOfWork::lockKeys('items', '1');
        $probe = $this->database->holdInAnotherClient([['__wait_advisory__', [$keys[0], $keys[1], 5000]],
            ['INSERT INTO lock_probe (got) SELECT pg_try_advisory_xact_lock(?, ?)', $keys]], 0, false);
        try {
            UnitsOfWork::on(new LocalBatchExecutor($db))->run('items', static function (UnitOfWork $uow): void {
                $uow->write('UPDATE items SET quantity = 2 WHERE id = 1', [], 'set quantity');
            }, '1');
        } finally {
            $this->assertStringContainsString('committed', $row());
            $this->assertStringContainsString('committed', $probe());
        }
        $this->assertSame([[false]], array_map(static fn (array $r): array => [in_array($r['got'], [true, 't', 1, '1'], true)], Writes::rows($db, 'SELECT got FROM lock_probe')),
            'the probe ran while the batch waited, and the batch held the lock');
        $this->assertSame([['2']], array_map(static fn (array $r): array => [(string)$r['quantity']], Writes::rows($db, 'SELECT quantity FROM items')));
    }

    /** L-1743: a local batch joined to a caller's transaction keeps the unit's lock until the outer commit. */
    public function testAJoinedLocalBatchHoldsTheLockUntilTheOuterCommit(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Lamp', 1, '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        Writes::transaction($db, function () use ($units): void {
            $units->run('items', static function (UnitOfWork $uow): void {
                $uow->write('UPDATE items SET quantity = 2 WHERE id = 1', [], 'set quantity');
            }, '1');
            $this->assertFalse($this->otherGets('items', '1'), 'held after the batch, until the outer commit');
        });
        $this->assertTrue($this->otherGets('items', '1'));
    }

    /** N-1665: a stocktake start locks its location only. */
    public function testAStocktakeStartLocksItsLocationOnly(): void
    {
        $db = $this->db();
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $taxonomy = new TaxonomyRepository($db, $clock, $history);
        [$shed, $attic] = [$taxonomy->create('location', 'Shed')['id'], $taxonomy->create('location', 'Attic')['id']];
        $this->assertNotNull($this->database);
        $takes = new Stocktakes($db, $clock, $taxonomy, $this->database->items($db), $history, new Barcode($db), UnitsOfWork::on(new ConnectionExecutor($db), null, 150));
        $wait = $this->database->holdInAnotherClient([['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('stocktakes', (string)$shed)]], 1500);
        try {
            $noWait = new Stocktakes($db, $clock, $taxonomy, $this->database->items($db), $history, new Barcode($db), UnitsOfWork::on(new ConnectionExecutor($db), null, 0));
            $this->assertGreaterThan(0, $this->heldDuring('stocktakes', [(string)$attic], static fn (): int => $noWait->start($attic)),
                'another location does not wait: it gets its lock with no wait at all');
            $this->refusedAtOnce(static fn (): int => $noWait->start($shed));
            try {
                $takes->start($shed);
                $this->fail('a busy location must answer 503');
            } catch (UserError $e) {
                $this->assertSame(503, $e->getCode());
            }
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
    }

    /**
     * Runs $unit in a caller's transaction, which keeps the unit's locks until its commit: the other client cannot
     * take any of $keys before that commit, and takes each of them after it.
     *
     * @param list<string> $keys
     */
    private function heldDuring(string $table, array $keys, callable $unit): mixed
    {
        $out = Writes::transaction($this->db(), function () use ($table, $keys, $unit): mixed {
            $out = $unit();
            foreach ($keys as $key) {
                $this->assertFalse($this->otherGets($table, $key), 'the unit took the lock of ' . $table . ' ' . $key);
            }

            return $out;
        });
        foreach ($keys as $key) {
            $this->assertTrue($this->otherGets($table, $key), 'free after the commit: ' . $table . ' ' . $key);
        }

        return $out;
    }

    /** With no wait budget, a key the other client holds answers 503 instead of waiting for it. */
    private function refusedAtOnce(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('a busy key with no wait budget must answer 503, not wait for the other client');
        } catch (UserError $e) {
            $this->assertSame(503, $e->getCode());
        }
    }

    private function otherGets(string $table, string $key = ''): bool
    {
        $this->assertNotNull($this->other);

        return in_array(Writes::rows($this->other, 'SELECT pg_try_advisory_xact_lock(?, ?) AS ok', UnitsOfWork::lockKeys($table, $key))[0]['ok'] ?? null, [true, 't'], true);
    }

    private function units(): UnitsOfWork
    {
        return UnitsOfWork::on(new ConnectionExecutor($this->db()));
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        $this->assertNotNull($this->database);

        return $this->database->db;
    }
}
