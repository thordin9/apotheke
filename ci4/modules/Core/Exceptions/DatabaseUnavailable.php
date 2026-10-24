<?php

declare(strict_types=1);

namespace Apotheke\Core\Exceptions;

use RuntimeException;

/**
 * A refused database connect. The message is the allowlisted line from
 * legacy db_connect_failure_message(): driver, host, port, dbname and
 * schema only. Never the user, the password, the DSN or the server's text.
 */
class DatabaseUnavailable extends RuntimeException
{
}
