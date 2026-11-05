<?php

declare(strict_types=1);

namespace Apotheke\Migrations;

use InvalidArgumentException;
use PDO;

/**
 * D1-safe DDL from a local SQLite schema (N-1760, spike §3c): emit plain
 * tables, CREATE VIRTUAL TABLE for FTS5 (D1 builds the shadow tables),
 * indexes on non-shadow tables, and triggers. Never emit FTS5 shadow
 * tables (suffixes data, idx, content, docsize, config) or sqlite_ / _cf_
 * objects. D1 rejects those CREATE TABLE statements as reserved for internal
 * use.
 */
final class D1SchemaDump
{
    /**
     * @return list<string> CREATE statements in apply order
     */
    public static function fromPdo(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT type, name, tbl_name, sql FROM sqlite_master WHERE sql IS NOT NULL',
        )->fetchAll(PDO::FETCH_ASSOC);

        return self::fromRows($rows);
    }

    /**
     * @param list<array{type?:string,name?:string,tbl_name?:string,sql?:string}> $rows
     * @return list<string>
     */
    public static function fromRows(array $rows): array
    {
        $virtual = [];
        foreach ($rows as $r) {
            $sql = (string)($r['sql'] ?? '');
            if (self::isVirtualSql($sql)) {
                $virtual[(string)($r['name'] ?? '')] = true;
            }
        }

        $plain = [];
        $views = [];
        $virtualSql = [];
        $indexes = [];
        $triggers = [];

        foreach ($rows as $r) {
            $type = (string)($r['type'] ?? '');
            $name = (string)($r['name'] ?? '');
            $tbl = (string)($r['tbl_name'] ?? '');
            $sql = trim((string)($r['sql'] ?? ''));
            if ($sql === '' || self::isInternalName($name) || self::isInternalName($tbl)) {
                continue;
            }
            if (preg_match('/^\s*PRAGMA\b/i', $sql) === 1) {
                continue;
            }
            if ($type === 'table') {
                if (isset($virtual[$name])) {
                    $virtualSql[] = $sql;
                    continue;
                }
                if (self::isShadowOfVirtual($name, $virtual)) {
                    continue;
                }
                if (preg_match('/^\s*CREATE\s+VIEW\b/i', $sql) === 1) {
                    $views[] = $sql;
                } else {
                    $plain[] = $sql;
                }
                continue;
            }
            if ($type === 'index') {
                if (self::isShadowOfVirtual($tbl, $virtual) || isset($virtual[$tbl])) {
                    continue;
                }
                if (str_starts_with($name, 'sqlite_autoindex_')) {
                    continue;
                }
                $indexes[] = $sql;
                continue;
            }
            if ($type === 'trigger') {
                if (self::isShadowOfVirtual($tbl, $virtual)) {
                    continue;
                }
                $triggers[] = $sql;
                continue;
            }
            if ($type === 'view') {
                $views[] = $sql;
            }
        }

        usort($virtualSql, static function (string $a, string $b): int {
            $va = preg_match('/fts5vocab/i', $a) === 1 ? 1 : 0;
            $vb = preg_match('/fts5vocab/i', $b) === 1 ? 1 : 0;

            return $va <=> $vb;
        });

        return [...$plain, ...$views, ...$virtualSql, ...$indexes, ...$triggers];
    }

    /**
     * True when $name is an FTS5/FTS shadow of a virtual table already listed.
     *
     * @param list<string> $virtualNames
     */
    public static function isFtsShadow(string $name, array $virtualNames = []): bool
    {
        if ($virtualNames !== []) {
            $virtual = [];
            foreach ($virtualNames as $v) {
                $virtual[(string)$v] = true;
            }

            return self::isShadowOfVirtual($name, $virtual);
        }
        // No virtual parent listed: only the five literal suffixes, case-sensitive as sqlite_master names them.
        foreach (self::SHADOW_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix) && $name !== ltrim($suffix, '_')) {
                // Require a non-empty base before the suffix (not the bare word "config").
                $base = substr($name, 0, -strlen($suffix));
                if ($base !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /** The five FTS5/FTS shadow-table suffixes SQLite creates for a virtual table. */
    private const SHADOW_SUFFIXES = ['_data', '_idx', '_content', '_docsize', '_config'];

    /**
     * True when $name is a known FTS shadow of a listed virtual table (N-1789:
     * a plain table whose name only shares a prefix, e.g. search_fts_backup, is kept).
     *
     * @param array<string,true> $virtual
     */
    private static function isShadowOfVirtual(string $name, array $virtual): bool
    {
        if (isset($virtual[$name])) {
            return false;
        }
        foreach (array_keys($virtual) as $vt) {
            if ($vt === '') {
                continue;
            }
            foreach (self::SHADOW_SUFFIXES as $suffix) {
                if ($name === $vt . $suffix) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isVirtualSql(string $sql): bool
    {
        return preg_match('/^\s*CREATE\s+VIRTUAL\s+TABLE\b/i', $sql) === 1;
    }

    private static function isInternalName(string $name): bool
    {
        $n = strtolower($name);

        return $n === 'sqlite_sequence'
            || str_starts_with($n, 'sqlite_')
            || str_starts_with($n, '_cf_');
    }

    /** Quote a plain identifier the dump listed itself. */
    public static function quoteIdent(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Refusing a non-plain identifier.');
        }

        return '"' . $name . '"';
    }
}
