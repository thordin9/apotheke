<?php

declare(strict_types=1);

namespace Apotheke\Core\Libraries;

use Apotheke\Core\Config\ApothekeConfig;

/** The VERSION file at the repository root. Same pattern as app_version(). */
final class Version
{
    public static function current(): string
    {
        $text = @file_get_contents(ApothekeConfig::repoRoot() . '/VERSION');
        if ($text !== false && preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', trim($text)) === 1) {
            return trim($text);
        }

        return '0.0.0';
    }
}
