<?php

declare(strict_types=1);

namespace Apotheke\Backup\Jobs;

use Apotheke\Backup\RestoreUploads;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Jobs\Job;
use Apotheke\Core\Jobs\JobQueue;
use Apotheke\Core\Jobs\JobRecovery;

/**
 * The upload manifest is the record of the restore. A job read, and a worker
 * before it claims, copy that outcome onto the job. An upload that is still
 * locked, or a lease that was renewed, stays running. A claim that died before
 * applying is queued again, as the same job, once that lease has ended and the
 * upload is free and still ready. A manifest that already says restored is
 * stored on the job without running the restore. A finished job is left as it is.
 */
final class RestoreConfirmRecovery implements JobRecovery
{
    public function __construct(private readonly JobQueue $queue, private readonly RestoreUploads $uploads)
    {
    }

    public function recover(Job $job): Job
    {
        if ($job->type !== JobQueue::ONE_ACTIVE_TYPE || $job->status === 'done' || $job->status === 'queued') {
            return $job;
        }
        if ($job->status === 'failed' && $job->error !== JobQueue::EXPIRED_ERROR) {
            return $job;
        }
        $upload = $job->payload['upload'] ?? null;
        $owner = $job->payload['owner'] ?? null;
        if (!is_string($upload) || !is_string($owner)) {
            return $job;
        }
        try {
            $manifest = $this->uploads->asOwner($owner)->get($upload);
        } catch (UserError $e) {
            if ($e->getCode() === 409) {
                return $this->queue->resumeExpired($job);
            }

            return $job;
        }
        $status = $manifest['status'];
        if ($status === 'restored') {
            $stored = is_array($manifest['result'] ?? null) ? $manifest['result'] : [];

            return $this->queue->replaceWithOutcome($job, null, RestoreConfirmJob::resultOf($stored));
        }
        if ($status === 'failed' || $status === 'interrupted') {
            $error = is_array($manifest['result'] ?? null) ? ($manifest['result']['error'] ?? null) : null;

            return $this->queue->replaceWithOutcome($job, is_string($error) && $error !== '' ? $error : 'The restore failed.', null);
        }
        if ($status === 'ready') {
            return $this->queue->requeueExpiredLease($job) ?? $this->queue->find($job->id) ?? $job;
        }

        return $this->queue->resumeExpired($job);
    }

    public function sweep(JobQueue $queue): void
    {
        foreach ($queue->needsReconcile(JobQueue::ONE_ACTIVE_TYPE) as $job) {
            $this->recover($job);
        }
    }
}
