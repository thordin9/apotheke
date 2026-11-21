<?php

declare(strict_types=1);

namespace Apotheke\Auth;

/**
 * SQL that matches the session rows of one account. A session payload stores the account as uid|i:<id>; and the
 * match accepts that field only when it opens the payload or follows a ';' or '}' field boundary, so a neighbouring
 * id (1 vs 11) or another key ending in uid is not treated as the same account.
 */
final class SessionUidMatch
{
    /** How many times condition() uses $id; pass that many bindings when $id is a placeholder. */
    public const USES = 3;

    /**
     * @param string $column the session data column, such as `data` or `sessions.data`
     * @param string $id an SQL integer expression for the account id, such as `id` or `?`
     */
    public static function condition(string $column, string $id): string
    {
        $text = 'CAST(' . $id . ' AS TEXT)';

        return $column . " LIKE 'uid|i:' || " . $text . " || ';%'"
            . ' OR ' . $column . " LIKE '%;uid|i:' || " . $text . " || ';%'"
            . ' OR ' . $column . " LIKE '%}uid|i:' || " . $text . " || ';%'";
    }

    /** @return list<int> the bindings for condition($column, '?') */
    public static function bindings(int $userId): array
    {
        return array_fill(0, self::USES, $userId);
    }
}
