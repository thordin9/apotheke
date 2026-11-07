<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

use Closure;

/**
 * One holder's lease for as long as the mutation runs. checkpoint() renews it.
 * A holder who has lost the lease cannot start the next write.
 */
final class LeaseHold
{
    /** @param Closure():int $now */
    public function __construct(
        private readonly ObjectLease $lease,
        private readonly string $key,
        private readonly string $token,
        private readonly int $ttl,
        private readonly Closure $now,
    ) {
    }

    public function checkpoint(): void
    {
        $this->lease->renew($this->key, $this->token, ($this->now)(), $this->ttl);
    }

    public function exclusive(Closure $write): mixed
    {
        $this->checkpoint();

        return $write();
    }

    public function token(): string
    {
        return $this->token;
    }

    public function release(): void
    {
        $this->lease->release($this->key, $this->token);
    }
}
