<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
use CodeIgniter\Database\BaseConnection;
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
 *   transaction (Writes::transaction()), and the queued steps run in order
 *   at the end. Inside a plain transaction that is already open, the unit
 *   runs under a SAVEPOINT, so it stays all or nothing even when the outer
 *   caller catches its error and commits (L-1625).
 * - On an executor without a connection (D1, a Durable Object, or
 *   LocalBatchExecutor for tests) the unit is a BatchUnitOfWork: its steps
 *   go out as one batch when the callback returns, holds first.
 * - A unit started while one is open joins it: the inner callback gets the
 *   outer unit, so everything commits (or not) as one. A failure inside a
 *   joined unit fails the outer one even if the outer callback catches it.
 * - A unit is closed once its run() returns or throws: a write queued on
 *   it later throws instead of being dropped (L-1626).
 */
final class UnitsOfWork
{
    private const CLEANUP_KEY = 'unit-of-work';

    /** How long a unit waits for its PostgreSQL lock before it answers 503 (N-1665). */
    public const LOCK_WAIT_MS = 5000;

    private ?QueuedUnitOfWork $open = null;

    private function __construct(private readonly SqlExecutor $executor, private readonly ?RequestCleanup $cleanup, private readonly int $lockWaitMs)
    {
    }

    public static function on(SqlExecutor $executor, ?RequestCleanup $cleanup = null, int $lockWaitMs = self::LOCK_WAIT_MS): self
    {
        return new self($executor, $cleanup, $lockWaitMs);
    }

    /**
     * The units a write site uses (P4-2b): the request's ($units, from
     * Config\Services::unitsOfWork()), or its own on $db when the site is
     * built without one (tests and command-line tools). A registry on
     * another connection than $db is a wiring error: its writes would land
     * elsewhere than the site's reads.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     */
    public static function forSite(BaseConnection $db, ?self $units): self
    {
        if ($units === null) {
            return self::on(new ConnectionExecutor($db));
        }
        $on = $units->executor()->connection();
        if ($on !== null && $on !== $db) {
            throw new LogicException('A write site and its units of work must use the same connection.');
        }

        return $units;
    }

    public function executor(): SqlExecutor
    {
        return $this->executor;
    }

    /**
     * Run $fn with a unit of work: $fn reads as usual and queues its writes
     * on the unit, which runs them as one atomic unit when $fn returns.
     * Queued writes are not visible to $fn's own reads. On a connection
     * $fn reads under a lock, so two units on $table cannot both pass the
     * same check (the legacy "write first" order): on SQLite a no-op write
     * on $table takes the database's write lock; on PostgreSQL a transaction
     * advisory lock on $table and $key (L-1660), so units with another $key
     * (a device's list) do not wait for each other. On a batch executor the
     * unit's guards hold the checks instead.
     *
     * PostgreSQL lock rules (N-1665):
     * - Pass $key where a row or a list is the real scope (an item's id, a
     *   device's list); without one every unit on $table waits for the other.
     * - The lock is a transaction lock, released at commit or rollback; a unit
     *   joined to a caller's transaction keeps it until the outer commit.
     * - A unit waits at most LOCK_WAIT_MS for it, then answers 503 (UserError).
     * - Lock order: a caller that locks rows in its own transaction and then
     *   opens a unit takes the rows first, then the unit's lock. Every path
     *   that takes both takes them in that order (rows, then unit), and a unit
     *   never opens a second unit on another table while it waits.
     *
     * @template T
     * @param callable(UnitOfWork):T $fn
     * @return T
     */
    public function run(string $table, callable $fn, string $key = ''): mixed
    {
        if ($this->open !== null) {
            try {
                return $fn($this->open);
            } catch (Throwable $e) {
                $this->open->fail($e);
                throw $e;
            }
        }
        $db = $this->executor->connection();
        if ($db === null) {
            return $this->once(new BatchUnitOfWork($this->executor), $fn);
        }
        $joined = (int)$db->transDepth > 0;

        return Writes::transaction($db, function () use ($db, $table, $fn, $joined, $key): mixed {
            $unit = function () use ($db, $table, $fn, $key): mixed {
                if ($db->DBDriver === 'SQLite3') {
                    Writes::check($db->query('DELETE FROM ' . $db->escapeIdentifiers($table) . ' WHERE 0 = 1'), 'write lock');
                } elseif ($db->DBDriver === 'Postgre') {
                    $this->lockPostgre($db, self::lockKeys($table, $key));
                }

                return $this->once(new ConnectionUnitOfWork($db), $fn);
            };

            return $joined ? Writes::savepoint($db, $unit) : $unit();
        });
    }

    /**
     * Take the unit's transaction advisory lock, waiting at most lockWaitMs:
     * a try-lock with a short back-off, so a busy key answers 503 without
     * changing the transaction's lock_timeout or aborting it.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     * @param array{0:int,1:int} $keys
     */
    private function lockPostgre(BaseConnection $db, array $keys): void
    {
        $deadline = hrtime(true) + $this->lockWaitMs * 1_000_000;
        $pause = 2_000;
        while (!in_array(Writes::rows($db, 'SELECT pg_try_advisory_xact_lock(?, ?) AS ok', $keys)[0]['ok'] ?? null, [true, 't', 1, '1'], true)) {
            if (hrtime(true) >= $deadline) {
                throw new UserError('The database is busy. Try again in a moment.', 503);
            }
            usleep($pause);
            $pause = min($pause * 2, 50_000);
        }
    }

    /**
     * The two int4 keys of a unit's PostgreSQL advisory lock (its own key
     * space: the migration runner's lock is a single bigint key).
     *
     * @return array{0:int,1:int}
     */
    public static function lockKeys(string $table, string $key = ''): array
    {
        $int4 = static fn (string $s): int => (int)unpack('l', pack('L', crc32($s)))[1];

        return [$int4('apotheke_uow:' . $table), $int4($key)];
    }

    /**
     * Run $fn on $uow, then flush it; the unit is closed either way.
     *
     * @template T
     * @param callable(UnitOfWork):T $fn
     * @return T
     */
    private function once(ConnectionUnitOfWork|BatchUnitOfWork $uow, callable $fn): mixed
    {
        try {
            $r = $this->scoped($uow, $fn);
            $uow->flush();

            return $r;
        } finally {
            $uow->close();
        }
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
