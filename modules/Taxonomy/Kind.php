<?php

declare(strict_types=1);

namespace Apotheke\Taxonomy;

use InvalidArgumentException;

/**
 * Fixed per-kind config (legacy tax()). Table and column names come only from
 * here, so interpolating them into SQL is safe.
 */
final class Kind
{
    public const KINDS = ['location', 'category'];

    private const CONFIG = [
        'location' => ['kind' => 'location', 'table' => 'locations', 'col' => 'location_id', 'param' => 'loc', 'alias' => 'l',
            'one' => 'location', 'many' => 'locations', 'One' => 'Location', 'Many' => 'Locations',
            'none' => 'No location', 'path' => '/locations', 'icon' => 'pin', 'example' => 'box 1024'],
        'category' => ['kind' => 'category', 'table' => 'categories', 'col' => 'category_id', 'param' => 'cat', 'alias' => 'c',
            'one' => 'category', 'many' => 'categories', 'One' => 'Category', 'Many' => 'Categories',
            'none' => 'No category', 'path' => '/categories', 'icon' => 'tag', 'example' => 'Tools'],
    ];

    /** @return array{kind:string,table:string,col:string,param:string,alias:string,one:string,many:string,One:string,Many:string,none:string,path:string,icon:string,example:string} */
    public static function get(string $kind): array
    {
        return self::CONFIG[$kind] ?? throw new InvalidArgumentException('Unknown kind ' . $kind);
    }

    /** "locations" → location, "categories" → category, else null. */
    public static function fromSegment(string $segment): ?string
    {
        return match ($segment) {
            'locations' => 'location',
            'categories' => 'category',
            default => null,
        };
    }
}
