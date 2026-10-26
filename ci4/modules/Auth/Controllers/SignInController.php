<?php

declare(strict_types=1);

namespace Apotheke\Auth\Controllers;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\SessionEpoch;
use Apotheke\Auth\Views\AuthViews;
use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\ErrorPage;
use Apotheke\Core\Http\SessionGate;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * /login, /register and /logout (legacy public/index.php, "auth"). Legacy
 * answers these before its permission check and checks the CSRF token itself,
 * after its own redirects, so the routes are `none` and skip the global CSRF
 * filter (Config\Filters); every POST here calls checkCsrf() where legacy
 * calls check_csrf().
 */
final class SignInController extends AuthPages
{
    /** /login: POST signs in, every other method shows the page (legacy, N-63) */
    public function login(): ResponseInterface
    {
        // A failed sign-in answers at a fixed deadline from here (Auth\SignInPace).
        $started = $this->accounts()->startSignIn();
        $this->firstAdmin();
        if (!$this->settings()->signInEnabled() || $this->signedIn()) {
            return $this->redirectTo('/');
        }
        if (!$this->posted()) {
            return $this->page($this->loginPage(null, '', $this->query('registered')));
        }
        $this->checkCsrf();
        $raw = $this->post('username');
        $who = \Apotheke\Core\Libraries\Text::cleanLine($raw, Accounts::USERNAME_MAX * 2);
        try {
            $mine = $this->limiter()->hit('login_address');   // all names from this address
            $this->limiter()->hit('login', $who);
            // A name with a null character anywhere (checked before trimming) never matches an account (N-221).
            $user = $this->accounts()->signIn($started, str_contains($raw, "\0") ? $raw : $who, $this->post('password'));
            if ($user !== null) {
                $this->accounts()->admit($user);
                (new SessionGate(time()))->signIn($user['id'], (new SessionEpoch())->of($user));
                $this->limiter()->clear('login', $who);
                $this->limiter()->forget($mine);   // a success takes back only its own address row

                return $this->redirectTo('/');
            }
            return $this->page($this->loginPage('Wrong user name or password.', $who), 401);
        } catch (UserError $e) {
            return $this->page($this->loginPage($e->getMessage(), $who), self::code($e, 403));
        }
    }

    /** /register: POST registers, every other method shows the page (legacy, N-63) */
    public function register(): ResponseInterface
    {
        $this->firstAdmin();
        $settings = $this->settings();
        if (!$settings->signInEnabled() || $settings->get('auth_register_disabled')) {
            /** @var ErrorPage $error */
            $error = service('errorPage');

            return $this->page($error->html(404, 'Registration is closed – ask an admin for an account.'), 404);
        }
        if ($this->signedIn()) {
            return $this->redirectTo('/');
        }
        if (!$this->posted()) {
            return $this->page($this->registerPage(null, ''), 200, true);
        }
        $this->checkCsrf();
        $raw = $this->post('username');
        $who = \Apotheke\Core\Libraries\Text::cleanLine($raw, Accounts::USERNAME_MAX * 2);
        try {
            $this->limiter()->hit('register');
            // Checked on the name as sent: a null character anywhere is refused, never trimmed away (N-261).
            $user = $this->accounts()->register(str_contains($raw, "\0") ? $raw : $who, $this->post('new'), $this->post('confirm'), (string)$settings->get('auth_register_role'), (bool)$settings->get('auth_register_approval'));
        } catch (UserError $e) {
            return $this->page($this->registerPage($e->getMessage(), $who), self::code($e, 422));
        }
        if ($user['status'] === 'active') {
            (new SessionGate(time()))->signIn($user['id'], (new SessionEpoch())->of($user));

            return $this->redirectTo('/?done=welcome');
        }

        return $this->redirectTo('/login?registered=pending');
    }

    /**
     * POST /logout. A live session still needs the token. One that already
     * ended (idle, absolute, or an old epoch) has nothing left to protect, so
     * the open page can sign out instead of answering 419.
     */
    public function logout(): ResponseInterface
    {
        $gate = new SessionGate(time());
        if ($this->signedIn()) {
            $this->checkCsrf();
            $gate->signOut();
        } elseif ($this->sessionCookieLive($gate) || session_status() === PHP_SESSION_ACTIVE) {
            $gate->signOut();
        }

        return $this->redirectTo($this->settings()->signInEnabled() ? '/login' : '/');
    }

    private function sessionCookieLive(SessionGate $gate): bool
    {
        $id = $this->request->getCookie(SessionGate::COOKIE);
        /** @var ApothekeConfig $config */
        $config = service('apothekeConfig');

        return is_string($id) && $gate->isLive($id, $config->dataDir() . '/sessions');
    }

    /** Legacy auth_enabled() creates the account "admin" from APP_PASSWORD on an install without accounts. */
    private function firstAdmin(): void
    {
        /** @var ApothekeConfig $config */
        $config = service('apothekeConfig');
        $password = $config->values()['app_password'] ?? '';
        $this->accounts()->ensureFirstAdmin((bool)$this->settings()->get('auth_enabled'), is_string($password) ? $password : '');
    }

    private function loginPage(?string $error, string $username, ?string $note = null): string
    {
        $settings = $this->settings();

        return AuthViews::login($this->layout(), [
            'appName' => (string)$settings->get('app_name'),
            'tagline' => (string)$settings->get('app_tagline'),
            'registrationOpen' => $settings->signInEnabled() && !$settings->get('auth_register_disabled'),
            'guestBrowsing' => $settings->signInEnabled() && !$settings->get('auth_guest_disabled'),
            'usernameMax' => Accounts::USERNAME_MAX,
        ], $error, $username, $note);
    }

    private function registerPage(?string $error, string $username): string
    {
        $settings = $this->settings();
        $role = (string)$settings->get('auth_register_role');

        return AuthViews::register($this->layout(), [
            'appName' => (string)$settings->get('app_name'),
            'approval' => (bool)$settings->get('auth_register_approval'),
            'registerRole' => $role,
            'roleName' => $this->roles()->name($role),
            'usernameMax' => Accounts::USERNAME_MAX,
            'passwordMin' => Accounts::PASSWORD_MIN,
        ], $error, $username);
    }

    /** Legacy $e->getCode() ?: $default. */
    private static function code(UserError $e, int $default): int
    {
        $code = (int)$e->getCode();

        return $code !== 0 ? $code : $default;
    }
}
