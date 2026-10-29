<?php

declare(strict_types=1);

namespace Apotheke\Tools\Fixtures\Loose;

/** Flagged: a Filters directory outside modules/<Module>/. */
final class LooseFilter
{
    public function before(): void
    {
        \Config\Services::request();
    }
}
