<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Http\RequestCleanup;
use LogicException;
use Throwable;

/**
 * Opens units of work on one executor, and is the registry of the open one
 * (ADR-002, request state). Built per request by Config\Services, never
 * persistent in worker mode, and with no static state: a unit is opened and
 * closed inside run(), so the registry is empty again when run() returns or
 * throws. A unit a fatal error left open is discarded, never flushed, by
 * the request's RequestCleanup.
 *
 * - On a connection (self-hosted SQLite and PostgreSQL) a unit is a real
 *   transaction (Writes::transaction(), so it joins one already open), and
 *   the queued steps run in order at the end.
 * - The batch path (D1, P4-3) is the next part of P4-2a; until then an
 *   executor without a connection is refused.
 * - A unit started while one is open joins it: the inner callback gets the
 *   outer unit, so everything commits (or not) as one. A failure inside a
 *   joined unit fails the outer one even if the outer callback catches it.
 */
final class UnitsOfWork
{
    private const CLEANUP_KEY = 'unit-of-work';

    private ?QueuedUnitOfWork $open = null;

    private function __construct(private readonly SqlExecutor $executor, private readonly ?RequestCleanup $cleanup)
    {
    }

    public static function on(SqlExecutor $executor, ?RequestCleanup $cleanup = null): self
    {
        return new self($executor, $cleanup);
    }

    public function executor(): SqlExecutor
    {
        return $this->executor;
    }

    /**
     * Run $fn with a unit of work: $fn reads as usual and queues its writes
     * on the unit, which runs them as one atomic unit when $fn returns.
     * Queued writes are not visible to $fn's own reads. On SQLite a no-op
     * write on $table first takes the database's write lock, so $fn reads
     * under it (the legacy "write first" order).
     *
     * @template T
     * @param callable(UnitOfWork):T $fn
     * @return T
     */
    public function run(string $table, callable $fn): mixed
    {
        if ($this->open !== null) {
            try {
                return $fn($this->open);
            } catch (Throwable $e) {
                $this->open->fail($e);
                throw $e;
            }
        }
        $db = $this->executor->connection() ?? throw new LogicException('A unit of work needs a connection until the batch path is in.');

        return Writes::transaction($db, function () use ($db, $table, $fn): mixed {
            if ($db->DBDriver === 'SQLite3') {
                Writes::check($db->query('DELETE FROM ' . $db->escapeIdentifiers($table) . ' WHERE 0 = 1'), 'write lock');
            }
            $uow = new ConnectionUnitOfWork($db);
            $r = $this->scoped($uow, $fn);
            $uow->flush();

            return $r;
        });
    }

    /** Whether a unit is open now (inside run()). */
    public function isOpen(): bool
    {
        return $this->open !== null;
    }

    /** Drop an open unit without running it (it can only throw now). True when there was one. */
    public function discard(): bool
    {
        $open = $this->open;
        $this->release();
        $open?->discard();

        return $open !== null;
    }

    private function release(): void
    {
        $this->open = null;
        $this->cleanup?->drop(self::CLEANUP_KEY);
    }

    /**
     * @template T
     * @param callable(UnitOfWork):T $fn
     * @return T
     */
    private function scoped(QueuedUnitOfWork $uow, callable $fn): mixed
    {
        $this->open = $uow;
        $this->cleanup?->add(self::CLEANUP_KEY, function (): void {
            $this->discard();
        });
        try {
            return $fn($uow);
        } finally {
            $this->release();
        }
    }
}
