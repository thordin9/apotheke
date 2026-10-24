<?php

declare(strict_types=1);

namespace Apotheke\Tools\Fixtures\Loose;

/** Flagged: a Filters directory outside ci4/modules/<Module>/. */
final class LooseFilter
{
    public function before(): void
    {
        \Config\Services::request();
    }
}
