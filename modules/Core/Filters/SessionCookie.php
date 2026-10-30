<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Http\CookieConsent;
use Apotheke\Core\Http\LegacyPathRouter;
use Apotheke\Core\Http\RequestScheme;
use Apotheke\Core\Http\SessionGate;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Cookie;
use Config\Services;
use Config\Session;
use Throwable;

/**
 * Sets up the apothekesid session (__Host-apothekesid on HTTPS) the same way the legacy app does, and loads
 * it only when the request's cookie names a live session (SEC-L2, legacy
 * init_session() on main); SessionToken starts one on demand. CI4's Session
 * service is configured to match, and PHP's session API does the work. The
 * lifetime follows session_days (SEC-N9); the Secure flag follows trust_proxy
 * and public_url (legacy session_cookie_secure()). A signed-in session ends
 * after session_idle_hours without a request or session_days after sign-in
 * (SessionGate, legacy init_session() on main #84). Each request starts with
 * an empty $_SESSION, so a worker never carries one over (#76 L-3); a session
 * an earlier request left open is closed first (#82 L-2).
 */
final class SessionCookie implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        // #82 L-2: an error path can leave the previous request's session open in a
        // long-lived worker. Close it, as PHP does at the end of a normal request.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // #76 L-3: a long-lived worker keeps $_SESSION from the previous request.
        // Every request starts without one; only a live cookie loads it again.
        $_SESSION = [];
        if ($this->isHealth($request) || $this->isPublicAsset($request)) {
            return null;
        }
        // Cookie consent: without it no session is loaded or started, so no cookie (ConsentFilter).
        if (!CookieConsent::configured()->given($request instanceof IncomingRequest ? (array)$request->getCookie() : $_COOKIE)) {
            return null;
        }
        $config = ApothekeConfig::fromEnvironment();
        $server = $request->getServer();
        $server = is_array($server) ? $server : [];
        // trust_proxy and public_url come from this request's SettingsService, on
        // CI4's connection: no separate settings connection (#74 N-2, SEC-N11).
        try {
            $settings = Services::settings();
            $trustProxy = (bool)$settings->get('trust_proxy');
            $publicUrl = (string)$settings->get('public_url');
        } catch (Throwable) {
            $trustProxy = false;
            $publicUrl = $config->publicUrl();
        }
        $secure = (new RequestScheme($trustProxy))->secureCookie($server, $publicUrl);
        $cookie = config(Cookie::class);
        $cookie->secure = $secure;
        $lifetime = Services::sessionLifetime(false)->seconds();
        $session = config(Session::class);
        $name = SessionGate::cookieName($secure);
        $session->cookieName = $name;
        $session->expiration = $lifetime;
        $save = $config->dataDir() . '/sessions';
        if (!is_dir($save)) {
            @mkdir($save, 0775, true);
        }
        $session->savePath = $save;
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        ini_set('session.use_strict_mode', '1');
        session_save_path($save);
        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
        // SEC-L2: only a cookie that names a live session loads one. A signed-in
        // session ends after session_idle_hours idle or session_days after sign-in;
        // any other session after session_days without use (SEC-N6).
        $id = SessionGate::requestId(static fn (string $n): mixed => $request instanceof IncomingRequest ? $request->getCookie($n) : ($_COOKIE[$n] ?? null), $secure);
        $gate = new SessionGate(time());
        if ($id !== null && $gate->isLive($id, $save)) {
            if (!isset($_COOKIE[$name])) {
                session_id($id);   // it came under the plain name on HTTPS: PHP sends it again under the new one
            }
            $gate->resume(Services::sessionIdle(false)->seconds(), $lifetime);
        }

        return null;
    }

    private function isHealth(RequestInterface $request): bool
    {
        return LegacyPathRouter::isHealth($request);
    }

    /**
     * Branding output the legacy front controller serves before it starts a
     * session: /theme.css, /labels.css, /manifest.webmanifest, /favicon.ico
     * and /brand/<name> (the legacy name pattern). No session, so no cookie.
     */
    private function isPublicAsset(RequestInterface $request): bool
    {
        $path = LegacyPathRouter::requestPath($request);

        return in_array($path, ['/theme.css', '/labels.css', '/manifest.webmanifest', '/favicon.ico'], true)
            || preg_match('#^/brand/[a-z0-9.\-]+$#D', $path) === 1;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
