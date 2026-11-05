<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use CodeIgniter\Database\BaseConnection;

/**
 * D1's batch-only contract on a local connection (SQLite or PostgreSQL), for
 * the parity tests and self-hosted parity checks of the batch path
 * (ADR-002, P4-2a): connection() is null, so a unit of work runs as a
 * BatchUnitOfWork, and every statement is checked by D1Limits before any
 * runs. It is a model of D1, not D1. A refused statement is a programming
 * error (LogicException).
 *
 * The batch itself is atomic as on D1: its own transaction, or a SAVEPOINT
 * when a plain transaction is open (D1 never has one), so a failed batch
 * keeps nothing and the outer transaction carries on. No last insert id is
 * exposed: ids come from uow_refs only.
 */
final class LocalBatchExecutor implements SqlExecutor
{
    /** @deprecated use D1Limits::MAX_PARAMS */
    public const MAX_PARAMS = D1Limits::MAX_PARAMS;

    /** @deprecated use D1Limits::PRAGMAS */
    public const PRAGMAS = D1Limits::PRAGMAS;

    private readonly ConnectionExecutor $inner;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
        $this->inner = new ConnectionExecutor($db);
    }

    public function dialect(): Dialect
    {
        return $this->inner->dialect();
    }

    public function connection(): ?BaseConnection
    {
        return null;
    }

    /**
     * The local connection the batches run on, for the unit's lock of a
     * PostgreSQL batch (UnitsOfWork, L-1743); never for a unit's writes.
     *
     * @return BaseConnection<object|resource, object|resource>
     */
    public function local(): BaseConnection
    {
        return $this->db;
    }

    public function run(array $statements): array
    {
        $pg = $this->dialect()->name === Dialect::POSTGRES;
        foreach ($statements as $s) {
            D1Limits::refuse($s['sql'], count($s['params']), $pg);
        }
        $run = fn (): array => $this->inner->run($statements);

        return (int)$this->db->transDepth > 0 ? Writes::savepoint($this->db, $run) : $run();
    }

    public function query(string $sql, array $params = []): array
    {
        D1Limits::refuse($sql, count($params), $this->dialect()->name === Dialect::POSTGRES);

        return $this->inner->query($sql, $params);
    }
}
