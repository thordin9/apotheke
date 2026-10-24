<?php

declare(strict_types=1);

namespace Apotheke\Tools\Fixtures\Filters;

/** Flagged: a module filter resolving services outside before()/after(). */
final class BadFilterState
{
    private object $held;

    public function __construct()
    {
        $this->held = \Config\Services::request();
    }

    public function helper(): object
    {
        return service('response');
    }

    public function before(): void
    {
        $lazy = static fn (): object => \Config\Services::request();
        $lazy();
    }
}
