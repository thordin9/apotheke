<?php

declare(strict_types=1);

namespace Apotheke\Core\Exceptions;

use RuntimeException;

/**
 * A database statement failed while saving. With DBDebug off CodeIgniter
 * returns false instead of throwing; this turns that into legacy's PDO
 * exception: the transaction rolls back and the request answers 500.
 */
final class WriteFailed extends RuntimeException
{
}
