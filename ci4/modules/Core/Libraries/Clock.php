<?php

declare(strict_types=1);

namespace Apotheke\Core\Libraries;

/** APOTHEKE_CLOCK wins, read on each call so tests can change it. */
final class Clock
{
    public function now(): string
    {
        $fixed = getenv('APOTHEKE_CLOCK');
        if (is_string($fixed) && $fixed !== '') {
            return $fixed;
        }

        return gmdate('Y-m-d H:i:s');
    }

    /** Unix time (legacy app_time()): APOTHEKE_CLOCK read as UTC when set and valid, else the real time. */
    public function unix(): int
    {
        $fixed = getenv('APOTHEKE_CLOCK');
        if (is_string($fixed) && $fixed !== '') {
            $t = strtotime($fixed . ' UTC');
            if ($t !== false) {
                return $t;
            }
        }

        return time();
    }
}
