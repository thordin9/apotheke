<?php

declare(strict_types=1);

namespace Apotheke\Layout;

/**
 * The theme preference (legacy theme_pref() and set_theme_cookie()): a cookie,
 * so the server renders the right theme before first paint, else the
 * theme_default setting. safeBack() is legacy safe_back().
 */
final class Theme
{
    public const THEMES = ['auto', 'light', 'dark'];

    /** 400 days, as legacy. */
    public const COOKIE_SECONDS = 60 * 60 * 24 * 400;

    public static function pref(mixed $cookie, string $default): string
    {
        return is_string($cookie) && in_array($cookie, self::THEMES, true) ? $cookie : $default;
    }

    /** A value for the cookie: one of THEMES, else auto. */
    public static function valid(mixed $theme): string
    {
        return is_string($theme) && in_array($theme, self::THEMES, true) ? $theme : 'auto';
    }

    /** The Set-Cookie value legacy setcookie() sends (not Secure, not HttpOnly: theme.js reads it). */
    public static function cookie(string $theme, int $now): string
    {
        $expires = $now + self::COOKIE_SECONDS;

        return 'theme=' . rawurlencode($theme) . '; expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT; Max-Age='
            . self::COOKIE_SECONDS . '; path=/; SameSite=Lax';
    }

    /**
     * Only same-site, path-absolute redirect targets ("/…", never "//host" or
     * "/\host"). Control characters (NUL, \x01, DEL …) give "/" too: CI4
     * refuses them in a Location header (N-18).
     */
    public static function safeBack(string $back): string
    {
        return preg_match('#^/(?![/\\\\])[^\s\x00-\x1F\x7F]*$#D', $back) === 1 ? $back : '/';
    }
}
