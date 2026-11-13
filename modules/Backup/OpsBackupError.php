<?php

declare(strict_types=1);

namespace Apotheke\Backup;

/** A refusal of an operator backup request: a machine code and its HTTP status (operator console C2). */
final class OpsBackupError extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, ?\Throwable $previous = null)
    {
        parent::__construct($errorCode, $status, $previous);
    }
}
