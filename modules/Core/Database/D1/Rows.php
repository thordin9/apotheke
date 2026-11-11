<?php

declare(strict_types=1);

namespace Apotheke\Core\Database\D1;

/** The rows of one bridge read, as the result id CodeIgniter expects (an object, not an array). */
final class Rows
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(public readonly array $rows)
    {
    }
}
