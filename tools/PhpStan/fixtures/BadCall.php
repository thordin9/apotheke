<?php

declare(strict_types=1);

namespace Apotheke\Tools\Fixtures;

function bad(): void
{
    service('session');
    \Config\Services::session();
}
