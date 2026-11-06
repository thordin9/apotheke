<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\SqlExecutor;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Closure;
use JsonException;
use LogicException;

/**
 * A restore on a batch-only database (D1, Durable Object SQLite; docs/database.md, "Restore on the edge"): the
 * backup's rows go into staging copies first, in many small batches, and one batch then swaps them in. Only that
 * swap changes the live tables, so the restore is all or nothing even though it is staged in parts:
 *
 * - begin(): drop any leftover copies and create an empty copy of each carried table (PREFIX + table);
 * - stage(): a table's rows, at most CHUNK_BYTES of JSON and CHUNK_ROWS rows per statement (N-1759: items
 *   use a smaller byte cap), each one bind (json_each on SQLite and D1, json_populate_recordset on
 *   PostgreSQL), so no statement has more than one parameter;
 * - swap(): one batch: deferred foreign keys, a guard per copy that it holds every row staged, the live tables
 *   emptied (children first) and filled from the copies (parents first), the sequences moved past the restored
 *   ids, and the restore_marker row of this run;
 * - drop(): remove the copies (after the swap, or the leftovers of a stopped restore).
 *
 * A restore stopped before the swap changed nothing (its copies are leftovers); one stopped after it is applied,
 * and applied() says so from its run id. Self-hosted installs keep Restorer's streaming path; this works on any
 * executor so the parity tests run it in both modes.
 *
 * One staged restore at a time (L-1700): begin() takes the lock row in restore_marker (run LOCK, sha256 this
 * stage's random token, applied_at when it expires). Every later batch (stage, swap, drop) first checks that this
 * stage still holds it, so a restore whose lock was taken over can neither touch the copies nor swap them in.
 * Each staged chunk renews it; a stopped restore's lock expires LEASE seconds after its last chunk (L-1708). The
 * times are the database's clock, so every instance agrees on them (N-1711).
 *
 * L-1707: restore_marker keeps the last KEEP runs, so outcome() can tell a stopped run that was applied and then
 * replaced by a later restore from one that never was, and says unknown once the run is older than that. L-1713: the
 * runs are ordered by the row's database-assigned id (seq()), not by applied_at, which has whole seconds only.
 *
 * N-1704: stage() takes only columns the live table has, each once, and values as Restorer binds them: a boolean
 * as 1 or 0 (so PostgreSQL's integer columns take it as SQLite does), anything but a plain value refused.
 */
final class RestoreStage
{
    /** Staging copies: no carried, CI4-only or SQLite/D1 reserved table starts with it. */
    public const PREFIX = 'apotheke_stage_';

    /** JSON per staged insert, one bind (D1 allows 2 MB per value; a larger row goes alone). */
    public const CHUNK_BYTES = 512 * 1024;

    /** Soft row cap per staged insert (N-1759): keeps a wide table's chunk under D1's 30 s request limit. */
    public const CHUNK_ROWS = 1000;

    /**
     * Per-table byte caps under CHUNK_BYTES for tables whose rows are wide (N-1759: items was the tightest
     * real-D1 stage margin). A missing key uses CHUNK_BYTES.
     *
     * @var array<string, int>
     */
    public const CHUNK_BYTES_FOR = [
        'items' => 256 * 1024,
    ];

    public const UNKNOWN_COLUMN = 'The backup names a column the table does not have, or one column twice. Nothing was changed.';

    public const TOO_LARGE = 'This backup does not fit next to the current data within the database size limit. Nothing was changed.';

    /** The run of the lock row in restore_marker (a run id is 32 hex digits, never this). */
    public const LOCK = 'lock';

    /** Seconds the lock is held without a staged chunk (each chunk renews it; a crash frees it after this). */
    public const LEASE = 120;

    /** The runs restore_marker keeps (L-1707). */
    public const KEEP = 20;

    /** outcome(): the run's swap committed and is the last one. */
    public const APPLIED = 'applied';

