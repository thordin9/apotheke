<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * CORS for /api (API plan §2.2): only the exact origins in
 * CORS_ALLOWED_ORIGINS, with credentials. No wildcard, no `null`, no path,
 * and http only for a loopback origin in development. An empty list (the
 * self-hosted default) sends no CORS headers at all.
 */
final class Cors
{
    /** @var list<string> */
    private array $origins = [];

    /** @param list<string> $configured CORS_ALLOWED_ORIGINS */
    public function __construct(array $configured, bool $development)
    {
        foreach ($configured as $origin) {
            $origin = self::origin($origin);
            if ($origin === null) {
                continue;
            }
            $host = (string)parse_url($origin, PHP_URL_HOST);
            if (str_starts_with($origin, 'http://') && !($development && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true))) {
                continue;
            }
            $this->origins[] = $origin;
        }
        $this->origins = array_values(array_unique($this->origins));
    }

    /** @return list<string> the usable origins */
    public function origins(): array
    {
        return $this->origins;
    }

    public function allows(string $origin): bool
    {
        $origin = self::origin($origin);

        return $origin !== null && in_array($origin, $this->origins, true);
    }

    /** @return array<string,string> the headers for a request from $origin; none when it isn't allowed */
    public function headers(string $origin, bool $preflight): array
    {
        if (!$this->allows($origin)) {
            return [];
        }
        $headers = [
            'Access-Control-Allow-Origin' => (string)self::origin($origin),
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Expose-Headers' => 'ETag, Location, Retry-After, Link',
        ];
        if ($preflight) {
            $headers += [
                'Access-Control-Allow-Methods' => 'GET, POST, PATCH, PUT, DELETE',
                'Access-Control-Allow-Headers' => 'Content-Type, X-CSRF-Token, If-Match, Idempotency-Key',
                'Access-Control-Max-Age' => '600',
            ];
        }

        return $headers;
    }

    /** scheme://host[:port] in lower case, or null for anything else (a path, a wildcard, `null`). */
    private static function origin(string $origin): ?string
    {
        $origin = strtolower(rtrim(trim($origin), '/'));
        if (preg_match('#^https?://(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(:[0-9]{1,5})?$#', $origin) !== 1) {
            return null;
        }

        return $origin;
    }
}
