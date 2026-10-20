<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\OriginalsGuard;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

/** Backup for the front controller: /originals is 404 with no security headers. */
final class OriginalsGuardFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $path = rawurldecode($request->getUri()->getPath());
        if ($path === '') {
            $path = '/';
        }
        if (!(new OriginalsGuard())->matches($path)) {
            return null;
        }
        $response = new Response(config(App::class));
        $response->setStatusCode(404);
        $response->removeHeader('Cache-Control');
        $response->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->setBody('Not found');

        return $response;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
