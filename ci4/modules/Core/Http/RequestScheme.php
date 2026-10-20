<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

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

    /** @param array<string,mixed> $server */
    public function secureCookie(array $server): bool
    {
        return $this->isHttps($server);
    }

    /** @param array<string,mixed> $server */
    public function publicBase(array $server, string $configured = ''): string
    {
        $configured = rtrim($configured, '/');
        if ($configured !== '') {
            return $configured;
        }
        $host = (string)($server['HTTP_HOST'] ?? 'localhost');
        if (preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host) !== 1) {
            $host = 'localhost';
        }

        return ($this->isHttps($server) ? 'https' : 'http') . '://' . $host;
    }
}
