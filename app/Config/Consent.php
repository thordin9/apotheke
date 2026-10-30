<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The cookie-consent contract (docs/security.md "Cookie consent"). The banner
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

    /** The value the app's own notice (public/assets/theme.js) sets: the newest version. */
    public const CURRENT = 'v1';

    public string $cookieName = self::COOKIE;

    /** @var list<string> */
    public array $acceptedValues = self::ACCEPTED;
}
