<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\Text;
use Apotheke\Settings\SettingsService;

/**
 * The roles editor (legacy roles.php: role_create(), role_update(),
 * role_delete(), user_managers_count()). Admin always has every permission
 * and cannot change; built-in roles cannot be deleted; the guest role never
 * gets users, settings or backup; and no change may leave the install
 * without an active account that can manage users. Checks run in legacy's
 * order. An update or delete checks and writes in one transaction that holds
 * the active admin rows, so it runs one after a concurrent admin change
 * (L-110). Holds no state.
 */
final class RoleAdmin
{
    public const KEY_MAX = 30;

    public const NAME_MAX = 60;

    /** What the guest role can never have (legacy role_update()). */
    public const NOT_FOR_GUESTS = ['users', 'settings', 'backup'];

    public function __construct(
        private readonly RoleRepository $roles,
        private readonly UserRepository $users,
        private readonly SettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string,array{key:string,name:string,builtin:bool,users:int,perms:list<string>}> */
    public function all(): array
    {
        return $this->roles->listed();
    }

    /** Legacy role_key_from(). */
    public static function keyFrom(string $name): string
    {
        $key = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));

        return substr($key, 0, self::KEY_MAX);
    }

    /**
     * Legacy role_validate_perms(): only known permission keys, in PERMISSIONS order.
     *
     * @param array<array-key,mixed> $perms
     * @return list<string>
     */
    public static function validPerms(array $perms): array
    {
        foreach ($perms as $p) {
            if (!is_string($p) || !isset(Permissions::ALL[$p])) {
                throw new UserError('Unknown permission “' . (is_string($p) ? $p : '?') . '”.', 422);
            }
        }

        return Permissions::clean('', $perms);
    }

    /**
     * Legacy user_managers_count(): active accounts that may manage users (an
     * admin, or a role with the users permission), with $override as the
     * permissions some roles would have after a change.
     *
     * @param array<string,list<string>>|null $override
     */
    public function managers(?array $override = null): int
    {
        $roles = $this->roles->listed();
        $n = 0;
        foreach ($this->users->activeRoles() as $role) {
            $perms = $override !== null && array_key_exists($role, $override) ? $override[$role] : ($roles[$role]['perms'] ?? []);
            if ($role === 'admin' || in_array('users', $perms, true)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Legacy role_create(); returns the new key.
     *
     * @param list<string> $perms
     */
    public function create(string $name, array $perms): string
    {
        $name = Text::cleanLine($name, self::NAME_MAX);
        if ($name === '') {
            throw new UserError('Enter a name for the role.', 422);
        }
        $key = self::keyFrom($name);
        if ($key === '') {
            throw new UserError('Use letters or digits in the role name.', 422);
        }
        foreach ($this->roles->listed() as $r) {
            if ($r['key'] === $key || mb_strtolower($r['name']) === mb_strtolower($name)) {
                throw new UserError('A role “' . $r['name'] . '” exists already.', 409);
            }
        }
        $this->roles->create($key, $name, self::validPerms($perms), $this->clock->now());

        return $key;
    }

    /**
     * Legacy role_update(): a custom role's name and any role's permissions
     * but admin's. $name null keeps the name (built-in roles always do).
     *
     * @param list<string> $perms
     */
    public function update(string $key, ?string $name, array $perms): void
    {
        $this->users->atomically(function () use ($key, $name, $perms): void {
            $this->users->holdActiveAdmins();
            $this->updateLocked($key, $name, $perms);
        });
    }

    /** @param list<string> $perms */
    private function updateLocked(string $key, ?string $name, array $perms): void
    {
        $roles = $this->roles->listed();
        $r = $roles[$key] ?? throw new UserError('Role not found.', 404);
        if ($key === 'admin') {
            throw new UserError('The admin role always has every permission.', 409);
        }
        $perms = self::validPerms($perms);
        if ($key === 'guest') {
            $perms = array_values(array_diff($perms, self::NOT_FOR_GUESTS));
        }
        if (in_array('users', $r['perms'], true) && !in_array('users', $perms, true) && $this->managers() > 0 && $this->managers([$key => $perms]) === 0) {
            throw new UserError('Nobody could manage users any more – keep the Users permission or make someone an admin first.', 409);
        }
        $name = $name === null || $r['builtin'] ? $r['name'] : Text::cleanLine($name, self::NAME_MAX);
        if ($name === '') {
            throw new UserError('Enter a name for the role.', 422);
        }
        foreach ($roles as $o) {
            if ($o['key'] !== $key && mb_strtolower($o['name']) === mb_strtolower($name)) {
                throw new UserError('A role “' . $o['name'] . '” exists already.', 409);
            }
        }
        $this->roles->update($key, $name, $perms);
    }

    /** Legacy role_delete(): a custom role; its accounts move to $moveTo. */
    public function delete(string $key, string $moveTo = 'viewer'): void
    {
        $register = $this->users->atomically(function () use ($key, $moveTo): mixed {
            $this->users->holdActiveAdmins();

            return $this->deleteLocked($key, $moveTo);
        });
        // After the commit, as legacy.
        if ($register === $key) {
            $this->settings->store(['auth_register_role' => 'viewer']);
        }
    }

    /** @return mixed the auth_register_role setting as it was before the delete */
    private function deleteLocked(string $key, string $moveTo): mixed
    {
        $roles = $this->roles->listed();
        $r = $roles[$key] ?? throw new UserError('Role not found.', 404);
        if ($r['builtin']) {
            throw new UserError('Built-in roles cannot be deleted.', 409);
        }
        if ($moveTo === $key || $moveTo === 'guest' || !isset($roles[$moveTo])) {
            throw new UserError('Choose another role for its accounts.', 422);
        }
        if ($this->managers() > 0 && $this->managers([$key => $roles[$moveTo]['perms']]) === 0) {
            throw new UserError('Nobody could manage users any more – make someone an admin first.', 409);
        }
        // Read before the delete: afterwards the setting no longer validates (legacy reads its per-request copy).
        $register = $this->settings->get('auth_register_role');
        $this->roles->delete($key, $moveTo);

        return $register;
    }
}
