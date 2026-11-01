<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\DatabaseUnavailable;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;
use JsonException;

/**
 * The SQL that differs between SQLite (also D1 and Durable Object SQLite)
 * and PostgreSQL, for the executor adapter (ADR-002, decision 2). Stateless.
 */
final class Dialect
{
    public const SQLITE = 'sqlite';
    public const POSTGRES = 'pgsql';

    /** The oldest SQLite with json_each() built in (JSON1 is part of the core since 3.38.0). */
    public const SQLITE_MIN = '3.38.0';

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

    /**
     * @param array<int|string> $values the bind value for inList(), in order
     * @throws WriteFailed when a value is not valid UTF-8 (the list cannot be bound)
     */
    public static function list(array $values): string
    {
        try {
            return json_encode(array_values($values), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WriteFailed('Database write failed: a list value is not valid UTF-8');
        }
    }

    /**
     * N-1623: refuse an SQLite older than SQLITE_MIN, whose json_each() the
     * id lists need (it may be missing, or an optional extension, before
     * 3.38). The message names the versions only.
     *
     * @throws DatabaseUnavailable
     */
    public static function requireSqlite(string $version): void
    {
        if (version_compare($version, self::SQLITE_MIN, '<')) {
            throw new DatabaseUnavailable(sprintf('SQLite %s or newer is required (JSON functions); this PHP has SQLite %s.', self::SQLITE_MIN, $version));
        }
    }
}
