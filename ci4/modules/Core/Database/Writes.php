<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Throwable;

/**
 * Checked writes for CodeIgniter connections with DBDebug off (production):
 * every failed statement throws WriteFailed, and a transaction whose
 * statement failed rolls back instead of committing (legacy db_tx() with
 * PDO's exception mode). The message names the step, never a value.
 */
final class Writes
{
    /** A Query Builder insert/update/delete or query() result: false means the statement failed. */
    public static function check(mixed $result, string $step): void
    {
        if ($result === false) {
            throw new WriteFailed('Database write failed: ' . $step);
        }
    }

    /**
     * Rows of a SELECT; a failed statement throws.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     * @param list<int|string|null> $binds
     * @return list<array<string,mixed>>
     */
    public static function rows(BaseConnection $db, string $sql, array $binds = []): array
    {
        $result = $db->query($sql, $binds);
        if (!$result instanceof BaseResult) {
            throw new WriteFailed('Database read failed');
        }
        /** @var list<array<string,mixed>> $rows */
        $rows = $result->getResultArray();

        return $rows;
    }

    /**
     * Run $fn in a transaction, joining one that is already open. Any failed
     * statement inside (transStatus() false) or a failed commit throws and
     * rolls back; the outermost call clears the failure flag afterwards so
     * a long-lived worker can write again.
     *
     * @template T
     * @param BaseConnection<object|resource, object|resource> $db
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(BaseConnection $db, callable $fn): mixed
    {
        $outer = (int)$db->transDepth === 0;
        if ($outer) {
            $db->resetTransStatus();
        }
        if (!$db->transBegin()) {
            throw new WriteFailed('Database write failed: begin');
        }
        try {
            $r = $fn();
            if (!$db->transStatus()) {
                throw new WriteFailed('Database write failed: a statement in the transaction');
            }
            if (!$db->transCommit()) {
                throw new WriteFailed('Database write failed: commit');
            }

            return $r;
        } catch (Throwable $e) {
            $db->transRollback();
            if ($outer) {
                $db->resetTransStatus();
            }
            throw $e;
        }
    }
}
