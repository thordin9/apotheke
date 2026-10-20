<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use SQLite3;
use Throwable;

/** trust_proxy from the settings table. Missing table, file or driver means not trusted. */
final class TrustProxyReader
{
    /** @param array<string,mixed> $group */
    public function __construct(private array $group)
    {
    }

    public function trusted(): bool
    {
        try {
            $driver = (string)($this->group['DBDriver'] ?? '');
            if ($driver === 'SQLite3') {
                return $this->sqlite();
            }
            if ($driver === 'Postgre') {
                return $this->postgres();
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function sqlite(): bool
    {
        $path = (string)($this->group['database'] ?? '');
        if ($path === '' || $path === ':memory:' || !is_file($path)) {
            return false;
        }
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $stmt = $db->prepare('SELECT value FROM settings WHERE name = :name');
        if ($stmt === false) {
            $db->close();

            return false;
        }
        $stmt->bindValue(':name', 'trust_proxy', SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result === false ? false : $result->fetchArray(SQLITE3_ASSOC);
        $db->close();
        if (!is_array($row) || !isset($row['value'])) {
            return false;
        }

        return $this->truthy((string)$row['value']);
    }

    private function postgres(): bool
    {
        if (!function_exists('pg_connect')) {
            return false;
        }
        $dsn = (string)($this->group['DSN'] ?? '');
        if ($dsn === '') {
            return false;
        }
        $conn = @pg_connect($dsn);
        if ($conn === false) {
            return false;
        }
        if (!$this->applySchema($conn)) {
            pg_close($conn);

            return false;
        }
        $result = @pg_query_params($conn, 'SELECT value FROM settings WHERE name = $1', ['trust_proxy']);
        if ($result === false) {
            pg_close($conn);

            return false;
        }
        $row = pg_fetch_assoc($result);
        pg_close($conn);
        if (!is_array($row) || !isset($row['value'])) {
            return false;
        }

        return $this->truthy((string)$row['value']);
    }

    private function applySchema(\PgSql\Connection $conn): bool
    {
        $schema = (string)($this->group['schema'] ?? '');
        if ($schema === '') {
            return true;
        }
        $quoted = '"' . str_replace('"', '""', $schema) . '"';

        return @pg_query($conn, 'SET search_path TO ' . $quoted) !== false;
    }

    private function truthy(string $raw): bool
    {
        $decoded = json_decode($raw, true);
        if ($decoded === null && strtolower(trim($raw)) !== 'null') {
            $decoded = $raw;
        }

        return filter_var($decoded, FILTER_VALIDATE_BOOLEAN) === true;
    }
}
