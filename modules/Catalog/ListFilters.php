<?php

declare(strict_types=1);

namespace Apotheke\Catalog;

use Apotheke\Core\Libraries\Text;

/**
 * The list's filters and sorts (legacy SORTS, list_filter_defs(),
 * filters_from(), filters_norm(), filters_active(), list_query(),
 * saved_filter_query()). Pure.
 */
final class ListFilters
{
    public const SORTS = ['recent' => 'Recently added', 'name' => 'Name', 'location' => 'Location', 'category' => 'Category', 'qty' => 'Quantity', 'value' => 'Value (highest first)'];

    /**
     * The choice filters besides location, category and tag: URL parameter => definition.
     *
     * @var array<string,array{label:string,all:string,opts:array<int|string,string>,icon:string,class:string,what:string}>
     */
    public const DEFS = [
        'stock' => ['label' => 'Stock', 'all' => 'All items', 'opts' => ['low' => 'Running low'], 'icon' => 'alert', 'class' => 'chip-low', 'what' => 'running-low filter'],
        'exp' => ['label' => 'Expiry / warranty', 'all' => 'All items', 'opts' => ['soon' => 'Expiring soon', 'expired' => 'Expired'], 'icon' => 'clock', 'class' => 'chip-exp', 'what' => 'expiry filter'],
        'fav' => ['label' => 'Favourites', 'all' => 'All items', 'opts' => ['1' => 'Favourites only'], 'icon' => 'star', 'class' => 'chip-fav', 'what' => 'favourites filter'],
        'lend' => ['label' => 'Lending', 'all' => 'All items', 'opts' => ['out' => 'Lent out', 'overdue' => 'Overdue'], 'icon' => 'handoff', 'class' => 'chip-lend', 'what' => 'lending filter'],
    ];

    /**
     * Every list filter from a query array. Unknown values become ''.
     *
     * @param array<string,mixed> $src
     * @return array<string,string>
     */
    public static function from(array $src): array
    {
        $f = ['loc' => self::value($src['loc'] ?? ''), 'cat' => self::value($src['cat'] ?? ''),
            'tag' => is_string($src['tag'] ?? null) && ctype_digit($src['tag']) ? $src['tag'] : ''];
        foreach (self::DEFS as $k => $d) {
            $v = $src[$k] ?? '';
            $f[$k] = is_string($v) && isset($d['opts'][$v]) ? $v : '';
        }

        return $f;
    }

    /**
     * @param array<string,string> $f
     * @return array<string,string>
     */
    public static function norm(array $f): array
    {
        return $f + array_fill_keys(array_merge(['loc', 'cat', 'tag'], array_keys(self::DEFS)), '');
    }

    /** @param array<string,string> $f */
    public static function active(array $f): bool
    {
        foreach (self::norm($f) as $v) {
            if ($v !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,string> $f */
    public static function activeCount(array $f, string $sort, string $defaultSort): int
    {
        return count(array_filter(self::norm($f), static fn (string $v): bool => $v !== '')) + ($sort !== $defaultSort && isset(self::SORTS[$sort]) ? 1 : 0);
    }

    /**
     * Query-string parameters for a list URL (empty values and the default sort dropped).
     *
     * @param array<string,string> $f
     * @return array<string,string>
     */
    public static function query(string $q, array $f, string $sort, string $defaultSort): array
    {
        return array_filter(['q' => $q] + self::norm($f) + ['sort' => $sort === $defaultSort ? '' : $sort], static fn (string $v): bool => $v !== '');
    }

    /**
     * Normalised query string of a list view (legacy saved_filter_query()).
     *
     * @param array<string,mixed> $src
     */
    public static function savedQuery(array $src, string $defaultSort): string
    {
        $q = Text::cleanLine(is_string($src['q'] ?? null) ? $src['q'] : '', 200);

        return http_build_query(self::query($q, self::from($src), self::sort($src['sort'] ?? null, $defaultSort), $defaultSort));
    }

    public static function sort(mixed $sort, string $defaultSort): string
    {
        return is_string($sort) && isset(self::SORTS[$sort]) ? $sort : $defaultSort;
    }

    /** '' = all, 'none' = unassigned, digits = id. */
    public static function value(mixed $v): string
    {
        $v = is_string($v) ? $v : '';

        return ($v === 'none' || ctype_digit($v)) ? $v : '';
    }
}
