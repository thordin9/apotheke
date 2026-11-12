<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

/**
 * The writes one D1 connection sent, counted per table, so ReadCache can tell whether a remembered read is still
 * what the database holds. A statement that is not a SELECT counts as a write: INSERT, UPDATE, DELETE and REPLACE
 * against their table, anything else (DDL, PRAGMA, a WITH, a name it cannot read) against every table. The
 * counters only grow, so a connection that outlives a request (worker mode) keeps them safely.
 */
final class WriteClock
{
    private int $all = 0;

    /** @var array<string,int> table => writes */
    private array $tables = [];

    /** Count $sql as a write when it is not a SELECT. */
    public function wrote(string $sql): void
    {
        if (preg_match('/^\s*SELECT\b/i', $sql) === 1) {
            return;
        }
        $table = self::table($sql);
        if ($table === null) {
            $this->all++;

            return;
        }
        $this->tables[$table] = ($this->tables[$table] ?? 0) + 1;
    }

    /** Count a write against every table (one sent past the connection, such as a migration). */
    public function wroteEverything(): void
    {
        $this->all++;
    }

    /**
     * The current counters of $tables (and of the writes that count against every table).
     *
     * @param list<string> $tables
     * @return array<string,int>
     */
    public function stamp(array $tables): array
    {
        $out = ['*' => $this->all];
        foreach ($tables as $t) {
            $out[$t] = $this->tables[$t] ?? 0;
        }

        return $out;
    }

    /** The table an INSERT, UPDATE, DELETE or REPLACE writes, lower-cased; null for any other statement. */
    public static function table(string $sql): ?string
    {
        $name = '[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?';
        $verb = '(?:INSERT(?:\s+OR\s+[A-Z]+)?\s+INTO|REPLACE\s+INTO|UPDATE(?:\s+OR\s+[A-Z]+)?|DELETE\s+FROM)';
        if (preg_match('/^\s*' . $verb . '\s+(?:[`"]?[A-Za-z_][A-Za-z0-9_]*[`"]?\.)?' . $name . '/i', $sql, $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }
}
