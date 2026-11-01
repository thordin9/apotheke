<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\WriteFailed;
use LogicException;
use Throwable;

/**
 * The unit of work on a batch-only executor (ADR-002, decision 2: D1, a
 * Durable Object's SQLite, or LocalBatchExecutor): nothing runs while the
 * callback reads; flush() sends every step as ONE batch, holds first, with
 * no BEGIN or SAVEPOINT. Guards are batch statements: an insert of NULL into
 * uow_guard.ok when changes() (SQLite) or the write's RETURNING count
 * (PostgreSQL) is not the expected one, or when an asserted condition is not
 * true, so the whole batch fails. A Ref gets a ULID; the row's id goes into
 * uow_refs right after its insert (never the connection's last row id
 * afterwards), later steps read it from there, and the ids are read back in
 * the same batch with one list parameter.
 *
 * When the batch fails, the failed step is found by replaying prefixes of it
 * that always fail at their end (nothing is kept), so the caller's own error
 * ($fail) or the step's WriteFailed is thrown, as on a connection.
 */
final class BatchUnitOfWork extends QueuedUnitOfWork
{
    private const ULID = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** A guard that always fails (code GUARD_FAILED) and a plain statement that always fails (code 0), for the replay. */
    private const FAIL_GUARD = ['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL', 'params' => []];
    private const FAIL_PLAIN = ['sql' => 'INSERT INTO uow_refs (ulid, id) SELECT NULL, NULL', 'params' => []];

    private readonly Dialect $dialect;

    public function __construct(private readonly BatchExecutor&SqlExecutor $executor)
    {
        $this->dialect = $executor->dialect();
    }

    public function hold(string $sql, array $binds, string $step): void
    {
        $this->refuseIfClosed();
        $this->queue[] = new Statement(Statement::KIND_HOLD, $step, $sql, $binds);
    }

    protected function newRef(string $table): Ref
    {
        $ulid = '';
        foreach (str_split(random_bytes(26)) as $byte) {
            $ulid .= self::ULID[ord($byte) & 31];
        }

        return new Ref($table, $ulid);
    }

    /** Send the queued steps as one batch and close the unit: it runs once. */
    public function flush(): void
    {
        $this->closeForFlush();
        $queue = $this->queue;
        $this->queue = [];
        usort($queue, static fn (Statement $a, Statement $b): int => ($b->kind === Statement::KIND_HOLD) <=> ($a->kind === Statement::KIND_HOLD));
        $steps = array_map(fn (Statement $s): array => $this->compile($s), $queue);
        $refs = array_values(array_filter(array_map(static fn (Statement $s): ?Ref => $s->ref, $queue)));
        $ulids = Dialect::list(array_map(static fn (Ref $r): string => $r->ulid, $refs));
        $tail = $refs === [] ? [] : [
            ['sql' => 'SELECT ulid, id FROM uow_refs WHERE ulid IN ' . $this->dialect->inList('text'), 'params' => [$ulids]],
            ['sql' => 'DELETE FROM uow_refs WHERE ulid IN ' . $this->dialect->inList('text'), 'params' => [$ulids]],
        ];
        $batch = array_merge(...$steps, ...[$tail]);
        if ($batch === []) {
            return;
        }
        try {
            $out = $this->executor->run($batch);
        } catch (WriteFailed $e) {
            throw $this->failedStep($queue, $steps, $e);
        }
        $ids = $refs === [] ? [] : array_column($out[count($batch) - 2], 'id', 'ulid');
        foreach ($refs as $ref) {
            $ref->resolve((int)($ids[$ref->ulid] ?? throw new LogicException('A row of ' . $ref->table . ' has no id after the batch.')));
        }
    }

    /**
     * The error of the first step that failed: the shortest prefix of steps
     * that, closed by a statement failing the other way, still fails as the
     * batch did (binary search; a guard failure is GUARD_FAILED, any other
     * failure 0). The original error when the replay does not reproduce it.
     *
     * @param list<Statement> $queue
     * @param list<list<array{sql:string,params:list<scalar|null>}>> $steps
     */
    private function failedStep(array $queue, array $steps, WriteFailed $e): Throwable
    {
        $code = $e->getCode();
        $guards = array_keys(array_filter($queue, static fn (Statement $s): bool => $s->expect !== null || $s->kind === Statement::KIND_ASSERT));
        if ($code === BatchExecutor::GUARD_FAILED && count($guards) === 1) {
            $lo = $guards[0];
        } else {
            [$lo, $hi] = [0, count($steps)];
            $end = $code === BatchExecutor::GUARD_FAILED ? self::FAIL_PLAIN : self::FAIL_GUARD;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                try {
                    $this->executor->run([...array_merge(...array_slice($steps, 0, $mid + 1)), $end]);
                    $same = false;
                } catch (WriteFailed $probe) {
                    $same = $probe->getCode() === $code;
                }
                [$lo, $hi] = $same ? [$lo, $mid] : [$mid + 1, $hi];
            }
            if ($lo === count($steps)) {
                return $e;
            }
        }
        $s = $queue[$lo];

