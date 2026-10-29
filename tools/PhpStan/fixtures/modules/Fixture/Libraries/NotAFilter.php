<?php

declare(strict_types=1);

namespace Apotheke\Tools\Fixtures\Libraries;

/** Flagged: neither a controller nor a filter, even with a before() method. */
final class NotAFilter
{
    public function before(): void
    {
        \Config\Services::request();
    }
}
