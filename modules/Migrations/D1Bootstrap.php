<?php

declare(strict_types=1);

namespace Apotheke\Migrations;

use Apotheke\Core\Database\D1Executor;
use RuntimeException;

/**
 * Schema bootstrap on the hosted Installation object (ADR-002, P4-5): the migrations run
 * through D1MigrationRunner under a lease row, so concurrent first requests do not each
 * apply the same migration. D1 has no interactive transaction, so the lease is the unit
 * lock: one atomic claim (insert, or take over an expired lease), then a read of who owns
 * it. A request that does not own the lease fails; the next request checks again.
 *
 * The lease table is this bootstrap's own bookkeeping, like schema_migrations, and holds
 * no row outside a run.
 */
final class D1Bootstrap
{
    public const LEASE_SECONDS = 300;
    public const BUSY = 'Database migrations are running; try again shortly.';

    public function __construct(
        private readonly D1Executor $d1,
        private readonly D1MigrationRunner $runner,
    ) {
    }

    /**
     * @param ?callable(string):void $log
     * @return list<string> applied versions
     */
    public function migrate(?callable $log = null, ?int $now = null): array
    {
        $now ??= time();
        $owner = bin2hex(random_bytes(16));
        $rows = $this->d1->run([
            ['sql' => 'CREATE TABLE IF NOT EXISTS apotheke_migration_lease'
                . ' (id INTEGER PRIMARY KEY CHECK (id = 1), owner TEXT NOT NULL, expires INTEGER NOT NULL)', 'params' => []],
            ['sql' => 'INSERT INTO apotheke_migration_lease (id, owner, expires) VALUES (1, ?, ?)'
                . ' ON CONFLICT(id) DO UPDATE SET owner = excluded.owner, expires = excluded.expires'
                . ' WHERE apotheke_migration_lease.expires < ?', 'params' => [$owner, $now + self::LEASE_SECONDS, $now]],
            ['sql' => 'SELECT owner FROM apotheke_migration_lease WHERE id = 1', 'params' => []],
        ]);
        if (($rows[2][0]['owner'] ?? null) !== $owner) {
            throw new RuntimeException(self::BUSY);
        }
        try {
            return $this->runner->migrate($this->d1, $log !== null ? ['log' => $log] : []);
        } finally {
            $this->d1->run([['sql' => 'DELETE FROM apotheke_migration_lease WHERE id = 1 AND owner = ?', 'params' => [$owner]]]);
        }
    }
}
