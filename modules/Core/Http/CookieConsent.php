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

    /** Methods that need no session (RFC 9110 safe methods). */
    private const SAFE = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /** @param list<string> $accepted */
    public function __construct(private readonly string $name = Consent::COOKIE, private readonly array $accepted = Consent::ACCEPTED)
    {
    }

    /** The configured contract (Config\Consent). */
    public static function configured(): self
    {
        $config = config(Consent::class);

        return $config instanceof Consent ? new self($config->cookieName, $config->acceptedValues) : new self();
    }

    /** This request ($_COOKIE) carries consent. */
    public static function request(): bool
    {
        return self::configured()->given($_COOKIE);
    }

    /** @param array<array-key,mixed> $cookies the request's cookies by name */
    public function given(array $cookies): bool
    {
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
}
