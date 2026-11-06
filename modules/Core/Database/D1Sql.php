<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

/**
 * A small SQL tokenizer for the D1 migration dialect (L-1802): split a string on real
 * statement boundaries and read a statement's leading keywords with comments and string
 * literals stripped and quoted / schema-qualified identifiers preserved. It is not a full
 * SQL parser — just enough to classify PRAGMA / VACUUM INTO / DROP TABLE statements.
 */
final class D1Sql
{
    /**
     * Split on `;` outside quotes and comments. Trigger bodies (BEGIN…END) are kept whole:
     * a `;` inside a CREATE TRIGGER body does not split. Empty pieces are dropped.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $n = strlen($sql);
        $i = 0;
        $inTrigger = false;
        while ($i < $n) {
            $two = substr($sql, $i, 2);
            $c = $sql[$i];
            if ($two === '--') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $n : $end;
                $buf .= ' ';
                continue;
            }
            if ($two === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $n : $end + 2;
                $buf .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`' || $c === '[') {
                [$token, $i] = self::quoted($sql, $i);
                $buf .= $token;
                continue;
            }
            if (!$inTrigger && preg_match('/\GCREATE\s+(?:TEMP(?:ORARY)?\s+)?TRIGGER\b/i', $sql, $m, 0, $i) === 1) {
                // Only treat as a trigger body when a BEGIN follows later; otherwise it is DDL with no body.
                $inTrigger = preg_match('/\bBEGIN\b/i', $sql, $m2, 0, $i) === 1;
            }
            if ($c === ';') {
                if ($inTrigger && self::triggerBodyOpen($buf)) {
                    $buf .= $c;
                    $i++;
                    continue;
                }
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                $inTrigger = false;
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }

        return $out;
    }

    /**
     * The statement's leading text with comments and string literals removed, for keyword
     * matching. Quoted identifiers are kept (so DROP TABLE "items" still reads its name).
     */
    public static function head(string $sql): string
    {
        $out = '';
        $n = strlen($sql);
        $i = 0;
        while ($i < $n) {
            $two = substr($sql, $i, 2);
            $c = $sql[$i];
            if ($two === '--') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $n : $end;
                $out .= ' ';
                continue;
            }
            if ($two === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $n : $end + 2;
                $out .= ' ';
                continue;
            }
            if ($c === "'") {
                // string literal: drop it entirely
                $i = self::quoteEnd($sql, $i, "'");
                $out .= "''";
                continue;
            }
            if ($c === '"' || $c === '`' || $c === '[') {
                // quoted identifier: keep the inner name so DROP TABLE "items" is seen
                [$token, $i] = self::quoted($sql, $i);
                $out .= $token;
                continue;
            }
            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * The last identifier in a DROP TABLE target: handles `main.items`, `"items"`, `` `items` ``,
     * `[items]`, and a trailing temp qualifier. Returns the bare table name, or null.
     */
    public static function lastIdentifier(string $target): ?string
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }
        // Strip a trailing RESTRICT/CASCADE.
        $target = (string)preg_replace('/\s+(RESTRICT|CASCADE)\s*$/i', '', $target);
        // Split on the last '.' that is outside quotes.
        $parts = [];
        $buf = '';
        $n = strlen($target);
        $i = 0;
        while ($i < $n) {
            $c = $target[$i];
            if ($c === '"' || $c === '`' || $c === '[') {
                [$token, $i] = self::quoted($target, $i);
                $buf .= $token;
                continue;
            }
            if ($c === '.') {
                $parts[] = $buf;
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        $parts[] = $buf;
        $last = trim((string)end($parts));
        if ($last === '') {
            return null;
        }
        // Unquote.
        if (preg_match('/^"(.*)"$/s', $last, $m) === 1 || preg_match('/^`(.*)`$/s', $last, $m) === 1 || preg_match('/^\[(.*)\]$/s', $last, $m) === 1) {
            $last = $m[1];
        }
        // Strip an embedded quote-doubling.
        $last = str_replace(['""', '``'], ['"', '`'], $last);

        return $last === '' ? null : $last;
    }

    /** @return array{0:string,1:int} the quoted token and the next offset */
    private static function quoted(string $sql, int $i): array
    {
        $c = $sql[$i];
        if ($c === '[') {
            $end = strpos($sql, ']', $i + 1);
            $j = $end === false ? strlen($sql) : $end + 1;

            return [substr($sql, $i, $j - $i), $j];
        }
        $j = self::quoteEnd($sql, $i, $c);

        return [substr($sql, $i, $j - $i), $j];
    }

    private static function quoteEnd(string $sql, int $i, string $q): int
    {
        $n = strlen($sql);
        for ($i++; $i < $n; $i++) {
            if ($sql[$i] === $q) {
                if (($sql[$i + 1] ?? '') !== $q) {
                    return $i + 1;
                }
                $i++;
            }
        }

        return $n;
    }

    private static function triggerBodyOpen(string $buf): bool
    {
        // Count the outer BEGIN and inner CASE expressions; an inner END is not the trigger's terminator.
        preg_match_all('/\bBEGIN\b|\bCASE\b|\bEND\b/i', self::head($buf), $m);
        return count($m[0]) % 2 === 1;
    }
}