    /** outcome(): the run's swap committed, and a later restore has been swapped in since. */
    public const REPLACED = 'replaced';

    /** outcome(): the run was never swapped in. */
    public const NONE = 'none';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    private readonly string $token;

    /** @param ?Closure():int $now the time (a test seam; null: the database's clock) */
    public function __construct(private readonly SqlExecutor $exec, private readonly ?Closure $now = null)
    {
        $this->token = bin2hex(random_bytes(16));
    }

    /**
     * Refuse before anything is staged when the copies would not fit: the live data and the copies are both there
     * until the swap, which replaces the live rows by as many again, so the peak is max(live, staged) + staged.
     * $limit 0 is no limit.
     *
     * @throws UserError TOO_LARGE
     */
    public static function preflight(int $liveBytes, int $stagedBytes, int $limitBytes): void
    {
        if ($limitBytes > 0 && max($liveBytes, $stagedBytes) + $stagedBytes > $limitBytes) {
            throw new UserError(self::TOO_LARGE, 413);
        }
    }

    /** The database's size (SQLite and D1 pages, PostgreSQL's database size). */
    public function liveBytes(): int
    {
        $sql = $this->exec->dialect()->name === Dialect::POSTGRES
            ? 'SELECT pg_database_size(current_database()) AS n'
            : 'SELECT page_count * page_size AS n FROM pragma_page_count(), pragma_page_size()';

        return (int)($this->exec->query($sql)[0]['n'] ?? 0);
    }

    /**
     * The bytes stage() would write for $rows (its JSON), for the pre-flight.
     *
     * @param list<string> $columns
     * @param iterable<list<scalar|null>> $rows
     */
    public function bytes(array $columns, iterable $rows): int
    {
        $pg = $this->exec->dialect()->name === Dialect::POSTGRES;
        $n = 0;
        foreach ($rows as $row) {
            $row = self::values($row);
            $n += strlen(self::json($pg && count($row) === count($columns) ? array_combine($columns, $row) : $row)) + 1;
        }

        return $n;
    }

    /**
     * Take the lock (BUSY while another staged restore holds it), drop any leftover copies and create an empty copy
     * of each table.
     *
     * @param list<string> $tables the carried tables to restore, a subset of Backups::tables()
     */
    public function begin(array $tables): void
    {
        $create = array_map(fn (string $t): array => ['sql' => 'CREATE TABLE ' . self::copy($t) . ' AS SELECT * FROM ' . self::table($t) . ' WHERE 1 = 0', 'params' => []], $tables);
        $this->claim();
        $this->exec->run([$this->held(), ...self::drops(), ...$create]);
    }

    /** Take the lock row, or renew it; another holder's that has not expired: BUSY (409). */
    public function claim(): void
    {
        [$now, $p] = $this->at(0);
        [$until, $q] = $this->at(self::LEASE);
        try {
            $this->exec->run([
                ['sql' => "DELETE FROM restore_marker WHERE run = ? AND (applied_at < $now OR sha256 = ?)", 'params' => [self::LOCK, ...$p, $this->token]],
                ['sql' => "INSERT INTO restore_marker (run, sha256, applied_at) SELECT ?, ?, $until WHERE NOT EXISTS (SELECT 1 FROM restore_marker WHERE run = ?)", 'params' => [self::LOCK, $this->token, ...$q, self::LOCK]],
                $this->held(),
            ]);
        } catch (WriteFailed $e) {
            throw new UserError(Restorer::BUSY, 409, $e);
        }
    }

    /** Give the lock up (only this stage's own). */
    public function release(): void
    {
        $this->exec->run([['sql' => 'DELETE FROM restore_marker WHERE run = ? AND sha256 = ?', 'params' => [self::LOCK, $this->token]]]);
    }

    /** Drop the copies, then give the lock up. */
    public function finish(): void
    {
        try {
            $this->drop();
        } finally {
            $this->release();
        }
    }

