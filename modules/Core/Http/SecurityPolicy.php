<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/** The four response headers legacy sends on every page after boot. */
final class SecurityPolicy
{
    public const CSP = "default-src 'self'; connect-src 'self' blob:; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    /** @return array<string,string> */
    public function headers(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => self::CSP,
        ];
    }
}
