<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\WriteFailed;
use PDO;
use PDOException;
use RuntimeException;

/** Thrown by the D1 adapter so migrations' catch (PDOException) still works (N-1805). */
final class D1PdoException extends PDOException
{
    public static function from(WriteFailed $e): self
    {
        return new self($e->getMessage(), (int)$e->getCode(), $e);
    }
}

/**
 * A PDO-shaped adapter over the D1 channel (ADR-002, P4-3), so the existing
 * migration callables (typed `PDO $pdo`) run unchanged against D1 through the edge
 * bridge. Results are iterable, writes raise a PDOException subclass, and D1's
 * dialect is applied over a small SQL tokenizer:
 *
 * - connection PRAGMAs (busy_timeout, journal_mode, synchronous, foreign_keys) and
 *   PRAGMA user_version (read and write) are skipped, including quoted, schema-qualified
 *   and comment-prefixed forms; a skipped read returns that pragma's D1 answer
 *   (user_version 0, foreign_keys 1);
 * - VACUUM INTO is skipped, even with a semicolon inside the quoted path;
 * - skipped statements mixed with real SQL are stripped and the rest runs;
 * - one migration's statements go in one prepared batch; reads go to query(), writes to run();
 * - a parent table is never dropped (DROP TABLE [IF EXISTS] <ident>, quoted, schema-qualified
 *   or temp forms), on prepare, query and exec alike, never on words inside comments or literals.
 *
 * Not a general PDO: only what the migrations use (exec, query, prepare/execute,
 * fetch/fetchAll/fetchColumn/foreach, FETCH_KEY_PAIR, closeCursor, quote).
 */
final class D1Pdo extends PDO
{
    /** @var list<string> statements the dialect skipped (for tests and logging). */
    private array $skipped = [];

    /** The parent tables a migration must never drop on D1. */
    private const SKIPPED_PRAGMAS = ['busy_timeout', 'journal_mode', 'synchronous', 'foreign_keys', 'user_version'];

    private const PARENT_TABLES = ['items', 'locations', 'categories', 'users', 'tags', 'item_photos', 'item_tags'];

    public function __construct(private readonly D1Executor $d1)
    {
        // Do not call parent::__construct(): no real connection is opened.
    }

    /** @return list<string> */
    public function skippedStatements(): array
    {
        return $this->skipped;
    }

    public function exec(string $statement): int
    {
        $plan = $this->plan($statement);
        $run = [];
        foreach ($plan as $sql) {
            if ($this->isParentDrop($sql)) {
                throw new RuntimeException('D1 migrations must not DROP a parent table (foreign_keys OFF is ignored).');
            }
            $run[] = ['sql' => $sql, 'params' => []];
        }
        if ($run !== []) {
            $this->d1->run($run);
        }

        // D1 gives no affected-row total for the batch; report the statements executed.
        return count($plan);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): D1Result
    {
        $sql = trim($query);
        if ($this->isSkipped($sql)) {
            $this->skipped[] = $sql;

            return new D1Result([$this->pragmaAnswer($sql)]);
        }
        if ($this->isParentDrop($sql)) {
            throw new RuntimeException('D1 migrations must not DROP a parent table (foreign_keys OFF is ignored).');
        }
        try {
            return new D1Result($this->d1->query($sql, []));
        } catch (WriteFailed $e) {
            throw D1PdoException::from($e);
        }
    }

    /** @param array<string,mixed> $options */
    public function prepare(string $query, array $options = []): D1Statement
    {
        return new D1Statement($query, $this);
    }

    /** @internal used by D1Statement to classify and run a prepared statement. */
    /** @param list<scalar|null> $params */
    public function runPrepared(string $sql, array $params): D1Result
    {
        if ($this->isParentDrop($sql)) {
            throw new RuntimeException('D1 migrations must not DROP a parent table (foreign_keys OFF is ignored).');
        }
        try {
            $upper = strtoupper((string)strtok(ltrim($sql), " \t\n\r"));
            if (in_array($upper, ['SELECT', 'PRAGMA', 'WITH', 'VALUES'], true)) {
                return new D1Result($this->d1->query($sql, $params));
            }

            return new D1Result($this->d1->run([['sql' => $sql, 'params' => $params]])[0] ?? []);
        } catch (WriteFailed $e) {
            throw D1PdoException::from($e);
        }
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }

    public function beginTransaction(): bool
    {
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

    /**
     * Split a statement string into the statements that should actually run on D1,
     * dropping the skipped ones (connection/user_version pragmas, VACUUM INTO).
     * A string that is entirely one skipped pragma yields nothing (the caller no-ops).
     *
     * @return list<string>
     */
    private function plan(string $statement): array
    {
        $out = [];
        foreach (D1Sql::split($statement) as $sql) {
            if ($this->isSkipped($sql)) {
                $this->skipped[] = $sql;
                continue;
            }
            $out[] = $sql;
        }

        return $out;
    }

    /** Whether every statement in $sql is a skipped pragma / VACUUM INTO (for query()). */
    private function isSkipped(string $sql): bool
    {
        $parts = D1Sql::split($sql);
        if ($parts === []) {
            return false;
        }
        foreach ($parts as $p) {
            if (!self::isSkippableStatement($p)) {
                return false;
            }
        }

        return true;
    }


    /** The bare pragma name (unquoted, schema qualifier removed), or null if not a PRAGMA. */
    private static function pragmaName(string $head): ?string
    {
        if (preg_match('/^PRAGMA\s+(.*?)\s*(?:=|\(|$)/is', $head, $m) !== 1) {
            return null;
        }
        $name = D1Sql::lastIdentifier($m[1]);
        if ($name === null) {
            return null;
        }

        return strtolower($name);
    }

    /** A single statement is skippable if it is a connection/user_version pragma or VACUUM INTO. */
    private static function isSkippableStatement(string $sql): bool
    {
        $head = D1Sql::head($sql);
        $name = self::pragmaName($head);
        if ($name !== null && in_array($name, self::SKIPPED_PRAGMAS, true)) {
            return true;
        }

        return preg_match('/^VACUUM\s+INTO\b/i', $head) === 1;
    }

    /**
     * The synthetic row a skipped pragma read returns (its D1 answer).
     *
     * @return array<string,mixed>
     */
    private static function pragmaAnswer(string $sql): array
    {
        $head = D1Sql::head($sql);
        $name = self::pragmaName($head);
        if ($name === 'foreign_keys') {
            return ['foreign_keys' => 1]; // D1 enforces foreign keys
        }
        if ($name !== null && in_array($name, ['journal_mode', 'busy_timeout', 'synchronous'], true)) {
            return [$name => null]; // D1 has no SQLite connection-mode value to report.
        }

        return ['user_version' => 0];
    }

    /** DROP TABLE [IF EXISTS] <ident> naming a parent table, after stripping comments and literals. */
    private function isParentDrop(string $sql): bool
    {
        foreach (D1Sql::split($sql) as $statement) {
            $head = D1Sql::head($statement);
            if (preg_match('/(?:^|\s)DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(.*?)\s*$/is', $head, $m) !== 1) {
                continue;
            }
            $ident = D1Sql::lastIdentifier($m[1]);
            if ($ident !== null && in_array(strtolower($ident), self::PARENT_TABLES, true)) {
                return true;
            }
        }

        return false;
    }
}
