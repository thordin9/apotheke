<?php

declare(strict_types=1);

namespace Apotheke\Core\Config;

use PDO;
use PDOException;
use PgSql\Connection as PgSqlConnection;
use ReflectionProperty;
use RuntimeException;

/**
 * Create a PostgreSQL schema only when pg_namespace has no exact match, then
 * select it. A failed CREATE or SET search_path is not ignored.
 */
final class PgsqlSchema
{
    public function apply(SchemaSession $session, string $schema): void
    {
        if ($schema === '') {
            return;
        }
        $quoted = '"' . str_replace('"', '""', $schema) . '"';
        if (!$session->exists($schema)) {
            try {
                $session->exec('CREATE SCHEMA IF NOT EXISTS ' . $quoted);
            } catch (PDOException $e) {
                $code = (string)$e->getCode();
                $already = in_array($code, ['42501', '42P06', '23505'], true) && $session->exists($schema);
                if (!$already && $code === '42501') {
                    throw new RuntimeException(self::missing($schema));
                }
                if (!$already) {
                    throw $e;
                }
            }
        }
        $session->exec('SET search_path TO ' . $quoted);
        if ($session->currentSchema() !== $schema) {
            throw new RuntimeException(self::missing($schema));
        }
    }

    public static function missing(string $schema): string
    {
        return 'PostgreSQL schema "' . $schema . '" does not exist, and this database role cannot create it. '
            . 'Create the schema, then grant this role USAGE and CREATE on it.';
    }
}

interface SchemaSession
{
    /** @phpstan-impure */
    public function exists(string $schema): bool;

    public function exec(string $sql): void;

    public function currentSchema(): ?string;
}

final class PdoSchemaSession implements SchemaSession
{
    public function __construct(private PDO $pdo)
    {
    }

    public function exists(string $schema): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM pg_catalog.pg_namespace WHERE nspname = ?');
        $st->execute([$schema]);

        return $st->fetchColumn() !== false;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function currentSchema(): ?string
    {
        $value = $this->pdo->query('SELECT current_schema()')->fetchColumn();

        return $value === false || $value === null ? null : (string)$value;
    }
}

/**
 * CI4's Postgre connection. DBDebug is off, so a failed query returns false
 * instead of throwing. CI4's error() has no SQLSTATE for Postgre (its code is
 * always ''), so exec() reads it from the driver result on the native
 * connection. The message text is never parsed: it follows lc_messages.
 */
final class Ci4SchemaSession implements SchemaSession
{
    public function __construct(private object $conn)
    {
    }

    public function exists(string $schema): bool
    {
        $result = $this->conn->query(
            'SELECT 1 AS present FROM pg_catalog.pg_namespace WHERE nspname = ' . $this->conn->escape($schema)
        );
        if ($result === false) {
            $this->failed($this->errorCode());
        }
        $row = $result->getRowArray();

        return is_array($row) && $row !== [];
    }

    public function exec(string $sql): void
    {
        $link = $this->conn->connID ?? null;
        if (!$link instanceof PgSqlConnection) {
            if ($this->conn->query($sql) === false) {
                $this->failed($this->errorCode());
            }

            return;
        }
        if (!pg_send_query($link, $sql)) {
            $this->failed('');
        }
        $state = null;
        while (($result = pg_get_result($link)) !== false) {
            if ($state === null && pg_result_status($result) === PGSQL_FATAL_ERROR) {
                $state = (string)pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);
            }
        }
        if ($state !== null) {
            $this->failed($state);
        }
    }

    public function currentSchema(): ?string
    {
        $result = $this->conn->query('SELECT current_schema() AS schema');
        if ($result === false) {
            $this->failed($this->errorCode());
        }
        $row = $result->getRowArray();
        if (!is_array($row) || !isset($row['schema']) || $row['schema'] === '') {
            return null;
        }

        return (string)$row['schema'];
    }

    /** The driver code from error(), when the driver fills it in. */
    private function errorCode(): string
    {
        $error = $this->conn->error();

        return is_array($error) ? (string)($error['code'] ?? '') : '';
    }

    private function failed(string $code): never
    {
        $e = new PDOException('PostgreSQL query failed');
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, $code);
        throw $e;
    }
}
