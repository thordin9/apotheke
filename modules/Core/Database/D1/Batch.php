<?php

declare(strict_types=1);

namespace Apotheke\Core\Database\D1;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\SqlExecutor;
use Apotheke\Core\Database\UnitScope;
use CodeIgniter\Database\BaseConnection;

/**
 * The batch executor of one D1 connection (P4-5): units of work run their batches here, on the same bridge as the
 * connection's reads, and the connection keeps every write either in its transaction or in the open unit, never
 * split between them (Connection::enterUnit()).
 */
final class Batch implements BatchExecutor, SqlExecutor, UnitScope
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function dialect(): Dialect
    {
        return Dialect::sqlite();
    }

    /** None: units take the batch path. */
    public function connection(): ?BaseConnection
    {
        return null;
    }

    /** The connection this executor belongs to (a site's units must be on its own connection). */
    public function owner(): Connection
    {
        return $this->db;
    }

    public function run(array $statements): array
    {
        return $this->db->runBatch($statements);
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->db->readBatch($sql, $params);
    }

    public function enterUnit(): void
    {
        $this->db->enterUnit();
    }

    public function leaveUnit(): void
    {
        $this->db->leaveUnit();
    }
}
