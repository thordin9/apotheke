<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\ApiCsrf;
use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\RequestScheme;
use Apotheke\Core\Http\SameOrigin;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Services;

/**
 * /api's CSRF check (ApiCsrf), in place of the HTML pages' form-token filter.
 * A refusal is problem+json; the required api filter adds the headers.
 */
final class ApiCsrfFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $server = $request->getServer();
        $server = is_array($server) ? $server : [];
        $settings = Services::settings();
        $https = (new RequestScheme((bool)$settings->get('trust_proxy')))->isHttps($server);
        $token = $_SESSION['csrf'] ?? '';
        $refusal = (new ApiCsrf())->refusal(
            $request->getMethod(),
            $server,
            is_string($token) ? $token : '',
            (new SameOrigin())->ownOrigins($server, $https, (string)$settings->get('public_url')),
            Services::apothekeConfig()->corsAllowedOrigins(),
        );
        if ($refusal === null) {
            return null;
        }

        return ApiResponse::problem(new Response(config(App::class)), $refusal['status'], $refusal['code'], $refusal['detail']);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
