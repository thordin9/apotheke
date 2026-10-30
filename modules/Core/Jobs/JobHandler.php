<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

/**
 * Runs one type of job. A handler resumes from $context->state() (its last
 * checkpoint), checkpoints as it goes, and returns the job's result. Throw
 * JobFailed for a failure a retry cannot fix (its message is shown to the
 * client), JobInterrupted after a final checkpoint when stopRequested() is
 * true; anything else is retried while attempts are left.
 */
interface JobHandler
{
    /** @return array<string,mixed> the result */
    public function run(Job $job, JobContext $context): array;
}
