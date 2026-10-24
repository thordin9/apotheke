<?php

declare(strict_types=1);

namespace Apotheke\Tools\Fixtures\Filters;

/** A filter may use the shared services: CI4 builds filters without constructor arguments. */
function allowed(): void
{
    \Config\Services::request();
}
