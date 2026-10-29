<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use Closure;
use Throwable;

/**
 * Idle limit in seconds, as legacy session_idle_seconds() on main (#84): the
 * stored `session_idle_hours` number clamped to 1–8760, 168 when it is 0,
 * missing or cannot be read. The stored row is read as is (not through the
 * schema's validation, which would turn an out-of-range row into the
 * default), so this clamp is the bound on that number. Every seconds() call
 * reads it again through the closure; nothing is kept here.
 */
final class SessionIdle
{
    public const DEFAULT_HOURS = 168;

    /** @param Closure():mixed $hours the decoded stored row, null when there is none */
    public function __construct(private readonly Closure $hours)
    {
    }

    public function seconds(): int
    {
        return 3600 * $this->hours();
    }

    private function hours(): int
    {
        try {
            $raw = ($this->hours)();
        } catch (Throwable) {
            return self::DEFAULT_HOURS;
        }
        $hours = 0;
        if (is_int($raw) || is_float($raw)) {
            $hours = (int)$raw;
        } elseif (is_string($raw) && preg_match('/^-?\d+$/D', $raw) === 1) {
            $hours = (int)$raw;
        }

        return max(1, min(8760, $hours !== 0 ? $hours : self::DEFAULT_HOURS));
    }
}
