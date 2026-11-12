<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * A page or form write takes one value per field. Only the fields the app's forms send as lists
 * (ids[], perms[], col[...], photos[], files[]) may be lists, and their controllers read the
 * values they take; any other field sent as a list (name[]=, id[]=) is refused instead of read
 * as "Array" or 1.
 */
final class FormFields
{
    public const STATUS = 422;

    public const LISTS = ['ids', 'perms', 'col', 'photos', 'files'];

    /**
     * The first field that breaks the rule, or null when every field is fine.
     *
     * @param array<array-key,mixed> $post
     */
    public function invalid(array $post): ?string
    {
        foreach ($post as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (!in_array((string)$key, self::LISTS, true)) {
                return (string)$key;
            }
        }

        return null;
    }

    public function message(string $field): string
    {
        return 'Each field takes one value (' . (preg_match('/^[A-Za-z0-9_]{1,40}$/', $field) === 1 ? $field : 'field') . ').';
    }
}
