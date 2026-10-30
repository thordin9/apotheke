<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Jobs\Job;
use Apotheke\Core\Jobs\JobContext;
use Apotheke\Core\Jobs\JobFailed;
use Apotheke\Core\Jobs\JobHandler;
use Apotheke\Core\Jobs\JobInterrupted;
use Apotheke\Core\Jobs\JobQueue;
use Apotheke\Core\Jobs\JobRunner;
use Apotheke\Core\Libraries\Clock;
use Closure;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Tests\Support\TestDatabase;

/** The jobs runner behind `spark jobs:work`: one job at a time, resumable from its checkpoint. */
final class JobRunnerTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    private ?JobQueue $queue = null;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('APOTHEKE_CLOCK=2026-11-01 10:00:00');
        $this->database = TestDatabase::create('sqlite');
        $this->queue = new JobQueue($this->database->db, new Clock());
    }

    protected function tearDown(): void
    {
        putenv('APOTHEKE_CLOCK');
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    public function testARunStoresTheResult(): void
    {
        $id = $this->q()->enqueue('sum', ['a' => 2, 'b' => 3]);
        $runner = $this->runner(['sum' => $this->handler(static fn (Job $job): array => ['sum' => $job->payload['a'] + $job->payload['b']])]);
        $this->assertSame(['id' => $id, 'type' => 'sum', 'status' => 'done'], $runner->runOnce());
        $this->assertSame(['done', ['sum' => 5]], [$this->q()->find($id)?->status, $this->q()->find($id)?->result]);
        $this->assertNull($runner->runOnce(), 'nothing left');
    }

    public function testAnUnknownTypeFailsWithoutARetry(): void
    {
        $id = $this->q()->enqueue('nope', []);
        $this->assertSame(['id' => $id, 'type' => 'nope', 'status' => 'failed'], $this->runner([])->runOnce());
        $this->assertSame(['failed', 'Unknown job type.', 1], [$this->q()->find($id)?->status, $this->q()->find($id)?->error, $this->q()->find($id)?->attempts]);
    }

    public function testJobFailedIsFinalAndShowsItsMessage(): void
    {
        $id = $this->q()->enqueue('a', []);
        $this->runner(['a' => $this->handler(static fn (): never => throw new JobFailed('The file is not a CSV file.'))])->runOnce();
        $this->assertSame(['failed', 'The file is not a CSV file.'], [$this->q()->find($id)?->status, $this->q()->find($id)?->error]);
    }

    public function testAnyOtherErrorIsRetriedAndItsMessageIsOnlyLogged(): void
    {
        $id = $this->q()->enqueue('a', []);
        $runner = $this->runner(['a' => $this->handler(static fn (): never => throw new RuntimeException('SQLSTATE[08006] host=db password=placeholder'))]);
        $this->assertSame('queued', $runner->runOnce()['status'] ?? null);
        $job = $this->q()->find($id);
        $this->assertSame(['queued', JobRunner::INTERNAL_ERROR], [$job?->status, $job?->error], 'internals never reach the row an API client reads');
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('job ' . $id . ' (a) attempt 1: RuntimeException: SQLSTATE[08006]', $this->logged[0]);
    }

    public function testARetryResumesFromTheCheckpoint(): void
    {
        $id = $this->q()->enqueue('rows', ['total' => 4]);
        $seen = [];
        $runner = $this->runner(['rows' => $this->handler(static function (Job $job, JobContext $ctx) use (&$seen): array {
            $from = (int)($ctx->state()['next'] ?? 0);
            $seen[] = $from;
            for ($row = $from; $row < $job->payload['total']; $row++) {
                if ($row === 2 && $from === 0) {
                    throw new RuntimeException('connection lost');
                }
                $ctx->checkpoint(['next' => $row + 1]);
            }

            return ['rows' => $job->payload['total']];
        })]);
        $this->assertSame('queued', $runner->runOnce()['status'] ?? null);
        putenv('APOTHEKE_CLOCK=2026-11-01 10:00:30');
        $this->assertSame('done', $runner->runOnce()['status'] ?? null);
        $this->assertSame([0, 2], $seen, 'the second attempt starts at the checkpoint');
        $this->assertSame(['rows' => 4], $this->q()->find($id)?->result);
    }

    public function testAStopCheckpointsAndHandsTheJobBack(): void
    {
        $id = $this->q()->enqueue('long', []);
        $stop = false;
        $runner = $this->runner(['long' => $this->handler(static function (Job $job, JobContext $ctx) use (&$stop): array {
            $ctx->checkpoint(['step' => 1]);
            if ($ctx->state() !== ['step' => 1]) {
                throw new JobFailed('state() is not the last checkpoint');
            }
            $stop = true;   // SIGTERM arrives
            if ($ctx->stopRequested()) {
                $ctx->checkpoint(['step' => 2]);
                throw new JobInterrupted();
            }

            return [];
        })], static function () use (&$stop): bool {
            return $stop;
        });
        $this->assertSame(['id' => $id, 'type' => 'long', 'status' => 'queued'], $runner->runOnce());
        $job = $this->q()->find($id);
        $this->assertSame(['queued', 0, ['step' => 2], null], [$job?->status, $job?->attempts, $job?->checkpoint, $job?->error], 'not counted as an attempt');
    }

    public function testALostLeaseLeavesTheRowToItsNewOwner(): void
    {
        $id = $this->q()->enqueue('slow', []);
        $q = $this->q();
        $runner = $this->runner(['slow' => $this->handler(static function (Job $job, JobContext $ctx) use ($q): array {
            putenv('APOTHEKE_CLOCK=2026-11-01 10:06:00');
            $q->claim('other');   // another worker took over the expired lease
            $ctx->checkpoint(['n' => 1]);

            return ['never' => true];
        })]);
        $this->assertSame(['id' => $id, 'type' => 'slow', 'status' => 'lost'], $runner->runOnce());
        $job = $q->find($id);
        $this->assertSame(['running', 'other', null, null], [$job?->status, $job?->leaseOwner, $job?->checkpoint, $job?->result]);
    }

    public function testWorkStopsAtItsLimits(): void
    {
        foreach (range(1, 3) as $n) {
            $this->q()->enqueue('a', ['n' => $n]);
        }
        $runner = $this->runner(['a' => $this->handler(static fn (): array => [])]);
        $done = [];
        $this->assertSame(2, $runner->work(2, 60, 0, static function (array $r) use (&$done): void {
            $done[] = $r['status'];
        }));
        $this->assertSame(['done', 'done'], $done);
        $this->assertSame(1, $runner->work(0, 60, 0), 'once the queue is empty, with no wait');
        $stop = true;
        $this->q()->enqueue('a', []);
        $stopped = $this->runner(['a' => $this->handler(static fn (): array => [])], static fn (): bool => $stop);
        $this->assertSame(0, $stopped->work(0, 60, 0), 'a stop before the next job');
    }

    private function q(): JobQueue
    {
        $this->assertNotNull($this->queue);

        return $this->queue;
    }

    /** @param array<string,JobHandler> $handlers */
    private function runner(array $handlers, ?Closure $stop = null): JobRunner
    {
        return new JobRunner($this->q(), $handlers, 'test-worker', $stop ?? static fn (): bool => false, function (string $line): void {
            $this->logged[] = $line;
        });
    }

    /** @param Closure(Job, JobContext): array<string,mixed> $fn */
    private function handler(Closure $fn): JobHandler
    {
        return new class ($fn) implements JobHandler {
            /** @param Closure(Job, JobContext): array<string,mixed> $fn */
            public function __construct(private readonly Closure $fn)
            {
            }

            public function run(Job $job, JobContext $context): array
            {
                return ($this->fn)($job, $context);
            }
        };
    }
}
