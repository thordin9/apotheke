<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

use Closure;

/** What a running handler may do with its job: read and write its checkpoint, and see a stop request. */
final class JobContext
{
    /** @var array<string,mixed>|null */
    private ?array $state;

    /** @param Closure():bool $stop */
    public function __construct(private readonly JobQueue $queue, private readonly Job $job, private readonly Closure $stop)
    {
        $this->state = $job->checkpoint;
    }

    /** @return array<string,mixed>|null the last checkpoint, of this attempt or an earlier one */
    public function state(): ?array
    {
        return $this->state;
    }

    /**
     * Saves $state and renews the lease.
     *
     * @param array<string,mixed> $state
     * @throws JobLeaseLost when another worker has taken the job over
     */
    public function checkpoint(array $state): void
    {
        if (!$this->queue->checkpoint($this->job, $state)) {
            throw new JobLeaseLost('job ' . $this->job->id . ': lease lost');
        }
        $this->state = $state;
    }

    /** Whether the worker was asked to stop (SIGTERM): checkpoint, then throw JobInterrupted. */
    public function stopRequested(): bool
    {
        return ($this->stop)();
    }
}
