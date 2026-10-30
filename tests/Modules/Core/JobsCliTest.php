<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Commands\JobsCli;
use Apotheke\Core\Jobs\Job;
use Apotheke\Core\Jobs\JobContext;
use Apotheke\Core\Jobs\JobHandler;
use Apotheke\Core\Jobs\JobQueue;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/** `php spark jobs:work`. */
final class JobsCliTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    protected function tearDown(): void
    {
        putenv('APOTHEKE_CLOCK');
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    public function testOnceRunsOneJob(): void
    {
        [$cli, $queue, $out, $err] = $this->cli();
        $a = $queue->enqueue('ok', []);
        $b = $queue->enqueue('ok', []);
        $this->assertSame(0, $cli->work(['--once']));
        $this->assertSame("job $a (ok): done\n", $this->read($out));
        $this->assertSame('', $this->read($err));
        $this->assertSame(['done', 'queued'], [$queue->find($a)?->status, $queue->find($b)?->status]);
    }

    public function testOnceOnAnEmptyQueueDoesNotWait(): void
    {
        [$cli, , $out] = $this->cli();
        $start = microtime(true);
        $this->assertSame(0, $cli->work(['--once', '--max-seconds=3']));
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertSame('', $this->read($out));
    }

    public function testMaxJobsAndAnEmptyQueue(): void
    {
        [$cli, $queue, $out] = $this->cli();
        foreach (range(1, 3) as $n) {
            $queue->enqueue('ok', []);
        }
        $this->assertSame(0, $cli->work(['--max-jobs=2', '--sleep=0']));
        $this->assertSame(2, substr_count($this->read($out), ': done'));
        $this->assertSame(0, $cli->work(['--sleep=0']), 'drains the queue and stops when it may not wait');
    }

    /** @return iterable<string,array{0:list<string>}> */
    public static function badArgs(): iterable
    {
        yield 'unknown' => [['--fast']];
        yield 'not a number' => [['--max-jobs=two']];
        yield 'negative' => [['--sleep=-1']];
        yield 'no seconds' => [['--max-seconds=0']];
        yield 'stray' => [['now']];
    }

    /** @param list<string> $args */
    #[DataProvider('badArgs')]
    public function testBadArgumentsShowTheUsage(array $args): void
    {
        [$cli, , $out, $err] = $this->cli();
        $this->assertSame(1, $cli->work($args));
        $this->assertSame('', $this->read($out));
        $this->assertStringStartsWith('Usage: php spark jobs:work', $this->read($err));
    }

    public function testHelp(): void
    {
        [$cli, , $out] = $this->cli();
        $this->assertSame(0, $cli->work(['--help']));
        $this->assertStringStartsWith('Usage: php spark jobs:work [--once] [--max-jobs=N] [--max-seconds=N] [--sleep=MS]', $this->read($out));
    }

    /** @return array{0:JobsCli,1:JobQueue,2:resource,3:resource} */
    private function cli(): array
    {
        $this->database ??= TestDatabase::create('sqlite');
        $queue = new JobQueue($this->database->db, new Clock());
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $this->assertIsResource($out);
        $this->assertIsResource($err);
        $ok = new class () implements JobHandler {
            public function run(Job $job, JobContext $context): array
            {
                return [];
            }
        };

        return [new JobsCli($queue, ['ok' => $ok], 'test-worker', $out, $err), $queue, $out, $err];
    }

    /** @param resource $stream */
    private function read(mixed $stream): string
    {
        rewind($stream);
        $text = (string)stream_get_contents($stream);
        ftruncate($stream, 0);
        rewind($stream);

        return $text;
    }
}