    /**
     * N-1705: drop a stopped restore's leftover copies, unless a staged restore holds the lock. True when none are
     * left. N-1710: read first, so it writes only when there are copies or a stale lock to clear.
     */
    public function sweep(): bool
    {
        [$now, $p] = $this->at(0);
        $lock = $this->exec->query("SELECT CASE WHEN applied_at < $now THEN 1 ELSE 0 END AS stale FROM restore_marker WHERE run = ?", [...$p, self::LOCK]);
        if ($lock !== [] && (int)$lock[0]['stale'] === 0) {
            return false;
        }
        $pg = $this->exec->dialect()->name === Dialect::POSTGRES;
        $copies = $this->exec->query($pg
            ? "SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = current_schema() AND table_name LIKE 'apotheke!_stage!_%' ESCAPE '!'"
            : "SELECT 1 AS ok FROM sqlite_master WHERE type = 'table' AND name LIKE 'apotheke!_stage!_%' ESCAPE '!'");
        if ($lock === [] && $copies === []) {
            return true;
        }
        try {
            $this->claim();
        } catch (UserError) {
            return false;
        }
        $this->finish();

        return true;
    }

    /**
     * Stage $rows (each a list in $columns' order) into $table's copy.
     *
     * @param list<string> $columns
     * @param iterable<list<scalar|null>> $rows
     * @return int the rows staged
     */
    public function stage(string $table, array $columns, iterable $rows, ?Closure $beat = null): int
    {
        $copy = self::copy($table);
        $cols = implode(', ', array_map(self::column(...), $columns));
        $this->checkColumns($table, $columns);
        $pg = $this->exec->dialect()->name === Dialect::POSTGRES;
        $sql = $pg
            ? "INSERT INTO $copy ($cols) SELECT $cols FROM json_populate_recordset(NULL::$copy, ?::json)"
            : "INSERT INTO $copy ($cols) SELECT " . implode(', ', array_map(static fn (int $i): string => "value ->> $i", array_keys($columns))) . ' FROM json_each(?)';
        $maxBytes = self::CHUNK_BYTES_FOR[$table] ?? self::CHUNK_BYTES;
        $n = 0;
        $chunk = [];
        $bytes = 0;
        foreach ($rows as $row) {
            if (count($row) !== count($columns)) {
                throw new LogicException('A staged row must have one value per column.');
            }
            $row = self::values($row);
            $json = self::json($pg ? array_combine($columns, $row) : $row);
            if ($chunk !== [] && ($bytes + strlen($json) + 1 > $maxBytes || count($chunk) >= self::CHUNK_ROWS)) {
                if ($beat !== null) {
                    $beat();
                }
                $this->insert($sql, $chunk);
                [$chunk, $bytes] = [[], 0];
            }
            $chunk[] = $json;
            $bytes += strlen($json) + 1;
            $n++;
        }
        if ($chunk !== []) {
            if ($beat !== null) {
                $beat();
            }
            $this->insert($sql, $chunk);
        }

        return $n;
    }

    /**
     * N-1704: every column one the live table has, and none twice (SQLite would take `id, id`).
     *
     * @param list<string> $columns
     * @throws UserError UNKNOWN_COLUMN
     */
    private function checkColumns(string $table, array $columns): void
    {
        $sql = $this->exec->dialect()->name === Dialect::POSTGRES
            ? 'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?'
            : 'SELECT name FROM pragma_table_info(?)';
        $live = array_column($this->exec->query($sql, [self::table($table)]), 'name');
        if ($columns === [] || count(array_unique($columns)) !== count($columns) || array_diff($columns, $live) !== []) {
            throw new UserError(self::UNKNOWN_COLUMN);
        }
    }

    /**
     * N-1704: a row's values as Restorer binds them: a boolean as 1 or 0; an array or object is not a plain value.
     *
     * @param array<array-key,mixed> $row
     * @return list<scalar|null>
     */
    private static function values(array $row): array
    {
        $out = [];
        foreach ($row as $v) {
            if (!is_scalar($v) && $v !== null) {
                throw new UserError(Restorer::FAILED);
            }
            $out[] = is_bool($v) ? (int)$v : $v;
        }

        return $out;
    }

