<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use CodeIgniter\Database\BaseConnection;

/**
 * The executor adapter (ADR-002, decision 2): all SQL of a request goes to
 * one executor, built per request by Config\Services::sqlExecutor() and
 * never kept between requests.
 *
 * - ConnectionExecutor (the default, self-hosted SQLite and PostgreSQL):
 *   a CodeIgniter connection, with real interactive transactions.
 * - The D1 and Durable Object executors (P4-3) have batches only:
 *   connection() is null there, and a unit of work runs as one batch.
 */
interface SqlExecutor extends BatchExecutor
{
    public function dialect(): Dialect;

    /**
     * The connection for interactive transactions (PDO path), or null
     * when the backend only runs batches.
     *
     * @return BaseConnection<object|resource, object|resource>|null
     */
    public function connection(): ?BaseConnection;
}
