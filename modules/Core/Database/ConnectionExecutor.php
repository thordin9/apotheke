<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Throwable;

/**
 * The default executor: a CodeIgniter connection (SQLite3 or Postgre, the
 * self-hosted "PDO path"). A batch runs inside Writes::transaction(), so it
 * joins an open transaction, and any failed statement rolls it back.
 */
final class ConnectionExecutor implements SqlExecutor
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function dialect(): Dialect
    {
        return Dialect::of($this->db);
    }

    public function connection(): BaseConnection
    {
        return $this->db;
    }

    public function run(array $statements): array
    {
        return Writes::transaction($this->db, function () use ($statements): array {
            $out = [];
            foreach ($statements as $s) {
                $out[] = $this->execute($s['sql'], $s['params'], 'the batch');
            }

            return $out;
        });
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params, 'read');
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string,mixed>>
     */
    private function execute(string $sql, array $params, string $step): array
    {
        // Replacing the connection's last SQLite result finalizes its statement, which clears the error of a statement
        // that failed just before (so a guard would read as "not an error"): release it first.
        $this->db->resultID = false;
        try {
            $result = $this->db->query($sql, $params);
            $error = $result === false ? (string)($this->db->error()['message'] ?? '') : '';
        } catch (Throwable $e) {
            $result = false;
            $error = $e->getMessage();
        }
        if ($result === false) {
            // No database message is passed on (it may hold a value).
            $guard = self::isGuardFailure($error);
            throw new WriteFailed('Database write failed: ' . ($guard ? 'a guard in the unit' : $step), $guard ? self::GUARD_FAILED : 0);
        }
        if (!$result instanceof BaseResult) {
            return [];
        }
        /** @var list<array<string,mixed>> $rows */
        $rows = $result->getResultArray();

        return $rows;
    }

    /**
     * A guard failed: the error is the NOT NULL violation of uow_guard.ok,
     * matched on the whole primary message (SQLite's own text, PostgreSQL's
     * first line), so the table and column identifiers decide, never a row
     * value: those only appear in PostgreSQL's DETAIL line or inside another
     * error's text, which does not match. The CodeIgniter drivers expose no
     * SQLSTATE for PostgreSQL; its text is the server's English message, and
     * any other text is an ordinary failure, never a guard.
     */
    private static function isGuardFailure(string $error): bool
    {
        $first = rtrim((string)strtok($error, "\n"));

        return preg_match('/^(?:(?:[A-Za-z ]+: )?NOT NULL constraint failed: uow_guard\.ok'
            . '|(?:ERROR:\s+)?null value in column "ok" of relation "uow_guard" violates not-null constraint)$/', $first) === 1;
    }
}