    /** @param list<string> $chunk one chunk of JSON rows, while the lock is held, which it renews */
    private function insert(string $sql, array $chunk): void
    {
        [$until, $q] = $this->at(self::LEASE);
        $this->exec->run([$this->held(), ['sql' => $sql, 'params' => ['[' . implode(',', $chunk) . ']']],
            ['sql' => 'UPDATE restore_marker SET applied_at = ' . $until . ' WHERE run = ? AND sha256 = ?', 'params' => [...$q, self::LOCK, $this->token]]]);
    }

    /**
     * Swap the copies in, as one batch, and mark run $run applied (at the database's time).
     *
     * @param list<string> $tables every carried table that exists, in Backups::tables() order: all are emptied
     * @param array<string,array{columns:list<string>,rows:int}> $staged per staged table: its columns and row count
     * @param list<string> $withId the tables with an id column (their sequences move past the restored ids)
     */
    public function swap(array $tables, array $staged, array $withId, string $run, string $sha256): void
    {
        $pg = $this->exec->dialect()->name === Dialect::POSTGRES;
        if ($run === self::LOCK) {
            throw new LogicException('A run id is not the lock.');
        }
        $s = $pg ? [] : [['sql' => 'PRAGMA defer_foreign_keys = ON', 'params' => []]];
        $s[] = $this->held();
        foreach ($staged as $t => $plan) {
            $s[] = ['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL WHERE (SELECT COUNT(*) FROM ' . self::copy($t) . ') <> ?', 'params' => [$plan['rows']]];
        }
        foreach (array_reverse($tables) as $t) {
            $s[] = ['sql' => 'DELETE FROM ' . self::table($t), 'params' => []];
        }
        foreach ($tables as $t) {
            if (isset($staged[$t])) {
                $cols = implode(', ', array_map(self::column(...), $staged[$t]['columns']));
                $s[] = ['sql' => 'INSERT INTO ' . self::table($t) . " ($cols) SELECT $cols FROM " . self::copy($t), 'params' => []];
            }
        }
        foreach (array_values(array_intersect($tables, $withId)) as $t) {
            // as Restorer::advanceSequences(): past the highest id and, for items, past every id in the history
            $max = "SELECT MAX(id) AS m FROM $t" . ($t === 'items' && in_array('activity', $tables, true) ? ' UNION ALL SELECT MAX(item_id) AS m FROM activity' : '');
            if ($pg) {
                $s[] = ['sql' => "SELECT setval(pg_get_serial_sequence('$t', 'id'), COALESCE(MAX(m), 0) + 1, false) AS v FROM ($max) x", 'params' => []];
            } else {
                $s[] = ['sql' => 'DELETE FROM sqlite_sequence WHERE name = ?', 'params' => [$t]];
                $s[] = ['sql' => "INSERT INTO sqlite_sequence (name, seq) SELECT ?, CAST(COALESCE(MAX(m), 0) AS TEXT) FROM ($max) x", 'params' => [$t]];   // text, as legacy binds it
            }
        }
        // L-1707: the last KEEP runs are kept, this one included (L-1713: newest by seq())
        $s[] = ['sql' => 'DELETE FROM restore_marker WHERE run <> ? AND run NOT IN (SELECT run FROM (SELECT run FROM restore_marker WHERE run <> ? ORDER BY ' . $this->seq() . ' DESC LIMIT ' . (self::KEEP - 1) . ') k)', 'params' => [self::LOCK, self::LOCK]];
        [$now, $p] = $this->at(0);
        $s[] = ['sql' => "INSERT INTO restore_marker (run, sha256, applied_at) VALUES (?, ?, $now)", 'params' => [$run, $sha256, ...$p]];
        $this->exec->run($s);
    }

