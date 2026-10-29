<?php

declare(strict_types=1);

namespace Apotheke\Core\Exceptions;

use RuntimeException;

/** A request the user can fix. getCode() is the HTTP status. */
final class UserError extends RuntimeException
{
}
