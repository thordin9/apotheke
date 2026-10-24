<?php

declare(strict_types=1);

namespace Apotheke\Tools\Fixtures\Filters\Nested;

/** Flagged: below a Filters directory, not directly in it. */
final class DeepFilter
{
    public function before(): void
    {
        \Config\Services::request();
    }
}
