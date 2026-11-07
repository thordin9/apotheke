<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

/**
 * Reconcile a job row with the record that actually says what happened.
 * It does not run the work again.
 */
interface JobRecovery
{
    public function recover(Job $job): Job;

    /** Reconcile rows a worker can see before it claims anything else. */
    public function sweep(JobQueue $queue): void;
}
