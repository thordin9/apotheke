<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use CodeIgniter\Database\BaseConnection;

/**
 * The SQL that differs between SQLite (also D1 and Durable Object SQLite)
 * and PostgreSQL, for the executor adapter (ADR-002, decision 2). Stateless.
 */
final class Dialect
{
    public const SQLITE = 'sqlite';
    public const POSTGRES = 'pgsql';

    private function __construct(public readonly string $name)
    {
    }

    public static function sqlite(): self
    {
        return new self(self::SQLITE);
    }

    public static function postgres(): self
    {
        return new self(self::POSTGRES);
    }

    /** @param BaseConnection<object|resource, object|resource> $db */
    public static function of(BaseConnection $db): self
    {
        return $db->DBDriver === 'Postgre' ? self::postgres() : self::sqlite();
    }

    /**
     * A subquery over a list bound as ONE parameter (D1 allows 100 bound
     * parameters per statement): `col IN <inList()>` with list() as its bind.
     * $type is 'int' or 'text'.
     */
    public function inList(string $type = 'int'): string
    {
        if ($this->name === self::POSTGRES) {
            return $type === 'int' ? '(SELECT json_array_elements_text(?::json)::bigint)' : '(SELECT json_array_elements_text(?::json))';
        }

        return '(SELECT value FROM json_each(?))';
    }

    /** @param array<int|string> $values the bind value for inList(), in order */
    public static function list(array $values): string
    {
        return (string)json_encode(array_values($values), JSON_THROW_ON_ERROR);
    }
}
