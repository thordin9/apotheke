<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\HostAllowList;
use Apotheke\Core\Http\SecurityPolicy;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Services;

/**
 * Host allow-list (SEC-M3), before /healthz and every route, as legacy runs
 * request_host_ok() right after the security headers. An unknown name gets 421
 * with legacy's text, which does not repeat the name.
 * A returned response skips global after filters, so the security headers are set here.
 */
final class HostFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $host = $request->getServer('HTTP_HOST');
        if (Services::hostCheck(false)->passes(is_string($host) ? $host : '')) {
            return null;
        }
        $response = new Response(config(App::class));
        $response->setStatusCode(421);
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }
        $response->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->removeHeader('Cache-Control');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setBody(HostAllowList::REFUSAL);

        return $response;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
