<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * A PDO-shaped adapter over the D1 HTTP executor (ADR-002, P4-3), so the existing
 * migration callables (typed `PDO $pdo`) run unchanged against D1 through the edge
 * bridge. It speaks D1's dialect only:
 *
 * - connection PRAGMAs (`busy_timeout`, `journal_mode`, `synchronous`, `foreign_keys`)
 *   and `PRAGMA user_version` (read and write) are no-ops: D1 manages connections itself
 *   and has no user_version;
 * - `VACUUM INTO` is skipped (D1 cannot write a file);
 * - a multi-statement string goes to D1's exec() (which has no bindings), one statement
 *   per exec; a single statement with `;` only at the end runs the same way;
 * - a parent table is never dropped (`DROP TABLE items` in the legacy adopt path): D1
 *   ignores `PRAGMA foreign_keys = OFF`, so dropping a referenced parent would fail or
 *   orphan children. The adopt path instead leaves the parent and is a no-op on D1
 *   (a fresh D1 database has no legacy user_version, so it never runs there);
 * - reads go to /query, writes to /batch (one statement) or /exec (multi-statement).
 *
 * Not a general PDO: only what the migrations use (exec, query, prepare/execute,
 * fetch/fetchAll/fetchColumn, FETCH_KEY_PAIR, closeCursor, quote).
 */
final class D1Pdo extends PDO
{
    /** @var list<string> SQL strings whose execution is a no-op on D1 (connection/user_version/VACUUM). */
    private array $skipped = [];

    public function __construct(private readonly D1HttpExecutor $d1)
    {
        // Do not call parent::__construct(): no real connection is opened.
    }

    /** @return list<string> statements the dialect skipped (for tests and logging). */
    public function skippedStatements(): array
    {
        return $this->skipped;
    }

    public function exec(string $statement): int
    {
        $sql = trim($statement);
        if ($sql === '') {
            return 0;
        }
        if ($this->isSkipped($sql)) {
            $this->skipped[] = $sql;

            return 0;
        }
        if ($this->dropsParent($sql)) {
            throw new RuntimeException('D1 migrations must not DROP a parent table (foreign_keys OFF is ignored).');
        }
        // D1 exec() runs one or more statements without bindings. Split only on a real
        // statement boundary so trigger bodies (BEGIN…END) stay whole.
        $count = $this->d1->exec($sql);

        return $count['count'];
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement
    {
        $sql = trim($query);
        if ($this->isSkipped($sql)) {
            $this->skipped[] = $sql;
            // A skipped read (PRAGMA user_version) yields one row with 0, matching a fresh database.
            return new D1Statement([['user_version' => 0]], 0);
        }
        $rows = $this->d1->query($sql);

        return new D1Statement($rows, 0);
    }

    /** @param array<string,mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement
    {
        return new D1Statement([], 0, $this->d1, $query);
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }

    public function beginTransaction(): bool
    {
        // D1 has no interactive transaction; the migration runner uses BEGIN/COMMIT via exec().
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function lastInsertId(?string $name = null): string
    {
        return '0';
    }

    public function errorCode(): ?string
    {
        return null;
    }

    /** @return array{0:string|null,1:int|null,2:string|null} */
    public function errorInfo(): array
    {
        return ['', null, null];
    }

    /** Connection/session statements D1 manages itself or does not support. */
    private function isSkipped(string $sql): bool
    {
        // A compound connection string may hold several PRAGMAs and nothing else.
        $parts = array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== '');
        if ($parts === []) {
            return false;
        }
        foreach ($parts as $p) {
            if (preg_match('/^PRAGMA\s+(busy_timeout|journal_mode|synchronous|foreign_keys)\b/i', $p) === 1) {
                continue;
            }
            if (preg_match('/^PRAGMA\s+user_version\b/i', $p) === 1) {
                continue;
            }
            if (preg_match('/^VACUUM\s+INTO\b/i', $p) === 1) {
                continue;
            }

            return false; // anything else must run, not be skipped
        }

        return true;
    }

    /** Dropping a parent table is refused on D1 (foreign_keys OFF is ignored there). */
    private function dropsParent(string $sql): bool
    {
        return preg_match('/\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:items|locations|categories|users|tags|item_photos|item_tags)\b/i', $sql) === 1;
    }
}

/**
 * A PDOStatement over D1 rows. prepare() binds and runs through /query (reads) or
 * /batch (writes) on execute(); query() results are materialised up front.
 */
final class D1Statement extends PDOStatement
{
    /** @var list<array<string,mixed>> */
    private array $rows;
    private int $pos = 0;
    private int $affected;
    /** @var list<scalar|null> */
    private array $params = [];

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(array $rows = [], int $affected = 0, private readonly ?D1HttpExecutor $d1 = null, private readonly string $sql = '')
    {
        $this->rows = $rows;
        $this->affected = $affected;
        $this->queryString = $sql;
    }

    /** @param list<scalar|null>|null $params */
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? $this->params;
        if ($this->d1 === null) {
            return true;
        }
        $upper = strtoupper((string)strtok(ltrim($this->sql), " \t\n\r"));
        if (in_array($upper, ['SELECT', 'PRAGMA', 'WITH', 'VALUES'], true)) {
            $this->rows = $this->d1->query($this->sql, $this->params);
        } else {
            $this->rows = $this->d1->run([['sql' => $this->sql, 'params' => $this->params]])[0] ?? [];
            $this->affected = count($this->rows);
        }
        $this->pos = 0;

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->pos >= count($this->rows)) {
            return false;
        }
        $row = $this->rows[$this->pos++];

        return $this->shape($row, $mode);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_KEY_PAIR) {
            $out = [];
            foreach ($this->rows as $row) {
                $v = array_values($row);
                $out[$v[0]] = $v[1] ?? null;
            }

            return $out;
        }

        return array_map(fn (array $r) => $this->shape($r, $mode), $this->rows);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->pos >= count($this->rows)) {
            return false;
        }
        $row = array_values($this->rows[$this->pos++]);

        return $row[$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->affected > 0 ? $this->affected : count($this->rows);
    }

    public function columnCount(): int
    {
        return $this->rows === [] ? 0 : count($this->rows[0]);
    }

    public function closeCursor(): bool
    {
        $this->pos = count($this->rows);

        return true;
    }

    /** @param array<string,mixed> $row */
    private function shape(array $row, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_COLUMN => array_values($row)[0] ?? null,
            default => $row, // FETCH_ASSOC and FETCH_DEFAULT
        };
    }
}