        return ($code === BatchExecutor::GUARD_FAILED ? $s->failure() : null) ?? new WriteFailed('Database write failed: ' . $s->step);
    }

    /**
     * The batch statements of one step.
     *
     * @return list<array{sql:string,params:list<scalar|null>}>
     */
    private function compile(Statement $s): array
    {
        $pg = $this->dialect->name === Dialect::POSTGRES;
        switch ($s->kind) {
            case Statement::KIND_INSERT:
                $cols = array_map(self::ident(...), array_keys($s->row));
                [$values, $params] = $this->values(array_values($s->row));
                $sql = 'INSERT INTO ' . self::ident($s->table) . ($cols === [] ? ' DEFAULT VALUES' : ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $values) . ')');
                $ulid = $s->ref->ulid ?? '';
                if ($pg) {
                    return [['sql' => 'WITH n AS (' . $sql . ' RETURNING id) INSERT INTO uow_refs (ulid, id) SELECT ?, id FROM n', 'params' => [...$params, $ulid]]];
                }

                return [['sql' => $sql, 'params' => $params], ['sql' => 'INSERT INTO uow_refs (ulid, id) VALUES (?, last_insert_rowid())', 'params' => [$ulid]]];
            case Statement::KIND_UPDATE:
                [$set, $params] = $this->values(array_values($s->row));
                $sql = 'UPDATE ' . self::ident($s->table) . ' SET ' . implode(', ', array_map(static fn (string $c, string $v): string => self::ident($c) . ' = ' . $v, array_keys($s->row), $set));
                $where = [];
                foreach ($s->where as $col => $value) {
                    if ($value === null) {
                        $where[] = self::ident($col) . ' IS NULL';
                        continue;
                    }
                    [[$v], $p] = $this->values([$value]);
                    $where[] = self::ident($col) . ' = ' . $v;
                    $params = [...$params, ...$p];
                }

                return [['sql' => $sql . ' WHERE ' . implode(' AND ', $where), 'params' => $params]];
            case Statement::KIND_ASSERT:
                [$sql, $params] = $this->bind($s->sql, $s->binds);

                return [['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL WHERE (CASE WHEN (' . $sql . ') THEN 1 ELSE 0 END) = 0', 'params' => $params]];
            default: // hold, write
                [$sql, $params] = $this->bind($s->sql, $s->binds);
                if ($s->expect === null) {
                    return [['sql' => $sql, 'params' => $params]];
                }
                if ($pg) {
                    return [['sql' => 'WITH w AS (' . rtrim($sql, " ;\n") . ' RETURNING 1) INSERT INTO uow_guard (ok) SELECT NULL WHERE (SELECT COUNT(*) FROM w) <> ?', 'params' => [...$params, $s->expect]]];
                }

                return [['sql' => $sql, 'params' => $params], ['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL WHERE changes() <> ?', 'params' => [$s->expect]]];
        }
    }

    /**
     * Replace each `?` of $sql (outside quotes) whose bind is an unresolved
     * Ref by the read of its id from uow_refs.
     *
     * @param list<scalar|Ref|null> $binds
     * @return array{0:string,1:list<scalar|null>}
     */
    private function bind(string $sql, array $binds): array
    {
        $parts = preg_split('/(\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|\?)/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sql];
        $out = '';
        $i = 0;
        $values = [];
        foreach ($parts as $part) {
            if ($part !== '?') {
                $out .= $part;
                continue;
            }
            if (!array_key_exists($i, $binds)) {
                throw new LogicException('A statement has more placeholders than binds.');
            }
            [[$v], $p] = $this->values([$binds[$i++]]);
            $out .= $v;
            $values = [...$values, ...$p];
        }
        if ($i !== count($binds)) {
            throw new LogicException('A statement has more binds than placeholders.');
        }

        return [$out, $values];
    }

    /**
     * @param list<scalar|Ref|null> $values
     * @return array{0:list<string>,1:list<scalar|null>} the SQL of each value and the params
     */
    private function values(array $values): array
    {
        $sql = [];
        $params = [];
        foreach ($values as $v) {
            if ($v instanceof Ref && !$v->resolved()) {
                $sql[] = '(SELECT id FROM uow_refs WHERE ulid = ?)';
                $params[] = $v->ulid;
            } else {
                $sql[] = '?';
                $params[] = $v instanceof Ref ? $v->id() : $v;
            }
        }

        return [$sql, $params];
    }

    private static function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new LogicException('Not a plain table or column name.');
        }

        return '"' . $name . '"';
    }
}
