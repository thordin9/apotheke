<?php

declare(strict_types=1);

namespace Apotheke\Auth\Filters;

use Apotheke\Auth\PermissionCheck;
use Apotheke\Auth\Permissions;
use Apotheke\Auth\RoutePermission;
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
use CodeIgniter\Router\Router;
use Config\App;
use Config\Services;

/**
 * Legacy's per-route permission check (route_perm() and user_can() in
 * public/index.php). A required before filter after `session` and
 * `sameorigin`, so it runs for every request, routed or not, before the CSRF
 * check, in legacy's order.
 *
 * The permission is derived from legacy's path (LegacyPathRouter: REQUEST_URI's
 * path, decoded once, not trimmed), the one the router matches. A matched
 * route gives its recorded `permission` option (`/save` adds legacy's
 * post-dependent rule: edit with an id, else create), and a matched route
 * without one is refused to everyone (fail closed). A request no route
 * matches, a redirect route, and a route marked `none` (health, branding)
 * get legacy route_perm() for that path, so `none` skips the check only
 * where legacy answers before its check. The method is the effective one
 * (after HEAD → GET and the `_method` override).
 *
 * Refusals match legacy: nobody or the anonymous guest gets 401 JSON for a
 * fetch, a bare 401 under /media/, otherwise 303 to /login; a signed-in role
 * without the permission gets 403 with legacy's message (JSON, or the app's
 * error page). The user is always the web request's (CurrentUser), never the CLI
 * actor. A returned response skips global after filters, so the security
 * headers are set here.
 */
final class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $method = $method === 'HEAD' ? 'GET' : $method;
        $requestPath = $request instanceof IncomingRequest ? $request->getPath() : ltrim($request->getUri()->getPath(), '/');
        $rules = new RoutePermission();
        // Legacy's own path (the router's too), so an absolute-form target or a trimmed variant is judged as legacy does.
        $uri = $request->getServer('REQUEST_URI');
        $path = is_string($uri) ? LegacyPathRouter::requestPath($request) : $rules->exact($requestPath);
        $need = $request instanceof IncomingRequest
            ? $this->routeOption(Services::router(Services::routes()->loadRoutes(), $request, false), $requestPath)
            : null;
        $post = $request instanceof IncomingRequest ? $request->getPost() : [];
        $post = is_array($post) ? $post : [];
        if ($need === null || $need === Permissions::NONE) {
            // No route, or a route legacy answers before its check: legacy route_perm() on the exact path.
            if ($rules->isBeforeCheck($method, $path)) {
                return null;
            }
            $need = $rules->need($method, $path, $post);
        } elseif ($path === '/save') {
            $need = $rules->need($method, $path, $post);   // edit with an id, else create
        }
        $refusal = (new PermissionCheck(Services::roles()))->refusal(Services::currentUser()->resolve(), $need);
        if ($refusal === null) {
            return null;
        }
        $server = $request->getServer();
        $json = (new WantsJson())->check(is_array($server) ? $server : []);
        $response = new Response(config(App::class));
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }
        if ($refusal === PermissionCheck::SIGN_IN) {
            if ($json) {
                return $this->json($response, 401, ['ok' => false, 'error' => 'Not signed in', 'redirect' => '/login']);
            }
            if (str_starts_with($path, '/media/')) {
                return $response->setStatusCode(401);
            }

            return $response->setStatusCode(303)->setHeader('Location', '/login');
        }
        if ($json) {
            return $this->json($response, 403, ['ok' => false, 'error' => $refusal]);
        }

        // Legacy view_error(): the app's error page (Layout).
        return $response->setStatusCode(403)->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setBody(Services::errorPage()->html(403, $refusal));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }

    /**
     * The `permission` option of the route CI4's router matches for this
     * request: UNMAPPED when the route has none, null when no route matches.
     * $router is a fresh one for this request, not the one that dispatches.
     */
    private function routeOption(Router $router, string $requestPath): ?string
    {
        try {
            $router->handle($requestPath);
        } catch (PageNotFoundException|BadRequestException|RedirectException) {
            return null;   // no controller route: legacy route_perm() on the routed path
        }
        if ($router->getMatchedRoute() === null) {
            return null;
        }
        $option = ($router->getMatchedRouteOptions() ?? [])['permission'] ?? null;

        return is_string($option) && $option !== '' ? $option : Permissions::UNMAPPED;
    }

    /** @param array<string,mixed> $data legacy json_out() */
    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        return $response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
