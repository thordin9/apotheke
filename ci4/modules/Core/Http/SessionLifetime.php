<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use Closure;
use Throwable;

/**
 * Session cookie lifetime in seconds, as legacy session_lifetime(): the
 * `session_days` setting clamped to 1–365, 30 when it is 0 or cannot be read
 * (SEC-N9). Every seconds() call reads the setting again through the closure
 * (the request's SettingsService); nothing is kept here, so a long-lived worker
 * sees a changed session_days on its next request.
 */
final class SessionLifetime
{
    public const DEFAULT_DAYS = 30;

    /** @param Closure():mixed $days reads session_days */
    public function __construct(private readonly Closure $days)
    {
    }

    public function seconds(): int
    {
        return 86400 * $this->days();
    }

    private function days(): int
    {
        try {
            $raw = ($this->days)();
        } catch (Throwable) {
            return self::DEFAULT_DAYS;
        }
        $days = is_int($raw) || is_float($raw) || is_string($raw) || is_bool($raw) ? (int)$raw : 0;

        return max(1, min(365, $days !== 0 ? $days : self::DEFAULT_DAYS));
    }
}
