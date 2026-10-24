<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use CodeIgniter\HTTP\SiteURI;
use Config\App;

/**
 * Puts a per-request public base (legacy public_url() without the path) into
 * CI4's App config and the request's URI, which base_url(), site_url() and
 * redirect()->to() build from. CI4 resets both per request, worker mode
 * included, so nothing carries over to the next request.
 */
final class PublicBase
{
    public function apply(App $app, ?SiteURI $uri, string $base): void
    {
        $base = rtrim($base, '/');
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return;
        }
        $app->baseURL = $base . '/';
        // site_url() and base_url() take the host from the current URI (CI4's
        // allowedHostnames). Keep it the public one, never the request's.
        $uri?->setHost($parts['host']);
    }
}
