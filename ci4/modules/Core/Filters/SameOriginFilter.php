<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\RequestScheme;
use Apotheke\Core\Http\SameOrigin;
use Apotheke\Core\Http\SecurityPolicy;
use Apotheke\Core\Http\WantsJson;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Services;

/**
 * With sign-in off, a non-safe request from another site gets 403 (SEC-M3), on
 * every path, before the CSRF check, as legacy runs it before any route. Safe
 * methods and requests with sign-in on pass without a settings read beyond
 * signInEnabled(). A returned response skips global after filters, so the
 * security headers are set here.
 */
final class SameOriginFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $rule = new SameOrigin();
        if ($rule->isSafe($request->getMethod())) {
            return null;
        }
        $settings = Services::settings();
        if ($settings->signInEnabled()) {
            return null;
        }
        $server = $request->getServer();
        $server = is_array($server) ? $server : [];
        $https = (new RequestScheme((bool)$settings->get('trust_proxy')))->isHttps($server);
        if ($rule->check($server, $https, (string)$settings->get('public_url'))) {
            return null;
        }
        $response = new Response(config(App::class));
        $response->setStatusCode(SameOrigin::STATUS);
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }
        if ((new WantsJson())->check($server)) {
            $response->setHeader('Content-Type', 'application/json');
            $response->setBody($rule->jsonBody());

            return $response;
        }
        $response->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->setBody(SameOrigin::MESSAGE);

        return $response;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
