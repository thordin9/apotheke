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
}
