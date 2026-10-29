<?php

declare(strict_types=1);

namespace Apotheke\Core\Libraries;

use Apotheke\Core\Exceptions\UserError;

/**
 * Pure text helpers, ported from the legacy fold(), clean_line(), natkey()
 * and tags_parse(). No state: safe in a long-lived worker.
 */
final class Text
{
    private const FOLD = [
        'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
        'ϊ' => 'ι', 'ΐ' => 'ι', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ς' => 'σ',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
    ];

    /** Case- and accent-insensitive key (legacy fold()). */
    public static function fold(string $s): string
    {
        return strtr(mb_strtolower($s), self::FOLD);
    }

    /** Collapse whitespace, trim and cut to $max characters (legacy clean_line()). */
    public static function cleanLine(string $s, int $max): string
    {
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));

        return mb_substr($s, 0, $max);
    }

    /** Natural-sort key: folded text with every number zero-padded ("box 2" < "box 10") (legacy natkey()). */
    public static function natkey(string $s): string
    {
        return mb_substr((string)preg_replace_callback('/\d+/', static fn (array $m): string => str_pad(ltrim($m[0], '0') ?: '0', 12, '0', STR_PAD_LEFT), self::fold(trim($s))), 0, 400);
    }

    /**
     * Split a tag list on commas, semicolons and newlines; drop a leading #,
     * empties and fold-duplicates (legacy tags_parse()).
     *
     * @param string|list<mixed> $in
     * @return list<string>
     */
    public static function tags(string|array $in): array
    {
        $parts = is_array($in) ? $in : preg_split('/[,;\n]+/u', $in);
        $out = [];
        foreach ($parts ?: [] as $p) {
            $p = ltrim(self::cleanLine(is_scalar($p) ? (string)$p : '', 40), '#');
            if ($p === '') {
                continue;
            }
            $out[self::fold($p)] ??= $p;
        }
        if (count($out) > 20) {
            throw new UserError('An item can have at most 20 tags.');
        }

        return array_values($out);
    }
}
