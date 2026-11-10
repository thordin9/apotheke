<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\WriteFailed;
use LogicException;
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
 *   or temp forms), on prepare, query and exec alike, never on words inside comments or literals;
 * - beginTransaction() queues writes and commit() sends them as one batch, all or none. A write
 *   with RETURNING and lastInsertId() before the answer are refused. A read after a queued write
 *   is refused unless previewReads() is on, in which case it is answered from a local copy of the
 *   committed schema plus the queued writes (so locked callables can still be one atomic batch).
 *   CREATE VIRTUAL TABLE is sent at once so 0019 can catch a missing FTS5 and still apply.
 *
 * Not a general PDO: only what the migrations use (exec, query, prepare/execute,
 * fetch/fetchAll/fetchColumn/foreach, FETCH_KEY_PAIR, closeCursor, quote).
 */
final class D1Pdo extends PDO
{
    /** @var list<string> statements the dialect skipped (for tests and logging). */
    private array $skipped = [];

    /** @var list<array{sql:string,params:list<scalar|null>}> writes of the open transaction, not yet sent */
    private array $queue = [];

    private bool $transaction = false;

    /** When true, a read after a queued write is served from a local preview of the queued batch. */
    private bool $previewReads = false;

    private ?PDO $preview = null;

    /** How many queued statements have already been applied to the preview. */
    private int $previewApplied = 0;

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

    /** Serve mid-transaction reads from a local preview of the queued writes (D1MigrationRunner). */
    public function previewReads(bool $on = true): self
    {
        $this->previewReads = $on;

        return $this;
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
            $this->applyWrites($run);
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
            if ($this->transaction && $this->mutates($sql)) {
                $this->applyWrites([['sql' => $sql, 'params' => []]]);

                return new D1Result([]);
            }
            return new D1Result($this->readRows($sql, []));
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
            if ($this->transaction) {
                if ($this->mutates($sql)) {
                    $this->applyWrites([['sql' => $sql, 'params' => $params]]);

                    return new D1Result([]);
                }
                return new D1Result($this->readRows($sql, $params));
            }
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
        if ($this->transaction) {
            return false;
        }
        $this->transaction = true;
        $this->queue = [];
        $this->resetPreview();

        return true;
    }