    /** Whether the swap of run $run committed (it is the last one marked). */
    public function applied(string $run): bool
    {
        return $run !== self::LOCK && $this->exec->query('SELECT 1 AS ok FROM restore_marker WHERE run = ?', [$run]) !== [];
    }

    /**
     * L-1707: what became of run $run: APPLIED (the last swap), REPLACED (swapped in, then another restore after it,
     * by seq()), NONE (never swapped in), or null when that cannot be known (older than KEEP runs).
     */
    public function outcome(string $run): ?string
    {
        $rows = $this->exec->query('SELECT run, ' . $this->seq() . ' AS seq FROM restore_marker WHERE run <> ?', [self::LOCK]);
        $mine = array_values(array_filter($rows, static fn (array $r): bool => $r['run'] === $run));
        if ($run === self::LOCK || $mine === []) {
            return $run !== self::LOCK && count($rows) >= self::KEEP ? null : self::NONE;
        }
        foreach ($rows as $r) {
            if ($r['run'] !== $run && (int)$r['seq'] > (int)$mine[0]['seq']) {
                return self::REPLACED;
            }
        }

        return self::APPLIED;
    }

    /** Drop every staging copy there may be (by name: nothing is read first), while the lock is held. */
    public function drop(): void
    {
        $this->exec->run([$this->held(), ...self::drops()]);
    }

    /** @return list<array{sql:string,params:list<scalar|null>}> */
    private static function drops(): array
    {
        return array_map(static fn (string $t): array => ['sql' => 'DROP TABLE IF EXISTS ' . self::copy($t), 'params' => []], Backups::tables());
    }

    /** @return array{sql:string,params:list<scalar|null>} the guard that this stage holds the lock */
    private function held(): array
    {
        return ['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL WHERE NOT EXISTS (SELECT 1 FROM restore_marker WHERE run = ? AND sha256 = ?)', 'params' => [self::LOCK, $this->token]];
    }

    /**
     * L-1713: a marker row's order, assigned by the database as the row is inserted (a later row is higher):
     * SQLite's and D1's rowid (restore_marker has no INTEGER PRIMARY KEY, so a new row gets one past the highest,
     * and the newest run is never pruned); PostgreSQL's inserting transaction, as minus its age (wrap-safe).
     */
    private function seq(): string
    {
        return $this->exec->dialect()->name === Dialect::POSTGRES ? '(0 - age(xmin))' : 'rowid';
    }

    /**
     * The time $seconds from now, as restore_marker keeps it (UTC text, so it compares in order): SQL and its
     * binds, on the database's clock (N-1711; D1 has datetime() too) unless a test clock is given.
     *
     * @return array{0:string,1:list<int|string>}
     */
    private function at(int $seconds): array
    {
        if ($this->now !== null) {
            return ['?', [gmdate('Y-m-d H:i:s', ($this->now)() + $seconds)]];
        }

        return $this->exec->dialect()->name === Dialect::POSTGRES
            ? ["to_char((now() AT TIME ZONE 'UTC') + CAST(? AS integer) * interval '1 second', 'YYYY-MM-DD HH24:MI:SS')", [$seconds]]
            : ["datetime('now', ?)", [sprintf('%+d seconds', $seconds)]];
    }

    public static function copy(string $table): string
    {
        return self::PREFIX . self::table($table);
    }

    private static function table(string $table): string
    {
        if (!in_array($table, Backups::tables(), true)) {
            throw new LogicException('Only carried tables are staged.');
        }

        return $table;
    }

    private static function column(string $column): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $column) !== 1) {
            throw new LogicException('A staged column name must be a plain name.');
        }

        return $column;
    }

    /** @param array<array-key,scalar|null> $row */
    private static function json(array $row): string
    {
        try {
            return json_encode($row, self::JSON);
        } catch (JsonException) {
            throw new UserError(Restorer::FAILED);
        }
    }
}
