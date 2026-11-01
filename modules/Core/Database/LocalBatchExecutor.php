<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use CodeIgniter\Database\BaseConnection;
use LogicException;

/**
 * D1's batch-only contract on a local connection (SQLite or PostgreSQL), for
 * the parity tests and self-hosted parity checks of the batch path
 * (ADR-002, P4-2a): connection() is null, so a unit of work runs as a
 * BatchUnitOfWork, and every statement is checked before any runs. It is a
 * model of D1, not D1: it refuses what would break the batch's atomicity
 * here or what D1 does not run, and lets through what D1 documents. A
 * refused statement is a programming error (LogicException):
 *
 * - one statement: a `;` outside quotes and comments only as its last
 *   character, except between the BEGIN and END of a CREATE TRIGGER body
 *   (INSERT, UPDATE, DELETE, REPLACE or SELECT statements only);
 * - no transaction statement (BEGIN, START TRANSACTION, COMMIT, END, ROLLBACK,
 *   ABORT, SAVEPOINT, RELEASE), and no ATTACH, DETACH, VACUUM, ANALYZE or
 *   REINDEX;
 * - a PRAGMA only from the list D1 documents (PRAGMAS), and a plain value
 *   (no `optimize(-1)`);
 * - no sqlite_*() function call (the sqlite_sequence table is not one);
 * - at most 100 bound parameters (a list is one json_each() parameter).
 *
 * The statement is read with its comments taken out, its literals emptied
 * and its quoted names kept as names, so a comment cannot hide a refused
 * word and a literal holding one is still a value. Both dialects' quoting is
 * read: '' doubling, "" and `` names, [..] names on SQLite, and E'' escapes,
 * dollar quotes and nested block comments on PostgreSQL. A statement it
 * cannot read (an unclosed quote or comment, a backtick on PostgreSQL) is
 * refused, never let through.
 *
 * The batch itself is atomic as on D1: its own transaction, or a SAVEPOINT
 * when a plain transaction is open (D1 never has one), so a failed batch
 * keeps nothing and the outer transaction carries on. No last insert id is
 * exposed: ids come from uow_refs only.
 */
final class LocalBatchExecutor implements SqlExecutor
{
    public const MAX_PARAMS = 100;

    /** The PRAGMAs D1 documents (developers.cloudflare.com/d1/sql-api/sql-statements/). */
    public const PRAGMAS = [
        'table_list', 'table_info', 'table_xinfo', 'index_list', 'index_info', 'index_xinfo', 'quick_check',
        'foreign_key_check', 'foreign_key_list', 'case_sensitive_like', 'ignore_check_constraints',
        'legacy_alter_table', 'recursive_triggers', 'reverse_unordered_selects', 'foreign_keys',
        'defer_foreign_keys', 'optimize',
    ];

