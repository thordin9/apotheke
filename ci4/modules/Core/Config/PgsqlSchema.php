<?php

declare(strict_types=1);

namespace Apotheke\Core\Config;

use PDO;
use PDOException;
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

/** CI4's Postgre connection. DBDebug is off, so a failed query returns false instead of throwing. */
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
            $this->failed();
        }
        $row = $result->getRowArray();

        return is_array($row) && $row !== [];
    }

    public function exec(string $sql): void
    {
        if ($this->conn->query($sql) === false) {
            $this->failed();
        }
    }

    public function currentSchema(): ?string
    {
        $result = $this->conn->query('SELECT current_schema() AS schema');
        if ($result === false) {
            $this->failed();
        }
        $row = $result->getRowArray();
        if (!is_array($row) || !isset($row['schema']) || $row['schema'] === '') {
            return null;
        }

        return (string)$row['schema'];
    }

    private function failed(): never
    {
        $error = $this->conn->error();
        $message = is_array($error) ? (string)($error['message'] ?? '') : '';
        $code = self::sqlstate($message);
        $e = new PDOException('PostgreSQL query failed');
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, $code);
        throw $e;
    }

    public static function sqlstate(string $message): string
    {
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $message, $m) === 1) {
            return $m[1];
        }
        if (str_contains($message, 'permission denied')) {
            return '42501';
        }
        if (str_contains($message, 'already exists')) {
            return '42P06';
        }
        if (str_contains($message, 'duplicate key') || str_contains($message, 'pg_namespace_nspname_index')) {
            return '23505';
        }

        return '';
    }
}
