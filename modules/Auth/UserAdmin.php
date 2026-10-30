<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;

/**
 * Managing accounts on Users & sign-in (legacy users.php: user_create(),
 * user_approve(), user_set_role(), user_set_password(), user_rename(),
 * user_delete()). Every change to an admin account, and granting the admin
 * role, needs an admin (AdminGuard, SEC-M1); the install always keeps one
 * active admin. Checks run in legacy's order, so the same request gets the
 * same message. Writes go through UserRepository (Core\Database\Writes).
 * Holds no state.
 */
final class UserAdmin
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly Accounts $accounts,
        private readonly AdminGuard $guard,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array{id:int,username:string,role:string,status:string,created_at:string,last_login_at:?string}> */
    public function all(): array
    {
        return $this->users->all();
    }

    /** @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}|null */
    public function find(int $id): ?array
    {
        return $this->users->find($id);
    }

    /** @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}|null */
    public function findByName(string $name): ?array
    {
        return $this->users->findByName($name);
    }

    /** Legacy users_count('admin'): active admins. */
    public function activeAdmins(): int
    {
        return $this->users->countActive('admin');
    }

    /** Legacy user_role_valid(): any role but guest. */
    public function roleValid(string $role): string
    {
        $roles = $this->accountRoles();
        if (!isset($roles[$role])) {
            throw new UserError('Choose a role: ' . implode(', ', array_map(static fn (array $r): string => mb_strtolower($r['name']), $roles)) . '.', 422);
        }

        return $role;
    }

    /** Legacy user_create() (status active). Returns the new id. */
    public function create(string $name, #[\SensitiveParameter] string $password, string $role, #[\SensitiveParameter] ?string $confirm = null): int
    {
        $name = $this->accounts->nameValid($name);
        $role = $this->roleValid($role);
        $this->guard->grant($role);
        $password = $this->accounts->passwordValid($password, $confirm);

        return $this->users->create($name, password_hash($password, PASSWORD_DEFAULT), $role, 'active', $this->clock->now());
    }

    /** Legacy user_approve(). */
    public function approve(int $id): void
    {
        $user = $this->get($id);
        $this->guard->target($user);
        $this->users->approve($id);
    }

    /**
     * Legacy user_set_role(): refuses to leave the install without an active
     * admin. The last-admin check and the write run in one transaction after
     * the active admins are locked (L-110), as legacy.
     */
    public function setRole(int $id, string $role): void
    {
        $user = $this->get($id);
        $role = $this->roleValid($role);
        $this->guard->target($user);
        $this->guard->grant($role);
        $this->users->atomically(function () use ($id, $role): void {
            $user = $this->lockAdmins($id);
            if ($user['role'] === 'admin' && $user['status'] === 'active' && $role !== 'admin' && $this->activeAdmins() <= 1) {
                throw new UserError('“' . $user['username'] . '” is the only admin – make someone else an admin first.', 409);
            }
            $this->users->setRole($id, $role);
        });
    }

    /**
     * Legacy user_set_password(): every session of that account is signed
     * out (a new session epoch). Returns the new epoch, for the session that
     * changed its own password and stays signed in.
     */
    public function setPassword(int $id, #[\SensitiveParameter] string $password, #[\SensitiveParameter] ?string $confirm = null): string
    {
        $user = $this->get($id);
        $this->guard->target($user);
        $password = $this->accounts->passwordValid($password, $confirm);

        return $this->users->setPasswordHash($id, password_hash($password, PASSWORD_DEFAULT));
    }

    /** Legacy user_rename(). */
    public function rename(int $id, string $name): void
    {
        $user = $this->get($id);
        $this->guard->target($user);
        $name = $this->accounts->nameValid($name, $id);
        $this->users->rename($id, $name);
    }

    /** Legacy user_delete(): the last active admin stays (checked under the admin lock, L-110). */
    public function delete(int $id): void
    {
        $user = $this->get($id);
        $this->guard->target($user);
        $this->users->atomically(function () use ($id): void {
            $user = $this->lockAdmins($id);
            if ($user['role'] === 'admin' && $user['status'] === 'active' && $this->activeAdmins() <= 1) {
                throw new UserError('“' . $user['username'] . '” is the only admin and cannot be deleted.', 409);
            }
            $this->users->delete($id);
        });
    }

    /** @return array<string,array{name:string,perms:list<string>}> legacy account_roles(): every role but guest */
    public function accountRoles(): array
    {
        return array_filter($this->roles->all(), static fn (string $key): bool => $key !== 'guest', ARRAY_FILTER_USE_KEY);
    }

    /**
     * Legacy users_lock_admins(), inside the transaction: lock the active
     * admins, then read the account again.
     *
     * @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}
     */
    private function lockAdmins(int $id): array
    {
        $this->users->lockActiveAdmins();

        return $this->get($id);
    }

    /** @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string} */
    private function get(int $id): array
    {
        return $this->users->find($id) ?? throw new UserError('User not found.', 404);
    }
}
