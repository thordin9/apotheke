<?php

declare(strict_types=1);

namespace Apotheke\Auth\Controllers;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\Permissions;
use Apotheke\Auth\SignOutEverywhere;
use Apotheke\Auth\Views\AuthViews;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\SessionGate;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * /account and /account/signout (legacy public/index.php, "your account"):
 * your own password and sign out everywhere. The routes need `self` (signed
 * in with an account) and the global CSRF filter runs first, as legacy checks
 * route_perm() and then check_csrf(). The session that made the change stays
 * signed in with keepSignIn, so session_days does not start again (#87).
 */
final class AccountController extends AuthPages
{
    /** @var array{id:?int,username:string,role:string,status:string,guest:bool,anon:bool}|null the visitor as the request began */
    private ?array $visitor = null;

    /** POST /account/signout */
    public function signOutEverywhere(): ResponseInterface
    {
        $me = $this->account();
        /** @var SignOutEverywhere $signOut */
        $signOut = service('signOutEverywhere');
        $epoch = $signOut->run($me['id']);
        (new SessionGate(time()))->signIn($me['id'], $epoch, keepSignIn: true);

        return $this->redirectTo('/account?done=out');
    }

    /** /account: POST changes the password, every other method shows the page (legacy, N-63) */
    public function index(): ResponseInterface
    {
        $me = $this->me();
        if ($me === null || $me['guest'] || $me['id'] === null) {
            // Legacy: a guest goes to the user settings when it may manage users, else home.
            $roles = $this->roles();

            return $this->redirectTo($me !== null && $roles->has($me['role'], 'users') ? '/settings/security' : '/');
        }
        $account = ['id' => $me['id'], 'username' => $me['username'], 'role' => $me['role']];
        $this->visitor = $me;
        if ($this->posted()) {
            try {
                $epoch = $this->accounts()->changeOwnPassword($account, $this->post('current'), $this->post('new'), $this->post('confirm'), $this->limiter());
            } catch (UserError $e) {
                $code = (int)$e->getCode();

                return $this->page($this->accountPage($account, $e->getMessage(), null), $code !== 0 ? $code : 422);
            }
            (new SessionGate(time()))->signIn($me['id'], $epoch, keepSignIn: true);

            return $this->redirectTo('/account?done=pw');
        }
        $done = $this->query('done') ?? '';
        $toast = $done === 'pw' ? 'Password changed' : ($done === 'out' ? 'Signed out on every other device' : null);

        return $this->page($this->accountPage($account, null, $toast), 200, true);
    }

    /** @return array{id:int,username:string,role:string} the signed-in account (the route's `self` permission admits nobody else) */
    private function account(): array
    {
        $me = $this->me();
        if ($me === null || $me['guest'] || $me['id'] === null) {
            throw new RuntimeException('The self permission admitted a visitor without an account.');
        }

        return ['id' => $me['id'], 'username' => $me['username'], 'role' => $me['role']];
    }

    /** @param array{id:int,username:string,role:string} $me */
    private function accountPage(array $me, ?string $error, ?string $toast): string
    {
        $roles = $this->roles()->all();
        $names = [];
        foreach (Permissions::ALL as $key => [$name]) {
            $names[$key] = $name;
        }

        /** @var \Apotheke\Layout\Page $page */
        $page = service('layout', false, $this->visitor);

        return AuthViews::account($page, $me, [
            'roleName' => $this->roles()->name($me['role']),
            'perms' => $roles[$me['role']]['perms'] ?? [],
            'permNames' => $names,
            'passwordMin' => Accounts::PASSWORD_MIN,
        ], $error, $toast);
    }
}
