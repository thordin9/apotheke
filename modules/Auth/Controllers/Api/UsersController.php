<?php

declare(strict_types=1);

namespace Apotheke\Auth\Controllers\Api;

use Apotheke\Auth\Controllers\AuthPages;
use Apotheke\Auth\RoleAdmin;
use Apotheke\Auth\UserAdmin;
use Apotheke\Core\Http\ApiResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /api/v1/users, /api/v1/roles and /api/v1/security (API plan §3, Users and sign-in row; P2 reads): what
 * Control Panel → Users & sign-in shows, for the users permission. Every answer is built field by field: an
 * account is id, name, role, status, created_at and last_login_at (2.6.x stores no e-mail address), and the
 * security read is the sign-in policy (POLICY), so a password hash, a session epoch or any other stored value
 * never reaches a client.
 */
final class UsersController extends AuthPages
{
    /** The security section's settings this API reads: the sign-in policy, never a secret. */
    public const POLICY = ['auth_guest_disabled', 'auth_register_disabled', 'auth_register_role', 'auth_register_approval',
        'session_days', 'session_idle_hours'];

    /** Readable security-section settings left out on purpose: trust_proxy is server set-up, not sign-in policy. */
    public const NOT_POLICY = ['trust_proxy'];

    /** GET /api/v1/users: every account, sorted by name. */
    public function listUsers(): ResponseInterface
    {
        /** @var UserAdmin $admin */
        $admin = service('userAdmin');
        $out = [];
        foreach ($admin->all() as $u) {
            $out[] = ['id' => $u['id'], 'name' => $u['username'], 'role' => $u['role'], 'status' => $u['status'],
                'created_at' => $u['created_at'], 'last_login_at' => $u['last_login_at']];
        }

        return ApiResponse::json($this->response, ['data' => $out]);
    }

    /** GET /api/v1/roles: every role, built-in first, with its permissions and how many accounts have it. */
    public function listRoles(): ResponseInterface
    {
        /** @var RoleAdmin $admin */
        $admin = service('roleAdmin');
        $out = [];
        foreach ($admin->all() as $r) {
            $out[] = ['key' => $r['key'], 'name' => $r['name'], 'builtin' => $r['builtin'], 'permissions' => $r['perms'], 'users' => $r['users']];
        }

        return ApiResponse::json($this->response, ['data' => $out]);
    }

    /** GET /api/v1/security: whether sign-in is on, and the sign-in policy. */
    public function policy(): ResponseInterface
    {
        $settings = $this->settings();
        $policy = [];
        foreach (self::POLICY as $key) {
            $policy[$key] = $settings->get($key);
        }

        return ApiResponse::json($this->response, ['sign_in_enabled' => $settings->signInEnabled(), 'policy' => $policy]);
    }
}
