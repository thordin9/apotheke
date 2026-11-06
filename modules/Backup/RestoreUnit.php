<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Storage\ObjectLease;
use Closure;

/**
 * One catalog restore at a time on the storage that holds the restored bytes.
 * The lease is taken before the callback, and released afterwards.
 */
final class RestoreUnit
{
    public const KEY = 'restores/unit';

    public function __construct(private readonly ObjectLease $lease, private readonly string $key = self::KEY)
    {
    }

    /**
     * @template T
     * @param Closure():T $sideEffect
     * @return T
     */
    public function run(Closure $sideEffect, int $now, int $ttl = 3600): mixed
    {
        $token = $this->lease->acquire($this->key, $now, $ttl);
        try {
            return $sideEffect();
        } finally {
            $this->lease->release($this->key, $token);
        }
    }
}
