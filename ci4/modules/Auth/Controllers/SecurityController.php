<?php

declare(strict_types=1);

namespace Apotheke\Auth\Controllers;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\RoleAdmin;
use Apotheke\Auth\SessionEpoch;
use Apotheke\Auth\SignInSwitch;
use Apotheke\Auth\UserAdmin;
use Apotheke\Auth\Views\SecurityView;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\SessionGate;
use Apotheke\Core\Libraries\Text;
use Apotheke\Layout\Page;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Control Panel → Users & sign-in (legacy public/index.php, "users &
 * sign-in" and "roles & permissions", and the security section of
 * /settings/{section}): the page, its settings form and reset, sign-in on or
 * off and your own password (/settings/password), accounts
 * (/settings/users…) and roles (/settings/roles…). Every route needs the
 * `users` permission and the global CSRF filter runs after it, as legacy
 * checks route_perm() and then check_csrf(). Pages drawn after a change use
 * the visitor as the request began (legacy current_user()'s per-request
 * cache).
 */
final class SecurityController extends AuthPages
{
    public const SECTION = 'security';

    /** Legacy /settings/{section}?done= toasts. */
    public const TOASTS = [
        'saved' => 'Settings saved', 'reset' => 'Section reset to defaults', 'logo' => 'Logo updated',
        'logo-reset' => 'Default logo restored', 'pw' => 'Password saved', 'pw-off' => 'Sign-in turned off',
        'user-added' => 'User added', 'user-role' => 'Role changed', 'user-pw' => 'Password set', 'user-renamed' => 'User renamed', 'user-deleted' => 'User deleted', 'user-approved' => 'Account approved',
        'token-saved' => 'Token saved', 'token-cleared' => 'Token cleared',
        'saved-token-cleared' => 'Settings saved. The token was cleared because the service address changed: enter it again.',
        'reset-token-cleared' => 'Section reset to defaults. The token was cleared because the service address changed: enter it again.', 'role-added' => 'Role added', 'role-saved' => 'Role saved', 'role-deleted' => 'Role deleted',
    ];

    /** @var array{id:?int,username:string,role:string,status:string,guest:bool,anon:bool}|null */
    private ?array $visitor = null;

    /** GET /settings/security */
    public function index(): ResponseInterface
    {
        $done = $this->query('done') ?? '';

        return $this->page($this->securityPage([], [], self::TOASTS[$done] ?? null), 200, true);
    }

    /** POST /settings/security: the section's settings. */
    public function save(): ResponseInterface
    {
        $settings = $this->settings();
        $raw = [];
        foreach ($settings->schema()->sectionKeys(self::SECTION) as $key) {
            $raw[$key] = $this->request->getPost($key) ?? '';
        }
        $errors = $settings->save($raw);
        if ($errors !== []) {
            return $this->page($this->securityPage($raw, $errors, null), 422);
        }

        return $this->redirectTo('/settings/security?done=saved');
    }

    /** POST /settings/security/reset */
    public function reset(): ResponseInterface
    {
        $this->settings()->resetSection(self::SECTION);

        return $this->redirectTo('/settings/security?done=reset');
    }

    /** POST /settings/password: turn sign-in off, change your own password, or turn sign-in on. */
    public function password(): ResponseInterface
    {
        $disable = $this->post('mode') === 'disable';
        $me = $this->visitorNow();
        try {
            if ($disable) {
                if (!$me['guest']) {
                    $this->accounts()->checkCurrent($me, $this->post('current'), $this->limiter());
                }
                $this->signInSwitch()->off();
            } elseif (!$me['guest'] && $me['id'] !== null) {
                // Signed in: your own password; this session stays signed in.
                $this->accounts()->checkCurrent($me, $this->post('current'), $this->limiter());
                $epoch = $this->userAdmin()->setPassword($me['id'], $this->post('new'), $this->post('confirm'));
                (new SessionGate(time()))->signIn($me['id'], $epoch, keepSignIn: true);
            } else {
                $name = Text::cleanLine($this->post('username'), Accounts::USERNAME_MAX * 2);
                $name = $name !== '' ? $name : 'admin';
                $user = $this->userAdmin()->findByName($name);
                if ($user !== null) {
                    // An account exists already: sign in as that admin to turn sign-in back on.
                    if ($user['role'] !== 'admin' || $this->accounts()->verify($name, $this->post('new')) === null) {
                        throw new UserError('Enter the password of the admin “' . $name . '” to turn sign-in on again.', 403);
                    }
                    $uid = $user['id'];
                } else {
                    /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
                    $db = service('apothekeDb');
                    $uid = Writes::transaction($db, fn (): int => $this->userAdmin()->create($name, $this->post('new'), 'admin', $this->post('confirm')));
                }
                $this->signInSwitch()->on();
                $account = $this->userAdmin()->find($uid);
                if ($account !== null) {
                    (new SessionGate(time()))->signIn($uid, (new SessionEpoch())->of($account));
                }
            }
        } catch (UserError $e) {
            return $this->page($this->securityPage([], ['password' => $e->getMessage()], null), self::status($e));
        }

        return $this->redirectTo('/settings/security?done=' . ($disable ? 'pw-off' : 'pw'));
    }

