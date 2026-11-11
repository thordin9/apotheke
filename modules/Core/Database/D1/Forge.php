<?php

declare(strict_types=1);

namespace Apotheke\Core\Database\D1;

/** The bridge runs SQLite, so CodeIgniter's SQLite3 forge is used as it is (Connection). */
class Forge extends \CodeIgniter\Database\SQLite3\Forge
{
}
