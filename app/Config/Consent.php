<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The cookie-consent contract (docs/deployment-and-operations.md "Cookie consent"). The banner
 * script sets `apotheke_consent=v1` when the user clicks Accept; it is the first
 * cookie the app ever has. Until a request carries it with an accepted value the
 * server sets no cookie at all and refuses writes with 403 consent_required.
 * Only the name and the exact value are compared (attributes never reach the
 * server), so a host-only cookie and one with a Domain (COOKIE_DOMAIN) both count.
 */
class Consent extends BaseConfig
{
    public const COOKIE = 'apotheke_consent';

    /** Accepted versions of the consent; a later banner text adds 'v2' here. */
    public const ACCEPTED = ['v1'];

    /** The value the app's own notice (Accept in public/assets/app.js, or POST /consent) sets: the newest version. */
    public const CURRENT = 'v1';

    /** 180 days. */
    public const MAX_AGE = 15552000;

    public string $cookieName = self::COOKIE;

    /** @var list<string> */
    public array $acceptedValues = self::ACCEPTED;

    /** COOKIE_CONSENT: `required` (the default; any other value too) or `off` (cookies as before 3.4, no 403). */
    public bool $required = true;

    /**
     * COOKIE_DOMAIN, normalised (domainFrom()): the parent domain the consent cookie (and any later cookie that is
     * not __Host-) is set for, when the request's host is that domain or under it. '' (the default): host-only.
     * Never applied to the __Host- session cookie.
     */
    public string $cookieDomain = '';

    public function __construct()
    {
        parent::__construct();
        $this->required = self::requiredFrom(getenv('COOKIE_CONSENT'));
        $this->cookieDomain = self::domainFrom(getenv('COOKIE_DOMAIN'));
    }

    /**
     * A usable COOKIE_DOMAIN: lower case, without a leading dot; '' (host-only) for anything else: empty, an IP
     * address, a single label or bare TLD, or a value with a port, a path, a wildcard or other characters.
     */
    public static function domainFrom(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $domain = strtolower(trim($value));
        $domain = str_starts_with($domain, '.') ? substr($domain, 1) : $domain;
        if ($domain === '' || filter_var(trim($domain, '[]'), FILTER_VALIDATE_IP) !== false) {
            return '';
        }
        $labels = explode('.', $domain);
        if (count($labels) < 2 || ctype_digit(end($labels))) {
            return '';
        }
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                return '';
            }
        }

        return $domain;
    }

    public static function requiredFrom(mixed $value): bool
    {
        return !is_string($value) || strtolower(trim($value)) !== 'off';
    }
}
