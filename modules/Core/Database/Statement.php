<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Closure;
use Throwable;

/**
 * One queued step of a unit of work.
 *
 * - KIND_HOLD: a locking write (legacy "write first" locks); a connection
 *   runs it at once, a batch puts it first.
 * - KIND_INSERT: $table/$row; $ref receives the new row's id.
 * - KIND_UPDATE: $table/$row (the new values) where every $where column
 *   equals its value.
 * - KIND_WRITE: $sql with $binds; with $expect, any other number of changed
 *   rows fails the whole unit.
 * - KIND_ASSERT: $sql is a boolean SQL condition that must hold when the
 *   step runs, or the whole unit fails.
 *
 * Bind values (and row values) may be Refs to rows inserted earlier in the
 * same unit. A failed guard throws $fail() when given, else WriteFailed.
 */
final class Statement
{
    public const KIND_HOLD = 'hold';
    public const KIND_INSERT = 'insert';
    public const KIND_UPDATE = 'update';
    public const KIND_WRITE = 'write';
    public const KIND_ASSERT = 'assert';

    /**
     * @param list<scalar|Ref|null> $binds
     * @param array<string,scalar|Ref|null> $row
     * @param array<string,scalar|Ref|null> $where
     * @param (Closure():Throwable)|null $fail
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $step,
        public readonly string $sql = '',
        public readonly array $binds = [],
        public readonly string $table = '',
        public readonly array $row = [],
        public readonly ?Ref $ref = null,
        public readonly ?int $expect = null,
        public readonly ?Closure $fail = null,
        public readonly array $where = [],
    ) {
    }

    public function failure(): ?Throwable
    {
        return $this->fail === null ? null : ($this->fail)();
    }
}
