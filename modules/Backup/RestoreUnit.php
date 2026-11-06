<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Storage\LeaseHold;
use Apotheke\Core\Storage\ObjectLease;
use Closure;

/**
 * One catalog restore at a time on the storage that holds the restored bytes.
 * The lease is taken before the callback. The callback renews that same hold
 * for as long as it still writes; a lost hold refuses the next write.
 */
final class RestoreUnit
{
    public const KEY = 'restores/unit';

    public function __construct(private readonly ObjectLease $lease, private readonly string $key = self::KEY)
    {
    }

    /**
     * @template T
     * @param Closure(LeaseHold):T $sideEffect receives the hold when it declares a parameter
     * @param ?Closure():int $clock stepped by tests; otherwise the wall clock, so a renewal
     *                               moves the expiry with the time the checkpoint runs
     * @return T
     */
    public function run(Closure $sideEffect, int $now, int $ttl = 3600, ?Closure $clock = null): mixed
    {
        $clock ??= static fn (): int => time();
        $token = $this->lease->acquire($this->key, $now, $ttl);
        $hold = new LeaseHold($this->lease, $this->key, $token, $ttl, $clock);
        try {
            return $sideEffect($hold);
        } finally {
            $hold->release();
        }
    }
}
