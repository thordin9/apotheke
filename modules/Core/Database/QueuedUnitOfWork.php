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
    private const CLOSED = 'closed';
    private const DISCARDED = 'discarded';

    /** @var list<Statement> */
    protected array $queue = [];

    private ?Throwable $failed = null;

    /** A joined (nested) unit failed: this unit must not commit, even if its caller caught the error. */
    public function fail(Throwable $e): void
    {
        $this->failed ??= $e;
    }

    /** Null while the unit is open, else CLOSED (it ran, or its run() threw) or DISCARDED. */
    private ?string $closed = null;

    /** Drop the queue: the unit never runs (UnitsOfWork::discard()). */
    public function discard(): void
    {
        $this->closed ??= self::DISCARDED;
        $this->queue = [];
    }

    /** End the unit without running it (its run() threw): later calls are refused. */
    public function close(): void
    {
        $this->closed ??= self::CLOSED;
        $this->queue = [];
    }

    /**
     * L-1626: a unit takes writes only while it is open. Once it ran, its
     * run() threw or it was discarded, any further call throws instead of
     * queueing a write that would never run.
     */
    protected function refuseIfClosed(): void
    {
        if ($this->closed === self::DISCARDED) {
            throw new WriteFailed('Database write failed: the unit of work was discarded');
        }
        if ($this->closed !== null) {
            throw new LogicException('The unit of work has already run: queue its writes inside its run() callback.');
        }
    }

    /** Close the unit for its one flush: refused if it is closed already or a joined unit failed. */
    protected function closeForFlush(): void
    {
        $this->refuseIfClosed();
        $this->closed = self::CLOSED;
        if ($this->failed !== null) {
            $this->queue = [];
            throw new WriteFailed('Database write failed: a joined unit of work failed', 0, $this->failed);
        }
    }

    public function insert(string $table, array $row, string $step): Ref
    {
        $this->refuseIfClosed();
        $ref = $this->newRef($table);
        $this->queue[] = new Statement(Statement::KIND_INSERT, $step, table: $table, row: $row, ref: $ref);

        return $ref;
    }

    /** The Ref an insert hands out: the batch path gives it a ULID. */
    protected function newRef(string $table): Ref
    {
        return new Ref($table);
    }

    public function update(string $table, array $set, array $where, string $step): void
    {
        $this->refuseIfClosed();
        if ($set === [] || $where === []) {
            throw new LogicException('An update names its columns and its rows.');
        }
        $this->queue[] = new Statement(Statement::KIND_UPDATE, $step, table: $table, row: $set, where: $where);
    }

    public function write(string $sql, array $binds, string $step, ?int $expect = null, ?Closure $fail = null): void
    {
        $this->refuseIfClosed();
        self::checkBinds($sql, $binds);
        $this->queue[] = new Statement(Statement::KIND_WRITE, $step, $sql, $binds, expect: $expect, fail: $fail);
    }

    public function assert(string $condition, array $binds, string $step, ?Closure $fail = null): void
    {
        $this->refuseIfClosed();
        self::checkBinds($condition, $binds);
        $this->queue[] = new Statement(Statement::KIND_ASSERT, $step, $condition, $binds, fail: $fail);
    }

    /**
     * The checks every hold makes before it is run or queued: the unit is
     * open, there is one bind per placeholder, and no bind is the Ref of a
     * row not written yet (L-1638): a hold runs before the unit's inserts in
     * both modes, so that id cannot exist yet.
     *
     * @param list<scalar|Ref|null> $binds
     */
    protected function checkHold(string $sql, array $binds): void
    {
        $this->refuseIfClosed();
        self::checkBinds($sql, $binds);
        foreach ($binds as $bind) {
            if ($bind instanceof Ref && !$bind->resolved()) {
                throw new LogicException('A hold runs before the inserts of its unit: it cannot bind the id of a ' . $bind->table . ' row the unit has not written yet.');
            }
        }
    }

    /**
     * N-1639: one bind per `?` placeholder (outside quotes), refused the same
     * way in both modes before anything runs.
     *
     * @param list<scalar|Ref|null> $binds
     */
    protected static function checkBinds(string $sql, array $binds): void
    {
        $placeholders = substr_count(preg_replace('/\'(?:[^\']++|\'\')*+\'|"(?:[^"]++|"")*+"/', '', $sql) ?? $sql, '?');
        if ($placeholders < count($binds)) {
            throw new LogicException('A statement has more binds than placeholders.');
        }
        if ($placeholders > count($binds)) {
            throw new LogicException('A statement has more placeholders than binds.');
        }
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