    private readonly ConnectionExecutor $inner;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
        $this->inner = new ConnectionExecutor($db);
    }

    public function dialect(): Dialect
    {
        return $this->inner->dialect();
    }

    public function connection(): ?BaseConnection
    {
        return null;
    }

    public function run(array $statements): array
    {
        foreach ($statements as $s) {
            $this->refuse($s['sql'], count($s['params']));
        }
        $run = fn (): array => $this->inner->run($statements);

        return (int)$this->db->transDepth > 0 ? Writes::savepoint($this->db, $run) : $run();
    }

    public function query(string $sql, array $params = []): array
    {
        $this->refuse($sql, count($params));

        return $this->inner->query($sql, $params);
    }

    private function refuse(string $sql, int $params): void
    {
        $code = self::code($sql, $this->dialect()->name === Dialect::POSTGRES);
        $segments = explode(';', $code);
        if (count($segments) > 1 && trim($segments[count($segments) - 1]) === '') {
            array_pop($segments);
        }
        $first = trim($segments[0]);
        if ($first === '' || (count($segments) > 1 && !self::triggerBody($segments))) {
            throw new LogicException('D1 runs one statement per batch step.');
        }
        if (preg_match('/^(?:BEGIN|START|COMMIT|END|ROLLBACK|ABORT|SAVEPOINT|RELEASE|ATTACH|DETACH|VACUUM|ANALYZE|REINDEX)\b/i', $first) === 1) {
            throw new LogicException('D1 refuses transaction statements and database maintenance in a batch.');
        }
        if (preg_match('/^PRAGMA\b/i', $first) === 1 && !self::documentedPragma($first)) {
            throw new LogicException('D1 runs only the PRAGMAs it documents.');
        }
        if (preg_match('/\bsqlite_(?!sequence\b)\w*"?\s*\(/i', $code) === 1) {
            throw new LogicException('D1 refuses sqlite_*() functions.');
        }
        if ($params > self::MAX_PARAMS) {
            throw new LogicException('D1 allows ' . self::MAX_PARAMS . ' bound parameters per statement; bind a list as one (Dialect::inList()).');
        }
    }

    /** `PRAGMA [schema.]name`, `= value` or `(value)`, with a name from PRAGMAS (so no `optimize(-1)`, as on D1). */
    private static function documentedPragma(string $code): bool
    {
        $value = '(?:\w+|\'\'|"\w*")';
        if (preg_match('/^PRAGMA\s+(?:\w+\s*\.\s*)?(\w+)\s*(?:=\s*' . $value . '|\(\s*' . $value . '\s*\))?$/i', $code, $m) !== 1) {
            return false;
        }
        return in_array(strtolower($m[1]), self::PRAGMAS, true);
    }

    /**
     * Whether the statements split at each `;` are one CREATE TRIGGER: its
     * head up to BEGIN with a first body statement, more body statements,
     * and END last. A body statement is INSERT, UPDATE, DELETE, REPLACE or
     * SELECT, so nothing else can follow the END.
     *
     * @param list<string> $segments
     */
    private static function triggerBody(array $segments): bool
    {
        $body = '(?:INSERT|UPDATE|DELETE|REPLACE|SELECT)\b';
        if (preg_match('/^\s*CREATE\s+(?:TEMP(?:ORARY)?\s+)?TRIGGER\b.*?\bBEGIN\s+' . $body . '/is', $segments[0]) !== 1
            || preg_match('/^\s*END\s*$/i', (string)array_pop($segments)) !== 1) {
            return false;
        }
        foreach (array_slice($segments, 1) as $s) {
            if (preg_match('/^\s*' . $body . '/i', $s) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The statement as it is matched: each comment becomes a space, each
     * string literal '' and each quoted name "name" (its characters other
     * than letters, digits and _ become _). PostgreSQL ($pg) adds E''
     * escapes, dollar quotes and nested block comments; SQLite adds [..]
     * and backtick names. An unclosed quote or comment, or a backtick on
     * PostgreSQL, cannot be read and is refused.
     */
    private static function code(string $sql, bool $pg): string
    {
        $out = '';
        $n = strlen($sql);
        $i = 0;
        while ($i < $n) {
            $c = $sql[$i];
            $two = substr($sql, $i, 2);
            if ($two === '--') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $n : $end;
                $out .= ' ';
            } elseif ($two === '/*') {
                $i = self::commentEnd($sql, $i, $pg);
                $out .= ' ';
            } elseif ($c === "'") {
                $i = self::quoteEnd($sql, $i, "'", $pg && preg_match('/(?<![\w$\x80-\xff])[eE]$/', $out) === 1);
                $out .= "''";
            } elseif ($c === '"' || (!$pg && ($c === '`' || $c === '['))) {
                $start = $i;
                $i = $c === '[' ? self::bracketEnd($sql, $i) : self::quoteEnd($sql, $i, $c, false);
                $out .= '"' . preg_replace('/\W/', '_', substr($sql, $start + 1, $i - $start - 2)) . '"';
            } elseif ($c === '`') {
                throw new LogicException('D1 batch statements must be readable: PostgreSQL has no backtick quoting.');
            } elseif ($pg && $c === '$' && preg_match('/[\w$\x80-\xff]$/', $out) !== 1
                && preg_match('/\G\$(?:[A-Za-z_\x80-\xff][\w\x80-\xff]*)?\$/', $sql, $m, 0, $i) === 1) {
                $end = strpos($sql, $m[0], $i + strlen($m[0]));
                if ($end === false) {
                    throw new LogicException('D1 batch statements must be readable: a dollar quote is not closed.');
                }
                $i = $end + strlen($m[0]);
                $out .= "''";
            } else {
                $out .= $c;
                $i++;
            }
        }

        return $out;
    }

    /** The offset just past the block comment at $i (nested on PostgreSQL). */
    private static function commentEnd(string $sql, int $i, bool $pg): int
    {
        $depth = 0;
        $n = strlen($sql);
        while ($i < $n) {
            $two = substr($sql, $i, 2);
            if ($two === '/*' && ($pg || $depth === 0)) {
                $depth++;
                $i += 2;
            } elseif ($two === '*/') {
                $i += 2;
                if (--$depth === 0) {
                    return $i;
                }
            } else {
                $i++;
            }
        }
        throw new LogicException('D1 batch statements must be readable: a block comment is not closed.');
    }

    /** The offset just past the $q-quoted text at $i ($q doubled inside; a backslash escapes when $escapes). */
    private static function quoteEnd(string $sql, int $i, string $q, bool $escapes): int
    {
        $n = strlen($sql);
        for ($i++; $i < $n; $i++) {
            if ($escapes && $sql[$i] === '\\') {
                $i++;
            } elseif ($sql[$i] === $q) {
                if (($sql[$i + 1] ?? '') !== $q) {
                    return $i + 1;
                }
                $i++;
            }
        }
        throw new LogicException('D1 batch statements must be readable: a quote is not closed.');
    }

    /** The offset just past the [..] name at $i (SQLite: it ends at the first `]`). */
    private static function bracketEnd(string $sql, int $i): int
    {
        $end = strpos($sql, ']', $i);
        if ($end === false) {
            throw new LogicException('D1 batch statements must be readable: a [ name is not closed.');
        }

        return $end + 1;
    }
}
