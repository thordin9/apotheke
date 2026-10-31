<?php

declare(strict_types=1);

namespace Apotheke\Core\Controllers;

use Apotheke\Core\Http\CookieConsent;
use Apotheke\Core\Http\RequestScheme;
use Apotheke\Core\Http\SameOrigin;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * POST /consent: the consent notice's Accept without JavaScript (Config\Consent). No CSRF token (there is no
 * session yet), so the request must be strictly same-origin (SameOrigin::strict(), else 403) and carry
 * consent=accept (else 400). It sets only the consent cookie and sends the browser back (303) to `return`
 * when that is a path on this site (CookieConsent::returnPath()), else to "/".
 */
final class ConsentController extends Controller
{
    public function accept(): ResponseInterface
    {
        $server = $this->request->getServer();
        $server = is_array($server) ? $server : [];
        $settings = Services::settings();
        $publicUrl = (string)$settings->get('public_url');
        $scheme = new RequestScheme((bool)$settings->get('trust_proxy'));
        $this->response->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
        if (!(new SameOrigin())->strict($server, $scheme->isHttps($server), $publicUrl)) {
            return $this->refuse(SameOrigin::STATUS, SameOrigin::MESSAGE);
        }
        if ($this->request->getPost('consent') !== 'accept') {
            return $this->refuse(400, 'Bad request');
        }
        $host = (string)($server['HTTP_HOST'] ?? '');

        return $this->response->setStatusCode(303)
            ->setHeader('Set-Cookie', CookieConsent::cookie($host, $scheme->secureCookie($server, $publicUrl), CookieConsent::configuredDomain()))
            ->setHeader('Location', CookieConsent::returnPath($this->request->getPost('return')));
    }

    private function refuse(int $status, string $message): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setBody(Services::errorPage()->html($status, $message));
    }
}
