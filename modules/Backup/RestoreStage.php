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
 * - stage(): a table's rows, CHUNK_BYTES of JSON per statement, each one bind (json_each on SQLite and D1,
 *   json_populate_recordset on PostgreSQL), so no statement has more than one parameter;
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
 * Each staged chunk renews it; a stopped restore's lock expires LEASE seconds after its last chunk.
 */
final class RestoreStage
{
    /** Staging copies: no carried, CI4-only or SQLite/D1 reserved table starts with it. */
    public const PREFIX = 'apotheke_stage_';

    /** JSON per staged insert, one bind (D1 allows 2 MB per value; a larger row goes alone). */
    public const CHUNK_BYTES = 512 * 1024;

    public const TOO_LARGE = 'This backup does not fit next to the current data within the database size limit. Nothing was changed.';

    /** The run of the lock row in restore_marker (a run id is 32 hex digits, never this). */
    public const LOCK = 'lock';

    /** Seconds the lock is held without a staged chunk. */
    public const LEASE = 300;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    private readonly string $token;

    /** @var Closure():int */
    private readonly Closure $now;

    /** @param ?Closure():int $now the time (a test seam) */
    public function __construct(private readonly SqlExecutor $exec, ?Closure $now = null)
    {
        $this->token = bin2hex(random_bytes(16));
        $this->now = $now ?? static fn (): int => time();
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
            $n += strlen(self::json($pg ? array_combine($columns, $row) : $row)) + 1;
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
        try {
            $this->exec->run([
                ['sql' => 'DELETE FROM restore_marker WHERE run = ? AND (applied_at < ? OR sha256 = ?)', 'params' => [self::LOCK, $this->at(0), $this->token]],
                ['sql' => 'INSERT INTO restore_marker (run, sha256, applied_at) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM restore_marker WHERE run = ?)', 'params' => [self::LOCK, $this->token, $this->at(self::LEASE), self::LOCK]],
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

    /** N-1705: drop a stopped restore's leftover copies, unless a staged restore holds the lock. True when dropped. */
    public function sweep(): bool
    {
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
    public function stage(string $table, array $columns, iterable $rows): int
    {
        $copy = self::copy($table);
        $cols = implode(', ', array_map(self::column(...), $columns));
        $pg = $this->exec->dialect()->name === Dialect::POSTGRES;
        $sql = $pg
            ? "INSERT INTO $copy ($cols) SELECT $cols FROM json_populate_recordset(NULL::$copy, ?::json)"
            : "INSERT INTO $copy ($cols) SELECT " . implode(', ', array_map(static fn (int $i): string => "value ->> $i", array_keys($columns))) . ' FROM json_each(?)';
        $n = 0;
        $chunk = [];
        $bytes = 0;
        foreach ($rows as $row) {
            if (count($row) !== count($columns)) {
                throw new LogicException('A staged row must have one value per column.');
            }
            $json = self::json($pg ? array_combine($columns, $row) : $row);
            if ($chunk !== [] && $bytes + strlen($json) + 1 > self::CHUNK_BYTES) {
                $this->insert($sql, $chunk);
                [$chunk, $bytes] = [[], 0];
            }
            $chunk[] = $json;
            $bytes += strlen($json) + 1;
            $n++;
        }
        if ($chunk !== []) {
            $this->insert($sql, $chunk);
        }

        return $n;
    }

    /** @param list<string> $chunk one chunk of JSON rows, while the lock is held, which it renews */
    private function insert(string $sql, array $chunk): void
    {
        $this->exec->run([$this->held(), ['sql' => $sql, 'params' => ['[' . implode(',', $chunk) . ']']],
            ['sql' => 'UPDATE restore_marker SET applied_at = ? WHERE run = ? AND sha256 = ?', 'params' => [$this->at(self::LEASE), self::LOCK, $this->token]]]);
    }

    /**
     * Swap the copies in, as one batch, and mark run $run applied.
     *
     * @param list<string> $tables every carried table that exists, in Backups::tables() order: all are emptied
     * @param array<string,array{columns:list<string>,rows:int}> $staged per staged table: its columns and row count
     * @param list<string> $withId the tables with an id column (their sequences move past the restored ids)
     */
    public function swap(array $tables, array $staged, array $withId, string $run, string $sha256, string $at): void
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
        $s[] = ['sql' => 'DELETE FROM restore_marker WHERE run <> ?', 'params' => [self::LOCK]];
        $s[] = ['sql' => 'INSERT INTO restore_marker (run, sha256, applied_at) VALUES (?, ?, ?)', 'params' => [$run, $sha256, $at]];
        $this->exec->run($s);
    }

    /** Whether the swap of run $run committed (it is the last one marked). */
    public function applied(string $run): bool
    {
        return $run !== self::LOCK && $this->exec->query('SELECT 1 AS ok FROM restore_marker WHERE run = ?', [$run]) !== [];
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

    /** The time $seconds from now, as restore_marker keeps it (UTC text, so it compares in order). */
    private function at(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', ($this->now)() + $seconds);
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