    /** POST /settings/users, /settings/users/{id} and /settings/users/{id}/{action} */
    public function user(string $id = '', string $action = ''): ResponseInterface
    {
        $me = $this->visitorNow();
        $uid = $id !== '' ? (int)$id : 0;
        $admin = $this->userAdmin();
        try {
            if ($uid === 0) {
                $admin->create($this->post('username'), $this->post('new'), $this->post('role'), $this->post('confirm'));
                $done = 'user-added';
            } elseif ($action === 'role') {
                $admin->setRole($uid, $this->post('role'));
                $done = 'user-role';
            } elseif ($action === 'password') {
                $epoch = $admin->setPassword($uid, $this->post('new'), $this->post('confirm'));
                if ($uid === $me['id']) {
                    (new SessionGate(time()))->signIn($uid, $epoch, keepSignIn: true);
                }
                $done = 'user-pw';
            } elseif ($action === 'rename') {
                $admin->rename($uid, $this->post('username'));
                $done = 'user-renamed';
            } elseif ($action === 'approve') {
                $admin->approve($uid);
                $done = 'user-approved';
            } elseif ($action === 'delete') {
                if ($uid === $me['id']) {
                    throw new UserError('You cannot delete your own account while signed in with it.', 409);
                }
                $admin->delete($uid);
                $done = 'user-deleted';
            } else {
                throw new UserError('Page not found.', 404);
            }
        } catch (UserError $e) {
            return $this->page($this->securityPage([], ['users' => $e->getMessage()], null), self::status($e));
        }

        return $this->redirectTo('/settings/security?done=' . $done . '#users');
    }

    /** POST /settings/roles, /settings/roles/{key} and /settings/roles/{key}/delete */
    public function role(string $key = '', string $delete = ''): ResponseInterface
    {
        $this->visitorNow();
        $raw = $this->request->getPost('perms');
        $perms = array_values(array_filter(is_array($raw) ? $raw : ($raw === null ? [] : [$raw]), 'is_string'));
        $roles = $this->roleAdmin();
        try {
            if ($key === '') {
                $key = $roles->create($this->post('name'), $perms);
                $done = 'role-added';
            } elseif ($delete !== '') {
                $roles->delete($key, $this->request->getPost('move_to') === null ? 'viewer' : $this->post('move_to'));
                $done = 'role-deleted';
                $key = '';
            } else {
                $roles->update($key, $this->request->getPost('name') === null ? null : $this->post('name'), $perms);
                $done = 'role-saved';
            }
        } catch (UserError $e) {
            if ($this->wantsJson()) {
                return $this->json(['ok' => false, 'error' => $e->getMessage()], self::status($e));
            }

            return $this->page($this->securityPage([], ['roles' => $e->getMessage()], null), self::status($e));
        }
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'key' => $key]);
        }

        return $this->redirectTo('/settings/security?done=' . $done . '#' . ($key !== '' ? 'rc-' . $key : 'roles'));
    }

    /**
     * @param array<string,mixed> $values re-submitted settings
     * @param array<string,string> $errors
     */
    private function securityPage(array $values, array $errors, ?string $toast): string
    {
        $me = $this->visitorNow();
        $settings = $this->settings();
        $schema = $settings->schema();
        $definitions = $schema->definitions();
        $fields = [];
        foreach ($schema->sectionKeys(self::SECTION) as $key) {
            [, $label, $type, , $help, $options] = $definitions[$key];
            $fields[] = ['key' => $key, 'label' => $label, 'type' => $type, 'default' => $schema->default($key, []), 'help' => $help,
                'options' => $options, 'value' => array_key_exists($key, $values) ? $values[$key] : $settings->get($key)];
        }
        $admin = $this->userAdmin();
        /** @var Page $page */
        $page = service('layout', false, $me);

        return SecurityView::page($page, [
            'on' => $settings->signInEnabled(),
            'guestBrowsing' => $settings->signInEnabled() && !$settings->get('auth_guest_disabled'),
            'me' => $me,
            'admins' => $admin->activeAdmins(),
            'users' => $admin->all(),
            'roles' => $this->roleAdmin()->all(),
            'fields' => $fields,
            'usernameMax' => Accounts::USERNAME_MAX,
            'passwordMin' => Accounts::PASSWORD_MIN,
            'timeFormat' => (string)$settings->get('time_format'),
            'timezone' => (string)$settings->get('timezone'),
        ], $errors, $toast);
    }

    /** @return array{id:?int,username:string,role:string,status:string,guest:bool,anon:bool} the visitor as the request began */
    private function visitorNow(): array
    {
        $this->visitor ??= $this->me();

        // The account can go between the permission filter and here (deleted or signed out by a request at the
        // same time): answer as the filter does for nobody, never with a server error.
        return $this->visitor ?? throw new UserError('Not signed in', 401);
    }

    /** @param array<string,mixed> $data legacy json_out() */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function status(UserError $e): int
    {
        $code = (int)$e->getCode();

        return $code !== 0 ? $code : 422;
    }

    private function userAdmin(): UserAdmin
    {
        /** @var UserAdmin $admin */
        $admin = service('userAdmin');

        return $admin;
    }

    private function roleAdmin(): RoleAdmin
    {
        /** @var RoleAdmin $admin */
        $admin = service('roleAdmin');

        return $admin;
    }

    private function signInSwitch(): SignInSwitch
    {
        /** @var SignInSwitch $switch */
        $switch = service('signInSwitch');

        return $switch;
    }
}
