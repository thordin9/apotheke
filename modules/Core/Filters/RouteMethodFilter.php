<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Controllers\ApiController;
use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\ApiSecurityPolicy;
use Apotheke\Core\Http\LegacyPathRouter;
use Apotheke\Core\Http\SecurityPolicy;
use Apotheke\Core\Http\WantsJson;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\RouteCollectionInterface;
use CodeIgniter\Router\Router;
use Config\App;
use Config\Services;

/**
 * A path no route serves is 404 and a route asked with a method it does not
 * take is 405 with an Allow header, before consent, session and permission
 * filters run, so neither turns into a 403 or a redirect to /login. A
 * request that matches a route passes untouched, and its own filters decide
 * as before. The /api catch-all (ApiController::notFound) is not a route
 * here. Under /api both answers are problem+json, and so is a path with a
 * doubled slash that is /api once the slashes are collapsed (//api/v1/x,
 * /api//v1); elsewhere the 404 is the app's error page (PlainHandler) and the
 * 405 the same page, or legacy JSON for a fetch. A path the router refuses is
 * left to the exception handler (400). A required filter, after headasget (HEAD routes as GET) and api
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
        // The target as sent: a URL parser reads "//api/v1/x" as a host and a path.
        $target = $request->getServer('REQUEST_URI');
        $target = is_string($target) ? explode('?', $target, 2)[0] : '';
        $raw = str_starts_with($target, '/') ? $target : LegacyPathRouter::requestPath($request);
        // A doubled slash (//api/v1/x, /api//v1) is no route: under /api it is the JSON 404 too.
        $api = ApiResponse::isApi((string)preg_replace('#/{2,}#', '/', $raw));
        if ($api && str_contains($raw, '//')) {
            return $this->notFound(Services::settings()->get('public_url'));
        }
        $routes = Services::routes()->loadRoutes();
        // One router for every try: only a match sets its matched route, and a match ends the search.
        $router = Services::router($routes, $request, false);
        $method = strtoupper($request->getMethod());
        $path = $request->getPath();
        $verb = $routes->getHTTPVerb();
        try {
            if ($this->matches($router, $routes, $method, $path)) {
                return null;
            }
            $allow = [];
            foreach (self::VERBS as $other) {
                if ($other !== $method && $this->matches($router, $routes, $other, $path)) {
                    $allow[] = $other;
                }
            }
        } finally {
            $routes->setHTTPVerb($verb);
        }
        if ($allow === []) {
            if ($api) {
                return $this->notFound(Services::settings()->get('public_url'));
            }

            throw PageNotFoundException::forPageNotFound();   // the app's error page (PlainHandler)
        }
        if (in_array('GET', $allow, true)) {
            array_splice($allow, (int)array_search('GET', $allow, true) + 1, 0, ['HEAD']);
        }
        $response = (new Response(config(App::class)))->setHeader('Allow', implode(', ', $allow));
        if ($api) {
            return ApiResponse::problem($response, 405, 'method_not_allowed', 'This endpoint does not take this method.');
        }
        $server = $request->getServer();
        $json = (new WantsJson())->check(is_array($server) ? $server : []);

        return $this->notAllowed($response, $json ? null : Services::errorPage()->html(405, 'This page does not take this kind of request.'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }

    /**
     * Whether a route other than the /api catch-all takes $verb on $path. A path the router refuses
     * (BadRequestException) is not answered here: the exception handler makes it a 400.
     *
     */
    private function matches(Router $router, RouteCollectionInterface $routes, string $verb, string $path): bool
    {
        $routes->setHTTPVerb($verb);
        try {
            $router->handle($path);
        } catch (RedirectException) {
            return true;
        } catch (PageNotFoundException) {
            return false;
        }
        if ($router->getMatchedRoute() === null) {
            return false;
        }
        $controller = $router->controllerName();

        return !(is_string($controller) && ltrim($controller, '\\') === ApiController::class && $router->methodName() === 'notFound');
    }

    /** The /api 404, as ApiController::notFound(), with the API headers (a doubled slash is not /api to ApiFilter). */
    private function notFound(mixed $publicUrl): ResponseInterface
    {
        $response = new Response(config(App::class));
        foreach ((new ApiSecurityPolicy())->headers(str_starts_with(strtolower((string)$publicUrl), 'https://')) as $name => $value) {
            $response->setHeader($name, $value);
        }

        return ApiResponse::problem($response, 404, 'not_found', 'No such API endpoint.');
    }

    /** @param string|null $page the error page; null: legacy JSON for a fetch */
    private function notAllowed(ResponseInterface $response, ?string $page): ResponseInterface
    {
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }
        $response->setStatusCode(405);
        if ($page === null) {
            return $response->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
                ->setBody((string)json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $response->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody($page);
    }
}
