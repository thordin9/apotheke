<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\CsrfCheck;
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
 * Session CSRF token on every non-safe method (SEC-N10), with legacy's 419
 * answer: JSON for fetch/JSON clients, the app's error page otherwise. Safe
 * methods pass untouched, and the filter never starts a session: it reads
 * $_SESSION as the session filter left it. CI4's own cookie CSRF stays off.
 * A returned response skips global after filters, so the security headers are set here.
 */
final class CsrfFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $check = new CsrfCheck();
        if (!$check->applies($request->getMethod())) {
            return null;
        }
        $post = $request instanceof IncomingRequest ? $request->getPost() : $_POST;
        if (!is_array($post)) {
            $post = [];
        }
        if ($check->passes($this->sessionToken(), $post, $request->getHeaderLine('X-CSRF-Token'))) {
            return null;
        }
        $response = new Response(config(App::class));
        $response->setStatusCode(CsrfCheck::STATUS, CsrfCheck::REASON);
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }
        $server = $request->getServer();
        if ((new WantsJson())->check(is_array($server) ? $server : [])) {
            // Legacy json_out().
            $response->setHeader('Content-Type', 'application/json; charset=utf-8');
            $response->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
            $response->setBody($check->jsonBody());

            return $response;
        }
        // Legacy view_error(): the app's error page (Layout).
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->setBody(Services::errorPage()->html(CsrfCheck::STATUS, CsrfCheck::MESSAGE));

        return $response;
    }

    private function sessionToken(): string
    {
        $token = $_SESSION['csrf'] ?? '';

        return is_string($token) ? $token : '';
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
