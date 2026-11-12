<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use CodeIgniter\HTTP\SiteURI;
use Config\App;

/**
 * Puts a per-request public base (legacy public_url() without the path) into
 * CI4's App config and the request's URI, which base_url(), site_url(),
 * current_url() and redirect()->to() build from. The URI gets the base's
 * scheme, host and port (#82 L-1), so a link to this page never mixes the
 * public host with the request's scheme or port. CI4 resets both per
 * request, worker mode included, so nothing carries over to the next request.
 */
final class PublicBase
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * $path ("/brand/logo.svg?v=…") as an absolute URL on the origin (scheme, host and port) of $base,
     * the base apply() put into Config\App. Anything that is not a root-relative path is returned as is.
     */
    public static function absolute(string $base, string $path): string
    {
        $parts = parse_url($base);
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || !is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $path;
        }
        $scheme = strtolower($parts['scheme']);
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[') ? '[' . $parts['host'] . ']' : $parts['host'];
        $port = isset($parts['port']) && $parts['port'] !== (self::DEFAULT_PORTS[$scheme] ?? null) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port . $path;
    }

    public function apply(App $app, ?SiteURI $uri, string $base): void
    {
        $base = rtrim($base, '/');
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return;
        }
        $scheme = strtolower($parts['scheme']);
        $app->baseURL = $base . '/';
        if ($uri === null) {
            return;
        }
        // site_url() and base_url() take the host from the current URI (CI4's
        // allowedHostnames). Keep it the public one, never the request's. A base
        // without a port gets the scheme's default, which URIs leave out.
        $uri->setScheme($scheme);
        $uri->setHost($parts['host']);
        $port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme] ?? null;
        if ($port !== null) {
            $uri->setPort($port);
        }
    }
}
