<?php

declare(strict_types=1);

namespace Apotheke\Auth\Filters;

use Apotheke\Auth\PermissionCheck;
use Apotheke\Auth\Permissions;
use Apotheke\Auth\RoutePermission;
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
 * The permission is derived from the routed path. CI4's own router matches
 * the request first (a fresh Router on the shared route collection, so the
 * dispatch later is untouched): a matched route gives its recorded
 * `permission` option, and a matched route without one is refused to
 * everyone (fail closed). A request no route matches gets legacy
 * route_perm() for the path the router tried, normalised as the router does
 * (decoded, slashes and spaces trimmed, relative to the base URL), and then
 * CI4's 404 once allowed; a redirect route is treated the same way, so its
 * redirect is only sent once that permission is met. A route marked `none`
 * (health, branding) skips the check only for the exact path legacy answers
 * before its check; a variant the router trims into it, such as a trailing
 * slash, needs what legacy route_perm() gives that path. The method is the
 * effective one (after HEAD → GET and the `_method` override).
 *
 * Refusals match legacy: nobody or the anonymous guest gets 401 JSON for a
 * fetch, a bare 401 under /media/, otherwise 303 to /login; a signed-in role
 * without the permission gets 403 with legacy's message (JSON, or an HTML
 * page). The user is always the web request's (CurrentUser), never the CLI
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
        $path = $rules->routed($requestPath);
        $need = $request instanceof IncomingRequest
            ? $this->routeOption(Services::router(Services::routes()->loadRoutes(), $request, false), $requestPath)
            : null;
        $post = $request instanceof IncomingRequest ? $request->getPost() : [];
        $post = is_array($post) ? $post : [];
        if ($need === null) {
            if ($rules->isBeforeCheck($method, $path)) {
                return null;
            }
            $need = $rules->need($method, $path, $post);
        } elseif ($need === Permissions::NONE) {
            // Only the exact path legacy answers before its check; a variant the
            // router trims into the route (a trailing slash) needs what legacy gives it.
            $exact = $rules->exact($requestPath);
            if ($rules->isBeforeCheck($method, $exact)) {
                return null;
            }
            $need = $rules->need($method, $exact, $post);
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

        // Legacy view_error()'s plain page. The page with the app's layout arrives with the Layout module.
        return $response->setStatusCode(403)->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setBody('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head><body><p>'
                . htmlspecialchars($refusal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></body></html>');
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
