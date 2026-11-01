<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Closure;
use Throwable;

/**
 * Writes queued during UnitsOfWork::run() and run as one atomic unit when
 * the callback returns (ADR-002, decision 2). The callback reads normally,
 * but its writes are not visible to those reads: compute everything from
 * what was read, and express any condition that must still hold at write
 * time as a guard (expect / assert), because a D1 batch() cannot read
 * between its statements.
 *
 * On SQLite and PostgreSQL (ConnectionUnitOfWork) the unit is a real
 * transaction: holds run at once, so reads after a hold happen under that
 * lock, as before. A batch backend (BatchUnitOfWork, D1) sends the same
 * steps as one batch.
 */
interface UnitOfWork
{
    /**
     * A locking write that must come first (legacy "write first" locks).
     *
     * @param list<scalar|null> $binds
     */
    public function hold(string $sql, array $binds, string $step): void;

    /**
     * Queue an insert. Row values may be Refs.
     *
     * @param array<string,scalar|Ref|null> $row
     */
    public function insert(string $table, array $row, string $step): Ref;

    /**
     * Queue an UPDATE of $table: $set where every $where column equals its value.
     *
     * @param array<string,scalar|Ref|null> $set
     * @param array<string,scalar|Ref|null> $where
     */
    public function update(string $table, array $set, array $where, string $step): void;

    /**
     * Queue a write. With $expect, any other number of changed rows fails the unit.
     *
     * @param list<scalar|Ref|null> $binds
     * @param (Closure():Throwable)|null $fail
     */
    public function write(string $sql, array $binds, string $step, ?int $expect = null, ?Closure $fail = null): void;

    /**
     * Queue a guard: $condition (an SQL boolean expression) must be true at
     * this point of the unit, or the whole unit fails.
     *
     * @param list<scalar|Ref|null> $binds
     * @param (Closure():Throwable)|null $fail
     */
    public function assert(string $condition, array $binds, string $step, ?Closure $fail = null): void;

    /** @return list<Statement> the queued steps, in order */
    public function statements(): array;
}
