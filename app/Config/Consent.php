<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The cookie-consent contract (docs/deployment-and-operations.md "Cookie consent"). The banner
 * script sets `apotheke_consent=v1` when the user clicks Accept; it is the first
 * cookie the app ever has. Until a request carries it with an accepted value the
 * server sets no cookie at all and refuses writes with 403 consent_required.
 * Only the name and the exact value are compared (attributes never reach the
 * server), so a host-only cookie and a Domain=getapotheke.com one both count.
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

    /** On a host under this domain (hosted) the cookie gets Domain=getapotheke.com; elsewhere it is host-only. */
    public const HOSTED_DOMAIN = 'getapotheke.com';

    public string $cookieName = self::COOKIE;

    /** @var list<string> */
    public array $acceptedValues = self::ACCEPTED;

    /** COOKIE_CONSENT: `required` (the default; any other value too) or `off` (cookies as before 3.3, no 403). */
    public bool $required = true;

    public function __construct()
    {
        parent::__construct();
        $this->required = self::requiredFrom(getenv('COOKIE_CONSENT'));
    }

    public static function requiredFrom(mixed $value): bool
    {
        return !is_string($value) || strtolower(trim($value)) !== 'off';
    }
}
