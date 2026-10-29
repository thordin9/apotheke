<?php

declare(strict_types=1);

namespace Apotheke\Tools\Fixtures\Filters;

/** Allowed: a module filter resolving a service inside before() and after(). */
final class GoodFilter
{
    public function before(): void
    {
        \Config\Services::request();
    }

    public function after(): void
    {
        service('response');
    }
}
