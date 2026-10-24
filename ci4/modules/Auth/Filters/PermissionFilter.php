<?php

declare(strict_types=1);

namespace Apotheke\Auth\Filters;

use Apotheke\Auth\PermissionCheck;
use Apotheke\Auth\RoutePermission;
use Apotheke\Core\Http\SecurityPolicy;
use Apotheke\Core\Http\WantsJson;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Services;

/**
 * Legacy's per-route permission check (route_perm() and user_can() in
 * public/index.php). A required before filter after `session` and
 * `sameorigin`, so it runs for every request, routed or not, before the CSRF
 * check, in legacy's order. The permission comes from the method and path,
 * as in legacy, so a new route is covered without being listed.
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
        // The path CI4 routes (dot segments resolved), decoded as legacy's.
        $path = '/' . ltrim(rawurldecode($request->getUri()->getPath()), '/');
        $routes = new RoutePermission();
        if ($routes->isBeforeCheck($method, $path)) {
            return null;
        }
        $post = $request instanceof IncomingRequest ? $request->getPost() : [];
        $need = $routes->need($method, $path, is_array($post) ? $post : []);
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

    /** @param array<string,mixed> $data legacy json_out() */
    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        return $response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
