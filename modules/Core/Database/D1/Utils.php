<?php

declare(strict_types=1);

namespace Apotheke\Core\Database\D1;

/** The bridge runs SQLite, so CodeIgniter's SQLite3 utils is used as it is (Connection). */
class Utils extends \CodeIgniter\Database\SQLite3\Utils
{
}
