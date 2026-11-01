<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
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
            $units = UnitsOfWork::on(new ConnectionExecutor($this->db()), null, 150);
            $t = hrtime(true);
            $units->run('recent_views', static fn (UnitOfWork $uow): int => 1, 'd:b');
            $this->assertLessThan(100, (hrtime(true) - $t) / 1e6, 'another key does not wait');
            $t = hrtime(true);
            try {
                $units->run('recent_views', static fn (UnitOfWork $uow): int => 1, 'd:a');
                $this->fail('a busy key must answer 503');
            } catch (UserError $e) {
                $this->assertSame([503, 'The database is busy. Try again in a moment.'], [$e->getCode(), $e->getMessage()]);
            }
            $ms = (hrtime(true) - $t) / 1e6;
            $this->assertGreaterThanOrEqual(150, $ms);
            $this->assertLessThan(1000, $ms, 'it gives up after the wait, before the other client commits');
            $this->assertSame(0, (int)$this->db()->transDepth);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame(1, $this->units()->run('recent_views', static fn (UnitOfWork $uow): int => 1, 'd:a'), 'free again once the other client committed');
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
