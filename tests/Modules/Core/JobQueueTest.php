<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Jobs\Job;
use Apotheke\Core\Jobs\JobQueue;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * The jobs table (migration 0029) and its queue (API plan §3: imports, restores
 * and backups run as jobs). Every step is one statement guarded by its WHERE
 * (status, lease owner), so it needs no transaction and maps onto D1 as is.
 */
final class JobQueueTest extends CIUnitTestCase
{
    private const T0 = '2026-11-01 10:00:00';

    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('APOTHEKE_CLOCK=' . self::T0);
    }

    protected function tearDown(): void
    {
        putenv('APOTHEKE_CLOCK');
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function testEnqueueStoresAQueuedJob(string $driver): void
    {
        $q = $this->queue($driver);
        $id = $q->enqueue('import.commit', ['import_id' => 7, 'name' => 'Café'], 'ada');
        $job = $q->find($id);
        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame([$id, 'import.commit', 'queued', ['import_id' => 7, 'name' => 'Café'], null, 0, 3, 'ada'],
            [$job->id, $job->type, $job->status, $job->payload, $job->checkpoint, $job->attempts, $job->maxAttempts, $job->actor]);
        $this->assertSame(['created_at' => self::T0, 'updated_at' => self::T0, 'run_after' => self::T0], $this->times($id, 'created_at', 'updated_at', 'run_after'));
        $this->assertNull($q->find($id + 1));
    }

    /** @return iterable<string,array{0:string,1:array<string,mixed>}> */
    public static function badJobs(): iterable
    {
        yield 'empty type' => ['', []];
        yield 'upper case' => ['Import', []];
        yield 'space' => ['import commit', []];
        yield 'too long' => [str_repeat('a', 65), []];
        yield 'payload too large' => ['import.commit', ['x' => str_repeat('a', JobQueue::PAYLOAD_MAX)]];
    }

    /** @param array<string,mixed> $payload */
    #[DataProvider('badJobs')]
    public function testEnqueueRefusesABadTypeOrAPayloadOverTheLimit(string $type, array $payload): void
    {
        $q = $this->queue('sqlite');
        $this->expectException(InvalidArgumentException::class);
        $q->enqueue($type, $payload);
    }

    public function testThePayloadLimitIsInclusive(): void
    {
        $q = $this->queue('sqlite');
        $fill = JobQueue::PAYLOAD_MAX - strlen('{"x":""}');
        $id = $q->enqueue('a', ['x' => str_repeat('a', $fill)]);
        $this->assertSame($fill, strlen((string)$q->find($id)?->payload['x']));
        $this->assertSame(str_repeat('a', 64), $q->find($q->enqueue(str_repeat('a', 64), []))?->type, '64 characters is the longest type');
    }

    #[DataProvider('drivers')]
    public function testClaimTakesTheOldestDueJobOnce(string $driver): void
    {
        $q = $this->queue($driver);
        $later = $q->enqueue('a', [], null, 3, '2026-11-01 10:05:00');
        $first = $q->enqueue('a', ['n' => 1]);
        $second = $q->enqueue('a', ['n' => 2]);
        $job = $q->claim('w1');
        $this->assertNotNull($job);
        $this->assertSame([$first, 'running', 1, 'w1'], [$job->id, $job->status, $job->attempts, $job->leaseOwner]);
        $this->assertSame(['started_at' => self::T0, 'lease_until' => '2026-11-01 10:05:00'], $this->times($first, 'started_at', 'lease_until'));
        $this->assertSame($second, $q->claim('w2')?->id, 'the next one');
        $this->assertNull($q->claim('w3'), 'the delayed job is not due yet');
        putenv('APOTHEKE_CLOCK=2026-11-01 10:05:00');
        $this->assertSame($later, $q->claim('w3')?->id);
    }

    #[DataProvider('drivers')]
    public function testOnlyOneWorkerWinsAClaim(string $driver): void
    {
        $q = $this->queue($driver);
        $id = $q->enqueue('a', []);
        $other = new JobQueue($this->testDb()->connectAgain(), new Clock());
        $this->assertNotNull($q->tryClaim($id, 'w1'));
        $this->assertNull($other->tryClaim($id, 'w2'), 'the row is no longer queued');
        $this->assertSame('w1', $q->find($id)?->leaseOwner);
        $this->assertSame(1, $q->find($id)?->attempts);
    }

    #[DataProvider('drivers')]
    public function testAnExpiredLeaseIsTakenOverAndTheOldOwnerLosesIt(string $driver): void
    {
        $q = $this->queue($driver);
        $id = $q->enqueue('a', []);
        $old = $q->claim('w1');
        $this->assertNotNull($old);
        putenv('APOTHEKE_CLOCK=2026-11-01 10:04:59');
        $this->assertNull($q->claim('w2'), 'the lease still holds');
        putenv('APOTHEKE_CLOCK=2026-11-01 10:05:01');
        $new = $q->claim('w2');
        $this->assertSame([$id, 'w2', 2], [$new?->id, $new?->leaseOwner, $new?->attempts]);
        $this->assertFalse($q->checkpoint($old, ['n' => 1]), 'w1 lost its lease');
        $this->assertFalse($q->complete($old, []));
        $this->assertFalse($q->fail($old, 'x', true));
        $this->assertFalse($q->release($old));
        $this->assertSame(['running', 'w2', null], [$q->find($id)?->status, $q->find($id)?->leaseOwner, $q->find($id)?->checkpoint]);
    }

    /**
     * N-1243: the lease is this owner's for this attempt. A forged owner with the right attempt, and the right owner
     * with another attempt (a worker that claimed the same job again after its lease ran out), are both refused.
     */
    #[DataProvider('drivers')]
    public function testOwnerAndAttemptBothGuardTheLease(string $driver): void
    {
        $q = $this->queue($driver);
        $id = $q->enqueue('a', []);
        $job = $q->claim('w1');
        $this->assertNotNull($job);
        $forged = self::withLease($job, 'w2', $job->attempts);
        $stale = self::withLease($job, 'w1', $job->attempts + 1);
        foreach (['forged owner, same attempt' => $forged, 'same owner, other attempt' => $stale] as $case => $other) {
            $this->assertFalse($q->checkpoint($other, ['x' => 1]), $case);
            $this->assertFalse($q->complete($other, []), $case);
            $this->assertFalse($q->fail($other, 'x', false), $case);
            $this->assertFalse($q->release($other), $case);
        }
        $row = $q->find($id);
        $this->assertSame(['running', 'w1', 1, null], [$row?->status, $row?->leaseOwner, $row?->attempts, $row?->checkpoint]);
        $this->assertTrue($q->complete($job, []), 'the real lease still works');
    }

    #[DataProvider('drivers')]
    public function testAJobWhoseLastAttemptExpiredFails(string $driver): void
    {
        $q = $this->queue($driver);
        $id = $q->enqueue('a', [], null, 1);
        $this->assertNotNull($q->claim('w1'));
        putenv('APOTHEKE_CLOCK=2026-11-01 10:05:01');
        $this->assertNull($q->claim('w2'), 'no attempt left');
        $job = $q->find($id);
        $this->assertSame(['failed', 'The job stopped without finishing.', null], [$job?->status, $job?->error, $job?->leaseOwner]);
        $this->assertSame(['finished_at' => '2026-11-01 10:05:01'], $this->times($id, 'finished_at'));
    }

    #[DataProvider('drivers')]
    public function testAnExpiredLastAttemptIsNotClaimedEvenDirectly(string $driver): void
    {
        $q = $this->queue($driver);
        $id = $q->enqueue('a', [], null, 1);
        $this->assertNotNull($q->tryClaim($id, 'w1'));
        putenv('APOTHEKE_CLOCK=2026-11-01 10:05:01');
        $this->assertNull($q->tryClaim($id, 'w2'), 'no attempt left, before the sweep has run');
        $this->assertSame('w1', $q->find($id)?->leaseOwner);
    }

    #[DataProvider('drivers')]
    public function testTheRetryWaitGrowsWithEachAttempt(string $driver): void
    {
        $q = $this->queue($driver);
        $id = $q->enqueue('a', []);
        $this->assertTrue($q->fail($q->claim('w') ?? $this->fail('claim'), 'x', true));
        $this->assertSame(['run_after' => '2026-11-01 10:00:30'], $this->times($id, 'run_after'));
        putenv('APOTHEKE_CLOCK=2026-11-01 10:00:30');
        $this->assertTrue($q->fail($q->claim('w') ?? $this->fail('claim'), 'x', true));
        $this->assertSame(['run_after' => '2026-11-01 10:01:30', 'started_at' => self::T0], $this->times($id, 'run_after', 'started_at'), '60 s after the second; started_at is the first start');
    }

    #[DataProvider('drivers')]
    public function testCheckpointCompleteAndFail(string $driver): void
    {
        $q = $this->queue($driver);
        $id = $q->enqueue('a', []);
        $job = $q->claim('w1');
        $this->assertNotNull($job);
        putenv('APOTHEKE_CLOCK=2026-11-01 10:03:00');
        $this->assertTrue($q->checkpoint($job, ['row' => 120]));
        $this->assertSame(['row' => 120], $q->find($id)?->checkpoint);
        $this->assertSame(['lease_until' => '2026-11-01 10:08:00', 'updated_at' => '2026-11-01 10:03:00'], $this->times($id, 'lease_until', 'updated_at'), 'a checkpoint renews the lease');
        $this->assertTrue($q->complete($job, ['rows' => 200]));
        $done = $q->find($id);
        $this->assertSame(['done', ['rows' => 200], null, null], [$done?->status, $done?->result, $done?->leaseOwner, $done?->error]);
        $this->assertSame(['finished_at' => '2026-11-01 10:03:00', 'lease_until' => null], $this->times($id, 'finished_at', 'lease_until'));
        $this->assertFalse($q->complete($job, []), 'once');

        $retry = $q->enqueue('a', [], null, 2);
        $job = $q->claim('w1');
        $this->assertSame($retry, $job?->id);
        $this->assertTrue($q->fail($job, 'Try again.', true));
        $this->assertSame(['queued', 'Try again.', null, 1], [$q->find($retry)?->status, $q->find($retry)?->error, $q->find($retry)?->leaseOwner, $q->find($retry)?->attempts]);
        $this->assertSame(['run_after' => '2026-11-01 10:03:30'], $this->times($retry, 'run_after'), 'retried after 30 s per attempt');
        $this->assertNull($q->claim('w1'), 'not yet');
        putenv('APOTHEKE_CLOCK=2026-11-01 10:03:30');
        $job = $q->claim('w1');
        $this->assertSame([$retry, 2], [$job?->id, $job?->attempts]);
        $this->assertTrue($q->fail($job, 'Still broken.', true), 'no attempt left: failed, even when a retry was asked for');
        $this->assertSame(['failed', 'Still broken.'], [$q->find($retry)?->status, $q->find($retry)?->error]);
        $this->assertSame(['finished_at' => '2026-11-01 10:03:30'], $this->times($retry, 'finished_at'));

        $final = $q->enqueue('a', []);
        $job = $q->claim('w1');
        $this->assertTrue($q->fail($job, 'Bad file.', false));
        $this->assertSame(['failed', 1], [$q->find($final)?->status, $q->find($final)?->attempts], 'no retry asked for');
    }

    #[DataProvider('drivers')]
    public function testReleaseHandsTheJobBackWithoutUsingAnAttempt(string $driver): void
    {
        $q = $this->queue($driver);
        $id = $q->enqueue('a', [], null, 1);
        $job = $q->claim('w1');
        $this->assertNotNull($job);
        $this->assertTrue($q->checkpoint($job, ['row' => 5]));
        $this->assertTrue($q->release($job));
        $back = $q->find($id);
        $this->assertSame(['queued', 0, null, ['row' => 5]], [$back?->status, $back?->attempts, $back?->leaseOwner, $back?->checkpoint]);
        $again = $q->claim('w2');
        $this->assertSame([$id, 1, ['row' => 5]], [$again?->id, $again?->attempts, $again?->checkpoint], 'the next worker resumes from the checkpoint');
    }

    #[DataProvider('drivers')]
    public function testPruneDeletesOnlyOldFinishedJobs(string $driver): void
    {
        $q = $this->queue($driver);
        $old = $q->enqueue('a', []);
        $q->complete($q->claim('w') ?? $this->fail('claim'), []);
        $oldFailed = $q->enqueue('a', []);
        $q->fail($q->claim('w') ?? $this->fail('claim'), 'x', false);
        $waiting = $q->enqueue('a', []);
        putenv('APOTHEKE_CLOCK=2026-11-08 10:00:01');
        $recent = $q->enqueue('a', []);
        $q->complete($q->claim('w') ?? $this->fail('claim'), []);
        $this->assertSame(2, $q->prune(7));
        $this->assertSame([null, null], [$q->find($old), $q->find($oldFailed)]);
        $this->assertNotNull($q->find($waiting), 'not finished: kept however old');
        $this->assertNotNull($q->find($recent));
    }

    public function testEveryStatementStaysWithinD1Limits(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/modules/Core/Jobs/JobQueue.php');
        $this->assertDoesNotMatchRegularExpression('/\b(BEGIN|SAVEPOINT|COMMIT|ROLLBACK)\b|trans(Begin|Start|Complete|Commit)\(/', $source, 'no transaction: one guarded statement per step (D1)');
    }

    private static function withLease(Job $job, string $owner, int $attempts): Job
    {
        return new Job($job->id, $job->type, $job->status, $job->payload, $job->checkpoint, $job->result, $job->error, $attempts, $job->maxAttempts, $job->actor, $owner);
    }

    private function queue(string $driver): JobQueue
    {
        $this->database = TestDatabase::create($driver);

        return new JobQueue($this->database->db, new Clock());
    }

    private function testDb(): TestDatabase
    {
        $this->assertNotNull($this->database);

        return $this->database;
    }

    /** @return array<string,string|null> */
    private function times(int $id, string ...$cols): array
    {
        $row = $this->testDb()->db->query('SELECT ' . implode(', ', $cols) . ' FROM jobs WHERE id = ?', [$id])->getRowArray();

        return array_map(static fn ($v): ?string => $v === null ? null : (string)$v, (array)$row);
    }
}
