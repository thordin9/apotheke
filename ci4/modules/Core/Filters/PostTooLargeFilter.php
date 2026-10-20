<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\PostTooLarge;
use Apotheke\Core\Http\SecurityPolicy;
use Apotheke\Core\Http\WantsJson;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

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
            $response->setHeader('Content-Type', 'application/json');
            $response->setBody($checker->jsonBody());

            return $response;
        }
        $response->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->setBody($checker->message());

        return $response;
    }

    private function isHealth(RequestInterface $request): bool
    {
        $path = strtolower(trim($request->getUri()->getPath(), '/'));

        return $path === 'healthz' || $path === 'health';
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
