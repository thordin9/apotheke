<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

use Closure;
use Throwable;

/** Runs queued jobs one at a time (`php spark jobs:work`). */
final class JobRunner
{
    public const UNKNOWN_TYPE = 'Unknown job type.';

    public const INTERNAL_ERROR = 'The job stopped on an internal error. The server log has the details.';

    /** @var Closure():bool */
    private readonly Closure $stop;

    /** @var Closure(string):void */
    private readonly Closure $log;

    /**
     * @param array<string,JobHandler> $handlers by job type
     * @param (Closure():bool)|null $stop true once the worker should stop (SIGTERM)
     * @param (Closure(string):void)|null $log where an unexpected error's details go (never the jobs row); error_log() when null
     */
    public function __construct(private readonly JobQueue $queue, private readonly array $handlers, private readonly string $owner, ?Closure $stop = null, ?Closure $log = null)
    {
        $this->stop = $stop ?? static fn (): bool => false;
        $this->log = $log ?? static function (string $line): void {
            error_log($line);   // the CLI's stderr: the worker's log
        };
    }

    /** @return array{id:int,type:string,status:string}|null the job run and where it ended (queued, done, failed or lost); null when none was due */
    public function runOnce(): ?array
    {
        $job = $this->queue->claim($this->owner);
        if ($job === null) {
            return null;
        }

        return ['id' => $job->id, 'type' => $job->type, 'status' => $this->run($job)];
    }

    /**
     * Runs jobs until $maxJobs ran (0: no limit), $maxSeconds passed, a stop was asked for, or, with $idleMs 0, the
     * queue is empty. Returns how many ran.
     *
     * @param (Closure(array{id:int,type:string,status:string}):void)|null $onJob
     */
    public function work(int $maxJobs, int $maxSeconds, int $idleMs, ?Closure $onJob = null): int
    {
        $start = microtime(true);
        $ran = 0;
        while (!($this->stop)() && ($maxJobs <= 0 || $ran < $maxJobs) && microtime(true) - $start < $maxSeconds) {
            $result = $this->runOnce();
            if ($result === null) {
                if ($idleMs <= 0) {
                    break;
                }
                usleep($idleMs * 1000);
                continue;
            }
            $ran++;
            if ($onJob !== null) {
                $onJob($result);
            }
        }

        return $ran;
    }

    private function run(Job $job): string
    {
        $handler = $this->handlers[$job->type] ?? null;
        if ($handler === null) {
            return $this->queue->fail($job, self::UNKNOWN_TYPE, false) ? 'failed' : 'lost';
        }
        try {
            $result = $handler->run($job, new JobContext($this->queue, $job, $this->stop));
        } catch (JobLeaseLost) {
            return 'lost';
        } catch (JobInterrupted) {
            return $this->queue->release($job) ? 'queued' : 'lost';
        } catch (JobFailed $e) {
            return $this->queue->fail($job, $e->getMessage(), false) ? 'failed' : 'lost';
        } catch (Throwable $e) {
            ($this->log)('job ' . $job->id . ' (' . $job->type . ') attempt ' . $job->attempts . ': ' . $e::class . ': ' . $e->getMessage());
            if (!$this->queue->fail($job, self::INTERNAL_ERROR, true)) {
                return 'lost';
            }

            return $job->attempts < $job->maxAttempts ? 'queued' : 'failed';
        }

        return $this->queue->complete($job, $result) ? 'done' : 'lost';
    }
}
