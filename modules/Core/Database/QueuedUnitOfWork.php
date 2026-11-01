<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\WriteFailed;
use Closure;
use LogicException;
use Throwable;

/** The queue shared by the unit-of-work backends. */
abstract class QueuedUnitOfWork implements UnitOfWork
{
    /** @var list<Statement> */
    protected array $queue = [];

    private ?Throwable $failed = null;

    /** A joined (nested) unit failed: this unit must not commit, even if its caller caught the error. */
    public function fail(Throwable $e): void
    {
        $this->failed ??= $e;
    }

    private bool $discarded = false;

    /** Drop the queue: the unit never runs (UnitsOfWork::discard()). */
    public function discard(): void
    {
        $this->discarded = true;
        $this->queue = [];
    }

    protected function refuseIfJoinedFailed(): void
    {
        if ($this->discarded) {
            throw new WriteFailed('Database write failed: the unit of work was discarded');
        }
        if ($this->failed !== null) {
            $this->queue = [];
            throw new WriteFailed('Database write failed: a joined unit of work failed', 0, $this->failed);
        }
    }

    public function insert(string $table, array $row, string $step): Ref
    {
        $ref = new Ref($table);
        $this->queue[] = new Statement(Statement::KIND_INSERT, $step, table: $table, row: $row, ref: $ref);

        return $ref;
    }

    public function update(string $table, array $set, array $where, string $step): void
    {
        if ($set === [] || $where === []) {
            throw new LogicException('An update names its columns and its rows.');
        }
        $this->queue[] = new Statement(Statement::KIND_UPDATE, $step, table: $table, row: $set, where: $where);
    }

    public function write(string $sql, array $binds, string $step, ?int $expect = null, ?Closure $fail = null): void
    {
        $this->queue[] = new Statement(Statement::KIND_WRITE, $step, $sql, $binds, expect: $expect, fail: $fail);
    }

    public function assert(string $condition, array $binds, string $step, ?Closure $fail = null): void
    {
        $this->queue[] = new Statement(Statement::KIND_ASSERT, $step, $condition, $binds, fail: $fail);
    }

    public function statements(): array
    {
        return $this->queue;
    }

    /**
     * @template K of array-key
     * @param array<K,scalar|Ref|null> $values
     * @return array<K,scalar|null>
     */
    protected static function ids(array $values): array
    {
        return array_map(static fn (mixed $v): mixed => $v instanceof Ref ? $v->id() : $v, $values);
    }
}
