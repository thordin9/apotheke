<?php

declare(strict_types=1);

namespace Apotheke\Auth\Controllers;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\CurrentUser;
use Apotheke\Auth\RateLimiter;
use Apotheke\Auth\RoleRepository;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\CsrfCheck;
use Apotheke\Core\Http\WantsJson;
use Apotheke\Layout\Page;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared answers for the sign-in and account pages: legacy redirect(),
 * check_csrf(), the page answer and the posted fields. The services come from
 * Config\Services (service() is allowed in controllers).
 */
abstract class AuthPages extends Controller
{
    /** Legacy check_csrf(): the session token from the form or the X-CSRF-Token header, else 419. */
    protected function checkCsrf(): void
    {
        $token = $_SESSION['csrf'] ?? '';
        $post = $this->request->getPost();
        if (!(new CsrfCheck())->passes(is_string($token) ? $token : '', is_array($post) ? $post : [], $this->request->getHeaderLine('X-CSRF-Token'))) {
            throw new UserError(CsrfCheck::MESSAGE, CsrfCheck::STATUS);
        }
    }

    /** A posted field as legacy's (string)$_POST[...] (an array reads as "Array"). */
    protected function post(string $field): string
    {
        return self::str($this->request->getPost($field));
    }

    /** A query field as legacy's (string)$_GET[...], or null when absent. */
    protected function query(string $field): ?string
    {
        $v = $this->request->getGet($field);

        return $v === null ? null : self::str($v);
    }

    protected function wantsJson(): bool
    {
        $server = $this->request->getServer();

        return (new WantsJson())->check(is_array($server) ? $server : []);
    }

    /** Legacy redirect(): 303, or {ok, redirect} for fetch. */
    protected function redirectTo(string $to): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->response->setStatusCode(200)
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
                ->setBody((string)json_encode(['ok' => true, 'redirect' => $to], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $this->response->setStatusCode(303)->setHeader('Location', $to);
    }

    /**
     * A page. $noStore: legacy no_store() before it. Otherwise the caching
     * headers are what PHP's session start sent on legacy (session cache
     * limiter), or none when no session started.
     */
    protected function page(string $html, int $status = 200, bool $noStore = false): ResponseInterface
    {
        $this->response->setStatusCode($status)->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody($html);
        $this->response->removeHeader('Cache-Control');
        if ($noStore) {
            return $this->response->setHeader('Cache-Control', 'no-store');
        }
        foreach (headers_list() as $line) {
            if (stripos($line, 'Cache-Control:') === 0) {
                $this->response->setHeader('Cache-Control', trim(substr($line, 14)));
            }
        }

        return $this->response;
    }

    /** @return array{id:?int,username:string,role:string,status:string,guest:bool,anon:bool}|null */
    protected function me(): ?array
    {
        return $this->currentUser()->resolve();
    }

    /** Legacy signed_in(): an account, not the anonymous admin or a guest. */
    protected function signedIn(): bool
    {
        $me = $this->me();

        return $me !== null && !$me['guest'];
    }

    protected function layout(): Page
    {
        /** @var Page $page */
        $page = service('layout');

        return $page;
    }

    protected function settings(): SettingsService
    {
        /** @var SettingsService $settings */
        $settings = service('settings');

        return $settings;
    }

    protected function accounts(): Accounts
    {
        /** @var Accounts $accounts */
        $accounts = service('accounts');

        return $accounts;
    }

    protected function limiter(): RateLimiter
    {
        /** @var RateLimiter $limiter */
        $limiter = service('rateLimiter');

        return $limiter;
    }

    protected function roles(): RoleRepository
    {
        /** @var RoleRepository $roles */
        $roles = service('roles');

        return $roles;
    }

    protected function currentUser(): CurrentUser
    {
        /** @var CurrentUser $user */
        $user = service('currentUser');

        return $user;
    }

    private static function str(mixed $v): string
    {
        return is_array($v) ? 'Array' : (is_scalar($v) ? (string)$v : '');
    }
}
