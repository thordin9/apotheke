<?php

declare(strict_types=1);

namespace Apotheke\Core\Exceptions;

use PDOException;

/**
 * A database statement failed while saving. With DBDebug off CodeIgniter
 * returns false instead of throwing; this turns that into legacy's PDO
 * exception: the transaction rolls back and the request answers 500.
 *
 * Extends PDOException so migration 0019's catch (PDOException) around the FTS
 * statements takes its substring fallback when the D1 adapter raises this (N-1805).
 */
final class WriteFailed extends PDOException
{
}
