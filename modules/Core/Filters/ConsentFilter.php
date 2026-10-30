<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\CookieConsent;
use Apotheke\Core\Http\LegacyPathRouter;
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
 * Cookie consent (Config\Consent, docs/security.md). A required filter, before
 * the session filter. Without consent:
 * - a state-changing request (sign-in, any form or API write: they all need the
 *   session's CSRF token) gets 403 consent_required: problem+json under /api,
 *   legacy JSON for fetch, else the error page, which shows the consent notice;
 * - no Set-Cookie leaves: the session filter and SessionToken start no session,
 *   and as a backstop every Set-Cookie is removed from the answer (after), and
 *   from PHP's header list right before headers go out, error answers included.
 * With consent nothing changes. The server never sets the consent cookie itself.
 */
final class ConsentFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $consent = CookieConsent::configured();
        if ($consent->given(self::cookies($request))) {
            return null;
        }
        // The backstop for answers that skip the after filters (an uncaught error, a streamed body).
        header_register_callback(static function (): void {
            header_remove('Set-Cookie');
        });
        if (!$consent->needed($request->getMethod())) {
            return null;
        }
        $response = new Response(config(App::class));
        $server = $request->getServer();
        $server = is_array($server) ? $server : [];
        if (ApiResponse::isApi(LegacyPathRouter::requestPath($request))) {
            ApiResponse::problem($response, CookieConsent::STATUS, CookieConsent::CODE, CookieConsent::MESSAGE);
            $body = json_decode((string)$response->getBody(), true);

            return $response->setBody((string)json_encode(['error' => CookieConsent::CODE] + (is_array($body) ? $body : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $response->setStatusCode(CookieConsent::STATUS);
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }
        if ((new WantsJson())->check($server)) {
            $response->setHeader('Content-Type', 'application/json; charset=utf-8');
            $response->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');

            return $response->setBody($consent->jsonBody());
        }
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8');

        return $response->setBody(Services::errorPage()->html(CookieConsent::STATUS, CookieConsent::MESSAGE));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        if (CookieConsent::configured()->given(self::cookies($request))) {
            return null;
        }
        $response->getCookieStore()->clear();
        $response->removeHeader('Set-Cookie');
        if (!headers_sent()) {
            header_remove('Set-Cookie');   // a session PHP started during the request
        }

        return $response;
    }

    /** @return array<array-key,mixed> */
    private static function cookies(RequestInterface $request): array
    {
        $cookies = $request instanceof IncomingRequest ? $request->getCookie() : $_COOKIE;

        return is_array($cookies) ? $cookies : [];
    }
}
