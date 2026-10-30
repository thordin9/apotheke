<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * The headers of every /api/ answer (API plan §2.5), in place of the page set
 * (SecurityPolicy). HSTS only when the app's public URL is https.
 */
final class ApiSecurityPolicy
{
    /** @return array<string,string> */
    public function headers(bool $https): array
    {
        $headers = [
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ];
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $headers;
    }
}
