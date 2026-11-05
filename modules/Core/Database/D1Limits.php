<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use LogicException;

/**
 * D1's statement limits and the shared refusal rules for a batch step
 * (ADR-002, P4-3; spike §3c). LocalBatchExecutor and D1HttpExecutor both
 * call refuse() before any statement runs. A refused statement is a
 * programming error (LogicException), never a WriteFailed.
 *
 * - one statement: a `;` outside quotes and comments only as its last
 *   character, except, on SQLite only, between the BEGIN and END of a
 *   CREATE TRIGGER body;
 * - it starts with SELECT, INSERT, UPDATE, DELETE, REPLACE, WITH, VALUES,
 *   CREATE, DROP, ALTER or PRAGMA (an allow-list);
 * - a PRAGMA only from the list D1 documents, with a plain value;
 * - no sqlite_*() or set_config() call;
 * - at most MAX_PARAMS bound parameters;
 * - at most MAX_STATEMENT_BYTES of SQL text (N-1640).
 */
final class D1Limits
{
    public const MAX_PARAMS = 100;

    /** D1: SQLITE_TOOBIG above 100,000 bytes per statement. */
    public const MAX_STATEMENT_BYTES = 100_000;

    /** The PRAGMAs D1 documents (developers.cloudflare.com/d1/sql-api/sql-statements/). */
    public const PRAGMAS = [
        'table_list', 'table_info', 'table_xinfo', 'index_list', 'index_info', 'index_xinfo', 'quick_check',
        'foreign_key_check', 'foreign_key_list', 'case_sensitive_like', 'ignore_check_constraints',
        'legacy_alter_table', 'recursive_triggers', 'reverse_unordered_selects', 'foreign_keys',
        'defer_foreign_keys', 'optimize',
    ];

    private const LEADING = '/^(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|WITH|VALUES|CREATE|DROP|ALTER|PRAGMA)\b/i';

    private const LEADING_NAMES = 'SELECT, INSERT, UPDATE, DELETE, REPLACE, WITH, VALUES, CREATE, DROP, ALTER or a documented PRAGMA';

    /**
     * Whether $error is a unit-of-work guard failure (uow_guard.ok NOT NULL).
     * Matches plain SQLite, PostgreSQL, and the D1 / workerd wrappers that
     * prefix the same message (N-1640).
     */
    public static function isGuardFailure(string $error): bool
    {
        $first = rtrim((string)strtok($error, "\n"));
        // Strip a leading "D1_ERROR: " / "Error: " / driver tag so the SQLite text decides.
        $first = (string)preg_replace('/^(?:(?:D1_ERROR|Error|SQLite|SQLSTATE\[\w+\])\s*:\s*)+/i', '', $first);

        return preg_match('/^(?:(?:[A-Za-z ]+: )?NOT NULL constraint failed: uow_guard\.ok'
            . '|(?:ERROR:\s+)?null value in column "ok" of relation "uow_guard" violates not-null constraint)$/', $first) === 1;
    }

    /** @throws LogicException */
    public static function refuse(string $sql, int $params, bool $postgres = false): void
    {
        if (strlen($sql) > self::MAX_STATEMENT_BYTES) {
            throw new LogicException('D1 allows ' . self::MAX_STATEMENT_BYTES . ' bytes per statement.');
        }
        $code = self::code($sql, $postgres);
        $segments = explode(';', $code);
        if (count($segments) > 1 && preg_match('/^\s*$/D', $segments[count($segments) - 1]) === 1) {
            array_pop($segments);
        }
        $first = (string)preg_replace('/^\s+|\s+$/D', '', $segments[0]);
        if ($first === '' || (count($segments) > 1 && ($postgres || !self::triggerBody($segments)))) {
            throw new LogicException('D1 runs one statement per batch step.');
        }
        if (preg_match(self::LEADING, $first) !== 1) {
            throw new LogicException('D1 batch steps start with ' . self::LEADING_NAMES . '.');
        }
        if (preg_match('/^PRAGMA\b/i', $first) === 1 && !self::documentedPragma($first)) {
            throw new LogicException('D1 runs only the PRAGMAs it documents.');
        }
        if (preg_match('/\b(?:sqlite_(?!sequence\b)\w*|set_config)"?\s*\(/i', $code) === 1) {
            throw new LogicException('D1 refuses sqlite_*() and set_config() calls.');
        }
        if ($params > self::MAX_PARAMS) {
            throw new LogicException('D1 allows ' . self::MAX_PARAMS . ' bound parameters per statement; bind a list as one (Dialect::inList()).');
        }
    }

    private static function documentedPragma(string $code): bool
    {
        $value = '(?:\w+|\'\'|"\w*")';
        if (preg_match('/^PRAGMA\s+(?:\w+\s*\.\s*)?(\w+)\s*(?:(=\s*' . $value . '|\(\s*' . $value . '\s*\)))?$/iD', $code, $m) !== 1) {
            return false;
        }
        $name = strtolower($m[1]);

        return in_array($name, self::PRAGMAS, true) && !($name === 'optimize' && ($m[2] ?? '') !== '');
    }

    /** @param list<string> $segments */
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

    private static function bracketEnd(string $sql, int $i): int
    {
        $end = strpos($sql, ']', $i);
        if ($end === false) {
            throw new LogicException('D1 batch statements must be readable: a [ name is not closed.');
        }

        return $end + 1;
    }
}
