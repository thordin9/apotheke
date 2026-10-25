<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Closure;

/**
 * The answer time of a failed sign-in. Every failure (an unknown name or a
 * wrong password) ends at the same deadline after the attempt began, so the
 * answer time does not depend on whether the account exists or on how its
 * stored hash was made. Legacy waits a flat 400 ms after the check instead;
 * the deadline is never shorter than that. Holds no request state: start()
 * returns the start time and pad() takes it back.
 */
final class SignInPace
{
    /** Default deadline from the start of the attempt to a failed answer. */
    public const DEFAULT_MS = 1000;

    /** Legacy usleep(400000): the shortest deadline accepted. */
    public const MIN_MS = 400;

    public const MAX_MS = 10000;

    public readonly int $deadlineMs;

    /** @var Closure(): int monotonic nanoseconds */
    private readonly Closure $now;

    /** @var Closure(int): void sleeps for microseconds */
    private readonly Closure $sleep;

    /**
     * @param (Closure(): int)|null $now monotonic nanoseconds (hrtime)
     * @param (Closure(int): void)|null $sleep microseconds (usleep)
     */
    public function __construct(int $deadlineMs = self::DEFAULT_MS, ?Closure $now = null, ?Closure $sleep = null)
    {
        $this->deadlineMs = min(self::MAX_MS, max(self::MIN_MS, $deadlineMs));
        $this->now = $now ?? static fn (): int => hrtime(true);
        $this->sleep = $sleep ?? static function (int $us): void {
            usleep($us);
        };
    }

    /** The start of an attempt, for pad(). */
    public function start(): int
    {
        return ($this->now)();
    }

    /** Waits until the deadline after $started; returns the microseconds waited (0 when already past it). */
    public function pad(int $started): int
    {
        $left = intdiv($started + $this->deadlineMs * 1_000_000 - ($this->now)(), 1000);
        if ($left <= 0) {
            return 0;
        }
        ($this->sleep)($left);

        return $left;
    }
}
