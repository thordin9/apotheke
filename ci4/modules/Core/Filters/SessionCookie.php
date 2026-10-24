<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DsnTranslator;
use Apotheke\Core\Http\CsrfCheck;
use Apotheke\Core\Http\RequestScheme;
use Apotheke\Core\Http\TrustProxyReader;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Cookie;
use Config\Services;
use Config\Session;

/**
 * Opens the apothekesid session the same way the legacy app does.
 * CI4's Session service is configured to match, and started with PHP's session
 * API. The lifetime follows session_days (SEC-N9).
 */
final class SessionCookie implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        if ($this->isHealth($request) || $this->isPublicAsset($request) || session_status() === PHP_SESSION_ACTIVE) {
            return null;
        }
        $config = ApothekeConfig::fromEnvironment();
        $group = (new DsnTranslator())->translate($config->database());
        $server = $request->getServer();
        $server = is_array($server) ? $server : [];
        $secure = (new RequestScheme((new TrustProxyReader($group))->trusted()))->secureCookie($server);
        $cookie = config(Cookie::class);
        $cookie->secure = $secure;
        $lifetime = Services::sessionLifetime()->seconds();
        $session = config(Session::class);
        $session->cookieName = 'apothekesid';
        $session->expiration = $lifetime;
        $save = $config->dataDir() . '/sessions';
        if (!is_dir($save)) {
            @mkdir($save, 0775, true);
        }
        $session->savePath = $save;
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        ini_set('session.use_strict_mode', '1');
        session_save_path($save);
        session_name('apothekesid');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
        session_start();
        // Legacy init_session(): every session has a CSRF token for CsrfFilter.
        if (!is_string($_SESSION['csrf'] ?? null) || $_SESSION['csrf'] === '') {
            $_SESSION['csrf'] = (new CsrfCheck())->newToken();
        }

        return null;
    }

    private function isHealth(RequestInterface $request): bool
    {
        $path = strtolower(trim($request->getUri()->getPath(), '/'));

        return $path === 'healthz' || $path === 'health';
    }

    /**
     * Branding output the legacy front controller serves before it starts a
     * session: /theme.css, /labels.css, /manifest.webmanifest, /favicon.ico
     * and /brand/<name> (the legacy name pattern). No session, so no cookie.
     */
    private function isPublicAsset(RequestInterface $request): bool
    {
        $path = trim($request->getUri()->getPath(), '/');

        return in_array($path, ['theme.css', 'labels.css', 'manifest.webmanifest', 'favicon.ico'], true)
            || preg_match('#^brand/[a-z0-9.\-]+$#D', $path) === 1;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
