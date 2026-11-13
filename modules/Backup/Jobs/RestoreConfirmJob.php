<?php

declare(strict_types=1);

namespace Apotheke\Backup\Jobs;

use Apotheke\Backup\RestoreUploads;
use Apotheke\Backup\Restorer;
use Apotheke\Core\Database\SqlExecutor;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Jobs\Job;
use Apotheke\Core\Jobs\JobContext;
use Apotheke\Core\Jobs\JobFailed;
use Apotheke\Core\Jobs\JobHandler;
use Apotheke\Core\Jobs\JobInterrupted;
use Apotheke\Core\Jobs\JobQueue;
use Apotheke\Core\Storage\LeaseHold;

/**
 * The restore.confirm job (POST /api/v1/restores/{id}/confirm on a batch-only database).
 * One attempt: the upload manifest is the record of whether the restore ran, and a second
 * attempt would only read that record. A PDO database still confirms inside the request.
 */
final class RestoreConfirmJob implements JobHandler
{
    public const TYPE = JobQueue::ONE_ACTIVE_TYPE;

    /** One attempt. A lost lease does not start another restore. */
    public const ONCE = 1;

    /**
     * @param array<string,mixed> $out the restore answer, or the result kept on the upload
     * @return array{restored:bool,tables:array<string,mixed>,photos:int,warnings:array<mixed>,token_cleared:bool}
     */
    public static function resultOf(array $out): array
    {
        return [
            'restored' => true,
            'tables' => is_array($out['tables'] ?? null) ? $out['tables'] : [],
            'photos' => is_int($out['photos'] ?? null) ? $out['photos'] : 0,
            'warnings' => is_array($out['warnings'] ?? null) ? $out['warnings'] : [],
            'token_cleared' => ($out['token_cleared'] ?? false) === true,
        ];
    }

    public function __construct(private readonly RestoreUploads $uploads, private readonly Restorer $restorer)
    {
    }

    /** True when confirm cannot run as one interactive transaction (D1, a Durable Object). */
    public static function asynchronous(?SqlExecutor $staged): bool
    {
        return $staged !== null && $staged->connection() === null;
    }

    /**
     * @return array{upload:string,owner:string,settings:bool}
     */
    public static function payload(string $upload, string $owner, bool $settings): array
    {
        return ['upload' => $upload, 'owner' => $owner, 'settings' => $settings];
    }

    public function run(Job $job, JobContext $context): array
    {
        $upload = $job->payload['upload'] ?? null;
        $owner = $job->payload['owner'] ?? null;
        $settings = $job->payload['settings'] ?? null;
        if (!is_string($upload) || !is_string($owner) || !is_bool($settings)) {
            throw new JobFailed('Restore not found.');
        }
        if ($context->stopRequested()) {
            $context->checkpoint(['done' => 0, 'total' => 1]);
            throw new JobInterrupted('restore ' . $upload . ': stopped');
        }
        $context->checkpoint(['done' => 0, 'total' => 1]);
        try {
            $by = $job->actor;
            $out = $this->uploads->asOwner($owner)->confirm($upload, function (string $zip, ?LeaseHold $hold = null) use ($settings, $upload, $by): array {
                return $this->restorer->restore($zip, $settings, $upload, $hold, $by);
            }, function () use ($context): void {
                $context->checkpoint(['done' => 0, 'total' => 1]);
            });
        } catch (UserError $e) {
            throw new JobFailed($e->getMessage());
        }
        $context->checkpoint(['done' => 1, 'total' => 1]);

        return self::resultOf($out);
    }
}
