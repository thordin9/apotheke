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
}
