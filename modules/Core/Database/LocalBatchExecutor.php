<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use CodeIgniter\Database\BaseConnection;
use LogicException;

/**
 * D1's batch-only contract on a local connection (SQLite or PostgreSQL), for
 * the parity tests and self-hosted parity checks of the batch path
 * (ADR-002, P4-2a): connection() is null, so a unit of work runs as a
 * BatchUnitOfWork, and every statement is held to D1's limits before any
 * runs. A refused statement is a programming error (LogicException):
 *
 * - no BEGIN, COMMIT, END, ROLLBACK, SAVEPOINT or RELEASE, and no PRAGMA;
 * - no sqlite_*() function;
 * - at most 100 bound parameters (a list is one json_each() parameter).
 *
 * The batch itself is atomic as on D1: its own transaction, or a SAVEPOINT
 * when a plain transaction is open (D1 never has one), so a failed batch
 * keeps nothing and the outer transaction carries on. No last insert id is
 * exposed: ids come from uow_refs only.
 */
final class LocalBatchExecutor implements SqlExecutor
{
    public const MAX_PARAMS = 100;

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

    public function run(array $statements): array
    {
        foreach ($statements as $s) {
            self::refuse($s['sql'], count($s['params']));
        }
        $run = fn (): array => $this->inner->run($statements);

        return (int)$this->db->transDepth > 0 ? Writes::savepoint($this->db, $run) : $run();
    }

    public function query(string $sql, array $params = []): array
    {
        self::refuse($sql, count($params));

        return $this->inner->query($sql, $params);
    }

    private static function refuse(string $sql, int $params): void
    {
        if (preg_match('/^\s*(?:BEGIN|COMMIT|END|ROLLBACK|SAVEPOINT|RELEASE|PRAGMA)\b/i', $sql) === 1) {
            throw new LogicException('D1 refuses transaction statements and pragmas in a batch.');
        }
        if (preg_match('/\bsqlite_\w+\s*\(/i', $sql) === 1) {
            throw new LogicException('D1 refuses sqlite_*() functions.');
        }
        if ($params > self::MAX_PARAMS) {
            throw new LogicException('D1 allows ' . self::MAX_PARAMS . ' bound parameters per statement; bind a list as one (Dialect::inList()).');
        }
    }
}
