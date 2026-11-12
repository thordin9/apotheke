<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Controllers\ApiController;
use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\LegacyPathRouter;
use Apotheke\Core\Http\SecurityPolicy;
use Apotheke\Core\Http\WantsJson;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\Exceptions\BadRequestException;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\RouteCollectionInterface;
use Config\App;
use Config\Services;

/**
 * A path no route serves is 404 and a route asked with a method it does not
 * take is 405 with an Allow header, before consent, session and permission
 * filters run, so neither turns into a 403 or a redirect to /login. A
 * request that matches a route passes untouched, and its own filters decide
 * as before. The /api catch-all (ApiController::notFound) is not a route
 * here. Under /api both answers are problem+json; elsewhere the 404 is the
 * app's error page (PlainHandler) and the 405 the same page, or legacy JSON
 * for a fetch. A required filter, after headasget (HEAD routes as GET) and api
 * (CORS preflight).
 */
final class RouteMethodFilter implements FilterInterface
{
    /** The verbs a browser or client can send that routes can be declared for. */
    private const VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if (!$request instanceof IncomingRequest) {
            return null;
        }
        $routes = Services::routes()->loadRoutes();
        $method = strtoupper($request->getMethod());
        $path = $request->getPath();
        $verb = $routes->getHTTPVerb();
        try {
            if ($this->matches($routes, $request, $method, $path)) {
                return null;
            }
            $allow = [];
            foreach (self::VERBS as $other) {
                if ($other !== $method && $this->matches($routes, $request, $other, $path)) {
                    $allow[] = $other;
                }
            }
        } finally {
            $routes->setHTTPVerb($verb);
        }
        if ($allow === []) {
            throw PageNotFoundException::forPageNotFound();
        }
        if (in_array('GET', $allow, true)) {
            array_splice($allow, (int)array_search('GET', $allow, true) + 1, 0, ['HEAD']);
        }

        return $this->notAllowed($request, implode(', ', $allow));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }

    private function matches(RouteCollectionInterface $routes, IncomingRequest $request, string $verb, string $path): bool
    {
        $router = Services::router($routes, $request, false);
        $routes->setHTTPVerb($verb);
        try {
            $router->handle($path);
        } catch (RedirectException) {
            return true;
        } catch (PageNotFoundException|BadRequestException) {
            return false;
        }
        if ($router->getMatchedRoute() === null) {
            return false;
        }
        $controller = $router->controllerName();

        return !(is_string($controller) && ltrim($controller, '\\') === ApiController::class && $router->methodName() === 'notFound');
    }

    private function notAllowed(IncomingRequest $request, string $allow): ResponseInterface
    {
        $response = (new Response(config(App::class)))->setHeader('Allow', $allow);
        if (ApiResponse::isApi(LegacyPathRouter::requestPath($request))) {
            return ApiResponse::problem($response, 405, 'method_not_allowed', 'This endpoint does not take this method.');
        }
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }
        $response->setStatusCode(405);
        $server = $request->getServer();
        if ((new WantsJson())->check(is_array($server) ? $server : [])) {
            return $response->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
                ->setBody((string)json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $response->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setBody(Services::errorPage()->html(405, 'This page does not take this kind of request.'));
    }
}
