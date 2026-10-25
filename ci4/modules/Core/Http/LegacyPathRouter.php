<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Request;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Router\RouteCollectionInterface;
use CodeIgniter\Router\Router;

/**
 * Routes the path legacy routes: rawurldecode() of REQUEST_URI's path, once
 * (public/index.php), matched exactly. CI4 would trim slashes and spaces and
 * drop dot segments, so /stats/, /./stats and /a/../stats answered as /stats
 * where legacy answers 404. Such a path matches no route here.
 */
final class LegacyPathRouter extends Router
{
    private ?string $requestUri;

    public function __construct(RouteCollectionInterface $routes, ?Request $request = null)
    {
        parent::__construct($routes, $request);
        $uri = $request?->getServer('REQUEST_URI');
        $this->requestUri = is_string($uri) ? $uri : null;
    }

    /** Legacy's path for a REQUEST_URI. */
    public static function legacyPath(string $requestUri): string
    {
        return rawurldecode((string)(parse_url($requestUri, PHP_URL_PATH) ?: '/'));
    }

    /** Whether the request is legacy's /healthz or /health, answered before sessions and settings (exact path). */
    public static function isHealth(RequestInterface $request): bool
    {
        return in_array(self::requestPath($request), ['/healthz', '/health'], true);
    }

    /** Legacy's path for the request. */
    public static function requestPath(RequestInterface $request): string
    {
        $uri = $request->getServer('REQUEST_URI');

        return self::legacyPath(is_string($uri) ? $uri : '/');
    }

    /**
     * Whether CI4's own trimming could not turn the path into another one. A '+'
     * (no route has one) is a 404 too: CI4 decoded it to a space before.
     */
    public static function exact(string $path): bool
    {
        return $path === '/' || ('/' . trim($path, '/ ') === $path && !str_contains($path, '//') && !str_contains($path, '+')
            && preg_match('#/\.\.?(/|$)#', $path) !== 1);
    }

    public function handle(?string $uri = null)
    {
        if ($this->requestUri === null) {
            return parent::handle($uri);
        }
        $path = self::legacyPath($this->requestUri);
        if (!self::exact($path)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Escaped so the router's own urldecode() gives the path back, not a second decoding.
        return parent::handle($path === '/' ? '/' : str_replace('%', '%25', substr($path, 1)));
    }
}
