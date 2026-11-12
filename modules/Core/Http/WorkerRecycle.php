<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * Request cap for the FrankenPHP worker loop (L-1979). FrankenPHP 1.13.4 rejects the Caddyfile's `max_requests`, so
 * public/frankenphp-worker.php counts the requests itself: after the cap the loop ends, the script returns, and
 * FrankenPHP starts the worker again with fresh PHP state (its documented recycling pattern). This bounds what a slow
 * leak can build up in a long-running worker.
 *
 * APOTHEKE_WORKER_MAX_REQUESTS: a whole number of requests per worker thread; empty or unset uses DEFAULT, 0 means
 * no cap. Anything else (negative, not a number) falls back to DEFAULT rather than stopping the worker.
 */
final class WorkerRecycle
{
    public const DEFAULT = 1000;

    public static function limit(string|false|null $value): int
    {
        $value = trim((string)$value);
        if ($value === '' || preg_match('/^\d{1,9}$/D', $value) !== 1) {
            return self::DEFAULT;
        }

        return (int)$value;
    }

    /** Whether the worker takes another request after serving $served of them; a $limit of 0 never stops. */
    public static function keepServing(int $served, int $limit): bool
    {
        return $limit === 0 || $served < $limit;
    }
}
