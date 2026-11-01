<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;

/**
 * The unit of work on a CodeIgniter connection (SQLite3, Postgre), the
 * default on self-hosted installs: run by UnitsOfWork::run() inside a real transaction that is already open while
 * the callback reads. A hold runs at once; the queued steps run in order at
 * flush(), and a guard that does not hold throws, so the transaction rolls
 * back as a whole.
 */
final class ConnectionUnitOfWork extends QueuedUnitOfWork
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function hold(string $sql, array $binds, string $step): void
    {
        $this->refuseIfClosed();
        Writes::check($this->db->query($sql, $binds), $step);
    }

    /** Run the queued steps (inside the caller's transaction) and close the unit: it runs once. */
    public function flush(): void
    {
        $this->closeForFlush();
        $queue = $this->queue;
        $this->queue = [];
        foreach ($queue as $s) {
            match ($s->kind) {
                Statement::KIND_INSERT => $this->runInsert($s),
                Statement::KIND_UPDATE => Writes::check($this->db->table($s->table)->where(self::ids($s->where))->update(self::ids($s->row)), $s->step),
                Statement::KIND_WRITE => $this->runWrite($s),
                Statement::KIND_ASSERT => $this->runAssert($s),
                default => throw new WriteFailed('Database write failed: ' . $s->step),
            };
        }
    }

    private function runInsert(Statement $s): void
    {
        Writes::check($this->db->table($s->table)->insert(self::ids($s->row)), $s->step);
        $s->ref?->resolve((int)$this->db->insertID());
    }

    private function runWrite(Statement $s): void
    {
        Writes::check($this->db->query($s->sql, self::ids($s->binds)), $s->step);
        if ($s->expect !== null && $this->db->affectedRows() !== $s->expect) {
            throw $s->failure() ?? new WriteFailed('Database write failed: ' . $s->step);
        }
    }

    private function runAssert(Statement $s): void
    {
        if (Writes::rows($this->db, 'SELECT 1 AS ok WHERE (' . $s->sql . ')', self::ids($s->binds)) === []) {
            throw $s->failure() ?? new WriteFailed('Database write failed: ' . $s->step);
        }
    }
}
