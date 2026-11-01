<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\SqlExecutor;
use Apotheke\Core\Exceptions\UserError;
use CodeIgniter\Database\BaseConnection;
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
 */
final class RestoreStage
{
    /** Staging copies: no carried, CI4-only or SQLite/D1 reserved table starts with it. */
    public const PREFIX = 'apotheke_stage_';

    /** JSON per staged insert, one bind (D1 allows 2 MB per value; a larger row goes alone). */
    public const CHUNK_BYTES = 512 * 1024;

    public const TOO_LARGE = 'This backup does not fit next to the current data within the database size limit. Nothing was changed.';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(private readonly SqlExecutor $exec)
    {
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

    /**
     * The database's size on a connection (SQLite file pages, PostgreSQL's database size). A batch-only backend
     * reports its own (P4-3).
     *
     * @param BaseConnection<object|resource, object|resource> $db
     */
    public static function liveBytes(BaseConnection $db): int
    {
        $sql = $db->DBDriver === 'Postgre'
            ? 'SELECT pg_database_size(current_database()) AS n'
            : 'SELECT page_count * page_size AS n FROM pragma_page_count(), pragma_page_size()';
        $result = $db->query($sql);

        return $result === false ? 0 : (int)($result->getRowArray()['n'] ?? 0);
    }

    /** @param list<string> $tables the carried tables to restore, a subset of Backups::tables() */
    public function begin(array $tables): void
    {
        $this->drop();
        $this->exec->run(array_map(fn (string $t): array => ['sql' => 'CREATE TABLE ' . self::copy($t) . ' AS SELECT * FROM ' . self::table($t) . ' WHERE 1 = 0', 'params' => []], $tables));
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
                $this->exec->run([['sql' => $sql, 'params' => ['[' . implode(',', $chunk) . ']']]]);
                [$chunk, $bytes] = [[], 0];
            }
            $chunk[] = $json;
            $bytes += strlen($json) + 1;
            $n++;
        }
        if ($chunk !== []) {
            $this->exec->run([['sql' => $sql, 'params' => ['[' . implode(',', $chunk) . ']']]]);
        }

        return $n;
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
        $s = $pg ? [] : [['sql' => 'PRAGMA defer_foreign_keys = ON', 'params' => []]];
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
        $s[] = ['sql' => 'DELETE FROM restore_marker', 'params' => []];
        $s[] = ['sql' => 'INSERT INTO restore_marker (run, sha256, applied_at) VALUES (?, ?, ?)', 'params' => [$run, $sha256, $at]];
        $this->exec->run($s);
    }

    /** Whether the swap of run $run committed (it is the last one marked). */
    public function applied(string $run): bool
    {
        return $this->exec->query('SELECT 1 AS ok FROM restore_marker WHERE run = ?', [$run]) !== [];
    }

    /** Drop every staging copy there may be (by name: nothing is read first). */
    public function drop(): void
    {
        $this->exec->run(array_map(static fn (string $t): array => ['sql' => 'DROP TABLE IF EXISTS ' . self::copy($t), 'params' => []], Backups::tables()));
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
