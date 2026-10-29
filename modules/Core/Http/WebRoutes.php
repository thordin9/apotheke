<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use Closure;
use CodeIgniter\HTTP\Method;
use CodeIgniter\Router\RouteCollection;

/**
 * Routes for pages legacy shows for every method. CI4's add() answers every
 * web method but also registers a command-line route (N-380), and match()
 * cannot take TRACE or CONNECT (RouteCollection has no trace() or
 * connect()). every() registers each web method through the collection's
 * own create(), on any RouteCollection (also the plain one the route
 * compiler builds), and nothing for CLI.
 */
final class WebRoutes
{
    /** Router::HTTP_METHODS without CLI: every method add() routes on the web. */
    public const METHODS = [Method::GET, Method::HEAD, Method::POST, Method::PATCH, Method::PUT, Method::DELETE, Method::OPTIONS, Method::TRACE, Method::CONNECT];

    /**
     * @param array<array-key, mixed>|Closure|string $to
     * @param array<string, mixed>|null              $options
     */
    public static function every(RouteCollection $routes, string $from, array|Closure|string $to, ?array $options = null): void
    {
        // create() is protected; this closure runs in RouteCollection's scope.
        $create = Closure::bind(static function (RouteCollection $collection, string $verb) use ($from, $to, $options): void {
            $collection->create($verb, $from, $to, $options);
        }, null, RouteCollection::class);
        foreach (self::METHODS as $verb) {
            $create($routes, $verb);
        }
    }
}
