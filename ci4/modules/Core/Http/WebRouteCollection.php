<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use CodeIgniter\HTTP\Method;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Router\RouteCollectionInterface;

/**
 * CI4's route collection with TRACE and CONNECT, so match() takes every web
 * method. add() answers these too, but also registers a command-line route
 * (N-380); a page legacy shows for every method lists them with match().
 */
final class WebRouteCollection extends RouteCollection
{
    /** Router::HTTP_METHODS without CLI: every method add() routes on the web. */
    public const WEB_METHODS = [Method::GET, Method::HEAD, Method::POST, Method::PATCH, Method::PUT, Method::DELETE, Method::OPTIONS, Method::TRACE, Method::CONNECT];

    /**
     * @param array<array-key, mixed>|\Closure|string $to
     * @param array<string, mixed>|null               $options
     */
    public function trace(string $from, $to, ?array $options = null): RouteCollectionInterface
    {
        $this->create(Method::TRACE, $from, $to, $options);

        return $this;
    }

    /**
     * @param array<array-key, mixed>|\Closure|string $to
     * @param array<string, mixed>|null               $options
     */
    public function connect(string $from, $to, ?array $options = null): RouteCollectionInterface
    {
        $this->create(Method::CONNECT, $from, $to, $options);

        return $this;
    }
}
