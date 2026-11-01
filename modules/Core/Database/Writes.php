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
     * Rows of a SELECT typed as legacy's PDO returns them, for rows that are
     * stored as JSON (undo snapshots). The Postgre driver returns every
     * column as a string; pdo_pgsql returns integer columns as int, float4
     * and float8 as float and boolean as bool (numeric stays a string).
     * SQLite3 already returns the storage class, as pdo_sqlite does.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     * @param list<int|string|null> $binds
     * @return list<array<string,mixed>>
     */
    public static function typedRows(BaseConnection $db, string $sql, array $binds = []): array
    {
        $result = $db->query($sql, $binds);
        if (!$result instanceof BaseResult) {
            throw new WriteFailed('Database read failed');
        }
        /** @var list<array<string,mixed>> $rows */
        $rows = $result->getResultArray();
        if ($db->DBDriver !== 'Postgre' || $rows === []) {
            return $rows;
        }
        $types = [];
        foreach ($result->getFieldData() as $field) {
            $types[(string)$field->name] = (string)$field->type_name;
        }
        foreach ($rows as &$row) {
            foreach ($row as $name => &$value) {
                if (!is_string($value)) {
                    continue;
                }
                $value = match ($types[$name] ?? '') {
                    'int2', 'int4', 'int8' => (int)$value,
                    'float4', 'float8' => (float)$value,
                    'bool' => $value === 't',
                    default => $value,
                };
            }
            unset($value);
        }
        unset($row);

        return $rows;
    }

    /**
     * Run $fn in a transaction, joining one that is already open. Any failed
     * statement inside (transStatus() false) or a failed commit throws and
     * rolls back; the outermost call clears the failure flag afterwards so
     * a long-lived worker can write again.
     *
     * Nesting is flat, as in legacy db_tx(): there is no SAVEPOINT, so an
     * inner call only joins the open transaction and its rollback undoes
     * nothing by itself. A caller that catches an inner UserError (or any
     * exception that is not a failed statement) and carries on commits the
     * inner statements with its own. A failed statement inside still fails
     * the whole outer transaction through transStatus(). A unit of work is
     * the exception: UnitsOfWork::run() inside an open transaction runs the
     * unit under a SAVEPOINT, so it is kept whole or not at all.
     *
     * A commit the database refuses (a deferred constraint) throws
     * WriteFailed whether the driver returns false or raises its own error.
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
            try {
                $committed = $db->transCommit();
            } catch (Throwable) {
                $committed = false;
            }
            if (!$committed) {
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

    /**
     * Run $fn under a SAVEPOINT of the open transaction: when $fn throws, its
     * statements are rolled back and the outer transaction carries on as it
     * was. Used by a unit of work that joins a plain transaction (L-1625) and
     * by the local batch executor. Both databases behave the same:
     *
     * - An outer transaction whose statement already failed takes no
     *   savepoint (PostgreSQL refuses one there): this throws, and the
     *   outer failure stands (L-1630).
     * - A statement that failed inside $fn fails it even when $fn caught the
     *   error (SQLite would carry on, PostgreSQL would not): checked before
     *   RELEASE, so the savepoint is rolled back instead (L-1631).
     * - On success the savepoint is released, so none pile up (N-1632).
     *
     * @template T
     * @param BaseConnection<object|resource, object|resource> $db
     * @param callable():T $fn
     * @return T
     */
    public static function savepoint(BaseConnection $db, callable $fn): mixed
    {
        if (self::failed($db)) {
            throw new WriteFailed('Database write failed: a statement in the transaction');
        }
        self::check($db->query('SAVEPOINT apotheke_uow'), 'savepoint');
        try {
            $r = $fn();
            if (self::failed($db)) {
                throw new WriteFailed('Database write failed: a statement in the unit of work');
            }
        } catch (Throwable $e) {
            if ($db->query('ROLLBACK TO SAVEPOINT apotheke_uow') !== false && $db->query('RELEASE SAVEPOINT apotheke_uow') !== false) {
                $db->resetTransStatus();
            }
            throw $e;
        }
        self::check($db->query('RELEASE SAVEPOINT apotheke_uow'), 'savepoint');

        return $r;
    }

    /**
     * Whether a statement of the open transaction failed (read anew each time: the flag changes with every statement).
     *
     * @phpstan-impure
     * @param BaseConnection<object|resource, object|resource> $db
     */
    private static function failed(BaseConnection $db): bool
    {
        return !$db->transStatus();
    }
}
