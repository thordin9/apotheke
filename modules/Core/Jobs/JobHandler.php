<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

/**
 * Runs one type of job. A handler resumes from $context->state() (its last
 * checkpoint), checkpoints as it goes, and returns the job's result. Throw
 * JobFailed for a failure a retry cannot fix (its message is shown to the
 * client), JobInterrupted after a final checkpoint when stopRequested() is
 * true; anything else is retried while attempts are left.
 *
 * Rules for every handler (N-1245; the P3 import and restore handlers first):
 * - Checkpoint well within the lease (JobQueue's leaseSeconds, five minutes in Config\Services::jobQueue()): every
 *   checkpoint renews it, so no step between two checkpoints may run anywhere near that long. A worker whose lease
 *   ran out loses the job to the next one (JobLeaseLost on its next checkpoint).
 * - Make every step safe to repeat: a step can run again after a crash, a SIGTERM without pcntl or a lost lease,
 *   because the next attempt resumes from the last checkpoint, not from where the step stopped. Write the
 *   checkpoint after the step's own effect, and make that effect idempotent (upsert, or skip what is already done).
 * - Keep a checkpoint small (it is JSON in one row) and the result likewise.
 */
interface JobHandler
{
    /** @return array<string,mixed> the result */
    public function run(Job $job, JobContext $context): array;
}
