<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use LogicException;

/**
 * The id of a row a unit of work will insert, so a statement queued after
 * the insert can refer to the row before the database has numbered it; the
 * id is known once the unit has run. The batch path also gives it a ULID.
 */
final class Ref
{
    private ?int $id = null;

    public function __construct(public readonly string $table, public readonly string $ulid = '')
    {
    }

    public function resolve(int $id): void
    {
        if ($this->id !== null && $this->id !== $id) {
            throw new LogicException('A row id is resolved once.');
        }
        $this->id = $id;
    }

    public function resolved(): bool
    {
        return $this->id !== null;
    }

    public function id(): int
    {
        return $this->id ?? throw new LogicException('The row of ' . $this->table . ' is not written yet: read its id after the unit of work ran.');
    }
}
