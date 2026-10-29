<?php

declare(strict_types=1);

namespace Apotheke\Catalog;

use Apotheke\Core\Libraries\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * "Today" and its neighbours in the app's time zone (legacy app_now(),
 * local_today(), expiry_*(), is_overdue(), local_boundary_utc()). The zone is
 * the `timezone` setting, as legacy settings_apply_runtime() sets it, but
 * passed explicitly instead of changing PHP's default.
 */
final class Calendar
{
    /** @param int $soonDays the expiry_soon_days setting */
    public function __construct(private readonly Clock $clock, private readonly string $timezone, private readonly int $soonDays)
    {
    }

    public function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->timezone);
        } catch (Throwable) {
            return new DateTimeZone(date_default_timezone_get());
        }
    }

    /** Legacy app_now(): APOTHEKE_CLOCK (read as UTC) or the real time, in $tz or the app's zone. */
    public function now(?DateTimeZone $tz = null): DateTimeImmutable
    {
        $t = strtotime($this->clock->now() . ' UTC');

        return (new DateTimeImmutable('@' . ($t !== false ? $t : time())))->setTimezone($tz ?? $this->zone());
    }

    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }

    /** Last day of the "Expiring soon" window. */
    public function soonUntil(): string
    {
        return $this->date($this->today())->modify('+' . $this->soonDays . ' days')->format('Y-m-d');
    }

    /** @param array<string,mixed> $it 'expired', 'soon' or '' */
    public function expiryState(array $it): string
    {
        $d = $it['expires_on'] ?? null;
        if ($d === null || $d === '') {
            return '';
        }
        if ($d < $this->today()) {
            return 'expired';
        }

        return $d <= $this->soonUntil() ? 'soon' : '';
    }

    /** Days from today until $date (negative = past). */
    public function days(string $date): int
    {
        return (int)$this->date($this->today())->diff($this->date($date))->format('%r%a');
    }

    /** @param array<string,mixed> $it */
    public function isOverdue(array $it): bool
    {
        return ($it['lent_to'] ?? null) !== null && ($it['lent_due'] ?? null) !== null && $it['lent_due'] < $this->today();
    }

    /** UTC 'Y-m-d H:i:s' of local midnight for an expression like 'monday this week'. */
    public function boundaryUtc(string $expr): string
    {
        return $this->now()->modify($expr)->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function date(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd, $this->zone());
    }
}
