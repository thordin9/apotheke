<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

/** One row of the jobs table, as JobQueue read it. */
final class Job
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $checkpoint
     * @param array<string,mixed>|null $result
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $status,
        public readonly array $payload,
        public readonly ?array $checkpoint,
        public readonly ?array $result,
        public readonly ?string $error,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?string $actor,
        public readonly ?string $leaseOwner,
    ) {
    }

    /**
     * The job as the API shows it (GET /api/v1/jobs/{id}): progress is the handler's checkpoint {done, total}, and
     * error only ever the fixed text the queue or the handler stored (JobRunner::INTERNAL_ERROR for anything unplanned).
     *
     * @return array{id:int,type:string,status:string,progress:array{done:int,total:int}|null,result:array<string,mixed>|null,error:string|null}
     */
    public function view(): array
    {
        $c = $this->checkpoint;
        $progress = is_int($c['done'] ?? null) && is_int($c['total'] ?? null) ? ['done' => $c['done'], 'total' => $c['total']] : null;

        return ['id' => $this->id, 'type' => $this->type, 'status' => $this->status, 'progress' => $progress, 'result' => $this->result, 'error' => $this->error];
    }
}
