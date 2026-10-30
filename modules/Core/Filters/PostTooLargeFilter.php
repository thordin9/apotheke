<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\LegacyPathRouter;
use Apotheke\Core\Http\PostTooLarge;
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
 * An empty POST that still has a Content-Length is the body PHP dropped.
 * A returned response skips global after filters, so the security headers are set here.
 */
final class PostTooLargeFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if ($this->isHealth($request)) {
            return null;
        }
        $checker = new PostTooLarge();
        $server = $request->getServer();
        $server = is_array($server) ? $server : [];
        $length = (int)($server['CONTENT_LENGTH'] ?? 0);
        if (ApiResponse::isApi(LegacyPathRouter::requestPath($request))) {
            // A JSON body never fills $_POST, so /api goes by the declared length (API plan §2.3).
            $limit = ini_parse_quantity((string)ini_get('post_max_size'));
            if ($limit <= 0 || $length <= $limit) {
                return null;
            }

            return ApiResponse::problem(new Response(config(App::class)), 413, 'upload.too_large', $checker->message());
        }
        $post = $request instanceof IncomingRequest ? $request->getPost() : $_POST;
        if (!is_array($post)) {
            $post = [];
        }
        if (!$checker->triggered($request->getMethod(), $post, $_FILES, $length)) {
            return null;
        }
        $response = new Response(config(App::class));
        $response->setStatusCode(413);
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }
        if ((new WantsJson())->check($server)) {
            // Legacy json_out().
            $response->setHeader('Content-Type', 'application/json; charset=utf-8');
            $response->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
            $response->setBody($checker->jsonBody());

            return $response;
        }
        // Legacy view_error(): the app's error page (Layout).
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->setBody(Services::errorPage()->html(413, $checker->message()));

        return $response;
    }

    private function isHealth(RequestInterface $request): bool
    {
        return LegacyPathRouter::isHealth($request);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
