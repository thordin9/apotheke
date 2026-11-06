<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

/** An object storage failure without transport, bucket or key details. */
final class R2StorageException extends \RuntimeException
{
    public static function failed(): self
    {
        // Cause is omitted deliberately: transport messages can name resources.
        return new self('Object storage request failed.');
    }
}
