<?php

declare(strict_types=1);

namespace Apotheke\Core\Database\D1;

/** The bridge runs SQLite, so CodeIgniter's SQLite3 builder is used as it is (Connection). */
class Builder extends \CodeIgniter\Database\SQLite3\Builder
{
}
