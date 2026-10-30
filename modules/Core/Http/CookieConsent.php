<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use Config\Consent;

/**
 * Whether this request carries the user's cookie consent (Config\Consent): the
 * cookie by its exact name with one of the accepted values. Any other value, or
 * none, is no consent, and answers the same. Without consent the app starts no
 * session, issues no CSRF token and sets no cookie (ConsentFilter).
 */
final class CookieConsent
{
    public const STATUS = 403;

    public const CODE = 'consent_required';

    public const MESSAGE = 'Cookies are needed to sign in or save changes. Accept cookies in the notice at the top of the page, then try again.';

    /** Methods that need no session (TRACE is not served, so it is refused like a write). */
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    /** @param list<string> $accepted */
    public function __construct(
        private readonly string $name = Consent::COOKIE,
        private readonly array $accepted = Consent::ACCEPTED,
        private readonly bool $required = true,
    ) {
    }

    /** The configured contract (Config\Consent). */
    public static function configured(): self
    {
        $config = config(Consent::class);

        return $config instanceof Consent ? new self($config->cookieName, $config->acceptedValues, $config->required) : new self();
    }

    /** This request ($_COOKIE) carries consent. */
    public static function request(): bool
    {
        return self::configured()->given($_COOKIE);
    }

    /** @param array<array-key,mixed> $cookies the request's cookies by name */
    public function given(array $cookies): bool
    {
        if (!$this->required) {
            return true;   // COOKIE_CONSENT=off
        }
        $value = $cookies[$this->name] ?? null;

        return is_string($value) && in_array($value, $this->accepted, true);
    }

    /** A request with this method changes state, so it needs a session and consent. */
    public function needed(string $method): bool
    {
        return !in_array(strtoupper($method), self::SAFE, true);
    }

    /** The answer for a fetch or JSON client outside /api (legacy json_out() shape). */
    public function jsonBody(): string
    {
        return (string)json_encode(['ok' => false, 'error' => self::MESSAGE, 'code' => self::CODE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The cookie's Domain for this Host header: the hosted parent domain, or '' (host-only). */
    public static function domain(string $host): string
    {
        $host = rtrim(strtolower((string)preg_replace('/:\d+$/', '', trim($host))), '.');

        return $host === Consent::HOSTED_DOMAIN || str_ends_with($host, '.' . Consent::HOSTED_DOMAIN) ? Consent::HOSTED_DOMAIN : '';
    }

    /** The Set-Cookie value POST /consent sends: the same attributes as the notice's script (app.js). */
    public static function cookie(string $host, bool $secure): string
    {
        $domain = self::domain($host);

        return Consent::COOKIE . '=' . Consent::CURRENT . '; Path=/; Max-Age=' . Consent::MAX_AGE
            . ($domain !== '' ? '; Domain=' . $domain : '') . ($secure ? '; Secure' : '') . '; SameSite=Lax';
    }

    /**
     * Where POST /consent sends the browser back: a path on this site ("/…"), else "/". Refused, also once to
     * three times percent-decoded: no leading "/", "//" anywhere, a backslash, control characters (CR, LF …).
     * Bytes above ASCII are percent-encoded.
     */
    public static function returnPath(mixed $path): string
    {
        if (!is_string($path) || strlen($path) > 2048 || str_contains($path, ' ')) {
            return '/';
        }
        for ($d = $path, $i = 0; $i < 4; $i++, $d = rawurldecode($d)) {
            if (!str_starts_with($d, '/') || str_contains($d, '//') || str_contains($d, '\\') || preg_match('/[\x00-\x1F\x7F]/', $d) === 1) {
                return '/';
            }
        }

        // N-1424: a Location header carries ASCII only, so other bytes go out percent-encoded.
        return (string)preg_replace_callback('/[\x80-\xFF]/', static fn (array $m): string => rawurlencode($m[0]), $path);
    }
}
