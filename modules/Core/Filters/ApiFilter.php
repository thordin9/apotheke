<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\ApiSecurityPolicy;
use Apotheke\Core\Http\Cors;
use Apotheke\Core\Http\LegacyPathRouter;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Services;

/**
 * /api (API plan §2.2, §2.5). A required filter, so it runs on every API
 * answer, refusals and 404s included: before, it answers a CORS preflight
 * (OPTIONS) with 204 ahead of the session and permission filters; after, it
 * sets the API security headers and, for a listed origin, the CORS headers.
 * Other paths pass untouched.
 */
final class ApiFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if (!ApiResponse::isApi(LegacyPathRouter::requestPath($request)) || strtoupper($request->getMethod()) !== 'OPTIONS') {
            return null;
        }

        return (new Response(config(App::class)))->setStatusCode(204);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        if (!ApiResponse::isApi(LegacyPathRouter::requestPath($request))) {
            return null;
        }
        $config = Services::apothekeConfig();
        $https = str_starts_with(strtolower((string)Services::settings()->get('public_url')), 'https://');
        $response->removeHeader('X-Frame-Options');   // the HTML set's; frame-ancestors covers it
        foreach ((new ApiSecurityPolicy())->headers($https) as $name => $value) {
            $response->removeHeader($name)->setHeader($name, $value);
        }
        $cors = new Cors($config->corsAllowedOrigins(), ENVIRONMENT === 'development');
        if ($cors->origins() !== []) {
            $response->setHeader('Vary', 'Origin');
            $origin = $request->getHeaderLine('Origin');
            foreach ($cors->headers($origin, strtoupper($request->getMethod()) === 'OPTIONS') as $name => $value) {
                $response->setHeader($name, $value);
            }
        }

        return $response;
    }
}
