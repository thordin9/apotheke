<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use Closure;

/** Same HTTPS check as request_is_https(): direct HTTPS, port 443, or a trusted proxy's first forwarded value. */
final class RequestScheme
{
    public function __construct(private bool $trustProxy)
    {
    }

    /** @param array<string,mixed> $server */
    public function isHttps(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') {
            return true;
        }
        if ((int)($server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (!$this->trustProxy) {
            return false;
        }
        $first = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));

        return $first === 'https';
    }

    /**
     * Mark the session cookie Secure (legacy session_cookie_secure()): over HTTPS,
     * and also when PUBLIC_URL is https:// and the request uses its host, so the
     * app reached through that address keeps Secure behind a proxy that is not
     * trusted. Opening the app directly by address keeps working over plain HTTP.
     *
     * @param array<string,mixed> $server
     */
    public function secureCookie(array $server, string $publicUrl = ''): bool
    {
        if ($this->isHttps($server)) {
            return true;
        }
        if (!str_starts_with(strtolower($publicUrl), 'https://')) {
            return false;
        }
        $want = parse_url($publicUrl, PHP_URL_HOST);
        $got = (new HostAllowList())->split((string)($server['HTTP_HOST'] ?? ''));

        return is_string($want) && $got !== null && strtolower(trim($want, '[]')) === $got[0];
    }

    /**
     * PUBLIC_URL, or this request's scheme and Host. Only a Host that passes the
     * allow-list is used; anything else becomes localhost (legacy public_url(), SEC-M3).
     *
     * @param array<string,mixed> $server
     * @param Closure(string):bool $hostAllowed
     */
    public function publicBase(array $server, Closure $hostAllowed, string $configured = ''): string
    {
        $configured = rtrim($configured, '/');
        if ($configured !== '') {
            return $configured;
        }
        $host = (string)($server['HTTP_HOST'] ?? '');
        if ($host === '' || !$hostAllowed($host)) {
            $host = 'localhost';
        }

        return ($this->isHttps($server) ? 'https' : 'http') . '://' . strtolower($host);
    }
}
