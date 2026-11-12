<?php

declare(strict_types=1);

namespace Apotheke\Core\Controllers;

use Apotheke\Core\Http\CookieConsent;
use Apotheke\Core\Http\RequestScheme;
use Apotheke\Core\Http\SameOrigin;
use Apotheke\Core\Http\SessionGate;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * POST /consent: the consent notice's Accept and Decline (Config\Consent). No CSRF token (there is no
 * session yet), so the request must be strictly same-origin (SameOrigin::strict(), else 403) and carry
 * consent=accept or consent=decline (else 400). Accept sets only the consent cookie. Decline expires the
 * consent cookie and ends a session the request carries (SessionGate::signOut()), as a request without
 * consent does (SessionCookie). Both send the browser back (303) to `return` when that is a path on this
 * site (CookieConsent::returnPath()), else to "/".
 */
final class ConsentController extends Controller
{
    public function accept(): ResponseInterface
    {
        $server = $this->request->getServer();
        $server = is_array($server) ? $server : [];
        $settings = Services::settings();
        $publicUrl = (string)$settings->get('public_url');
        $scheme = RequestScheme::fromSetting((bool)$settings->get('trust_proxy'));
        $this->response->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
        if (!(new SameOrigin())->strict($server, $scheme->isHttps($server), $publicUrl)) {
            return $this->refuse(SameOrigin::STATUS, SameOrigin::MESSAGE);
        }
        $choice = $this->request->getPost('consent');
        if ($choice !== 'accept' && $choice !== 'decline') {
            return $this->refuse(400, 'Bad request');
        }
        $host = (string)($server['HTTP_HOST'] ?? '');
        $secure = $scheme->secureCookie($server, $publicUrl);
        if ($choice === 'decline') {
            $this->endSession();
            // Next to the session cookie's expiry in PHP's header list (a response header would replace it).
            if (!headers_sent()) {
                header('Set-Cookie: ' . CookieConsent::expired($host, $secure, CookieConsent::configuredDomain()), false);
            }

            return $this->response->setStatusCode(303)
                ->setHeader('Location', CookieConsent::returnPath($this->request->getPost('return')));
        }

        return $this->response->setStatusCode(303)
            ->setHeader('Set-Cookie', CookieConsent::cookie($host, $secure, CookieConsent::configuredDomain()))
            ->setHeader('Location', CookieConsent::returnPath($this->request->getPost('return')));
    }

    /**
     * Decline with consent still in the request: end the session it carries. Without consent the session
     * filter has already done so.
     */
    private function endSession(): void
    {
        $cookies = (array)$this->request->getCookie();
        if (!CookieConsent::configured()->given($cookies)) {
            return;
        }
        $carried = SessionGate::carried($cookies);
        if (SessionGate::loaded()) {
            (new SessionGate(time()))->signOut($carried);
        } elseif ($carried !== []) {
            SessionGate::expireCookies($carried);
        }
    }

    private function refuse(int $status, string $message): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setBody(Services::errorPage()->html($status, $message));
    }
}