    public function commit(): bool
    {
        if (!$this->transaction) {
            return false;
        }
        $queue = $this->queue;
        $this->queue = [];
        $this->transaction = false;
        $this->resetPreview();
        if ($queue !== []) {
            try {
                $this->d1->run($queue);
            } catch (WriteFailed $e) {
                throw D1PdoException::from($e);
            }
        }

        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->transaction) {
            return false;
        }
        $this->queue = [];
        $this->transaction = false;
        $this->resetPreview();

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function lastInsertId(?string $name = null): string
    {
        if ($this->transaction) {
            throw new LogicException('D1 does not know the insert id while writes are queued or after a batch; use a unit of work.');
        }

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
     * @param list<array{sql:string,params:list<scalar|null>}> $statements
     */
    private function applyWrites(array $statements): void
    {
        if (!$this->transaction) {
            $this->d1->run($statements);

            return;
        }
        foreach ($statements as $s) {
            if ($this->returnsRows($s['sql'])) {
                throw new LogicException('D1 cannot return rows from a write inside a transaction; use a unit of work.');
            }
            // 0019 catches a missing FTS5; queuing the CREATE would roll back search_docs too.
            if ($this->isVirtualTableCreate($s['sql'])) {
                $this->d1->run([$s]);
                if ($this->preview !== null) {
                    $this->preview->exec($s['sql']);
                }
                continue;
            }
            $this->queue[] = $s;
        }
    }

    private function refuseReadAfterWrite(): void
    {
        if ($this->transaction && $this->queue !== [] && !$this->previewReads) {
            throw new LogicException('D1 cannot read after a queued write in the same transaction; use a unit of work.');
        }
    }

    private function resetPreview(): void
    {
        $this->preview = null;
        $this->previewApplied = 0;
    }

    /**
     * Rows for a read: from D1 when nothing is queued, otherwise from the local preview.
     *
     * @param list<scalar|null> $params
     * @return list<array<string,mixed>>
     */
    private function readRows(string $sql, array $params): array
    {
        $this->refuseReadAfterWrite();
        if ($this->transaction && $this->queue !== [] && $this->previewReads) {
            return $this->previewQuery($sql, $params);
        }

        return $this->d1->query($sql, $params);
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string,mixed>>
     */
    private function previewQuery(string $sql, array $params): array
    {
        $this->syncPreview();
        $stmt = $this->preview()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function syncPreview(): void
    {
        if ($this->preview === null) {
            $this->preview = new PDO('sqlite::memory:');
            $this->preview->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->preview->exec('PRAGMA foreign_keys = OFF');
            $this->cloneCommitted();
            $this->previewApplied = 0;
        }
        while ($this->previewApplied < count($this->queue)) {
            $s = $this->queue[$this->previewApplied];
            $stmt = $this->preview->prepare($s['sql']);
            $stmt->execute($s['params']);
            $this->previewApplied++;
        }
    }

    private function preview(): PDO
    {
        return $this->preview ?? throw new LogicException('D1 preview is not open.');
    }

    /** Copy the committed D1 schema and rows into the preview so queued writes can be applied on top. */
    private function cloneCommitted(): void
    {
        $objects = $this->d1->query(
            "SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' "
            . "ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 ELSE 2 END, name",
            [],
        );
        $exists = $this->preview()->prepare('SELECT 1 FROM sqlite_master WHERE name = ?');
        foreach ($objects as $o) {
            $sql = (string)($o['sql'] ?? '');
            $name = (string)($o['name'] ?? '');
            if ($sql === '' || $name === '') {
                continue;
            }
            // CREATE VIRTUAL TABLE materialises shadow tables; skip those already created.
            $exists->execute([$name]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }
            $this->preview()->exec($sql);
            if (($o['type'] ?? '') !== 'table') {
                continue;
            }
            $rows = $this->d1->query('SELECT * FROM ' . self::quoteIdent($name), []);
            if ($rows === []) {
                continue;
            }
            $cols = array_keys($rows[0]);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $colList = implode(',', array_map([self::class, 'quoteIdent'], $cols));
            $ins = $this->preview()->prepare('INSERT INTO ' . self::quoteIdent($name) . ' (' . $colList . ') VALUES (' . $placeholders . ')');
            foreach ($rows as $row) {
                $ins->execute(array_map(static fn (string $c): mixed => $row[$c], $cols));
            }
        }
    }

    private static function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function mutates(string $sql): bool
    {
        return self::changes(D1Limits::leading($sql));
    }

    private function returnsRows(string $sql): bool
    {
        $first = D1Limits::leading($sql);

        return self::changes($first) && preg_match('/(?<!")\bRETURNING\b(?!")/i', $first) === 1;
    }

    private function isVirtualTableCreate(string $sql): bool
    {
        return preg_match('/^CREATE\s+VIRTUAL\s+TABLE\b/i', D1Limits::leading($sql)) === 1;
    }

    /** $first (D1Limits::leading()) runs INSERT, UPDATE, DELETE, REPLACE, CREATE, DROP or ALTER as its statement. */
    private static function changes(string $first): bool
    {
        return in_array(self::verb($first), ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'DROP', 'ALTER'], true);
    }

    /** The statement's own keyword: the first word, or after a WITH the first verb outside the CTEs' parentheses. */
    private static function verb(string $first): string
    {
        $word = strtoupper(preg_match('/^\w+/', $first, $m) === 1 ? $m[0] : '');
        if ($word !== 'WITH') {
            return $word;
        }
        $depth = 0;
        for ($i = 4, $n = strlen($first); $i < $n; $i++) {
            $c = $first[$i];
            if ($c === '"') {
                $i = (int)(strpos($first, '"', $i + 1) ?: $n);
            } elseif ($c === '(' || $c === ')') {
                $depth += $c === '(' ? 1 : -1;
            } elseif ($depth === 0 && preg_match('/\G(SELECT|VALUES|INSERT|UPDATE|DELETE|REPLACE)\b/i', $first, $v, 0, $i) === 1
                && preg_match('/\w/', $first[$i - 1]) !== 1) {
                return strtoupper($v[1]);
            }
        }

        return 'WITH';
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
