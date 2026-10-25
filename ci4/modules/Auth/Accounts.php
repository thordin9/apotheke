<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use RuntimeException;

/**
 * Accounts for the sign-in pages (legacy src/users.php): checking a password,
 * self-registration, a new password for your own account and the first admin
 * from APP_PASSWORD. Messages and status codes are legacy's. Every write goes
 * through UserRepository (Core\Database\Writes). Holds no state.
 */
final class Accounts
{
    public const USERNAME_MAX = 40;
    public const PASSWORD_MIN = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly Clock $clock,
        private readonly SignInPace $pace = new SignInPace(),
    ) {
    }

    /** The start of a sign-in attempt, for signIn(). */
    public function startSignIn(): int
    {
        return $this->pace->start();
    }

    /**
     * A sign-in attempt begun at $started (startSignIn()): verify(), and on
     * any failure wait until SignInPace's deadline after $started, so an
     * unknown name, a wrong password on a current hash and one on an older,
     * cheaper hash all answer at the same time.
     *
     * @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}|null
     */
    public function signIn(int $started, string $name, #[\SensitiveParameter] string $password): ?array
    {
        $user = $this->verify($name, $password);
        if ($user === null) {
            $this->pace->pad($started);
        }

        return $user;
    }

    /**
     * Legacy user_verify(): the account for this name and password, or null.
     * Unknown names and wrong passwords give the same null. A hash made with
     * older options is replaced on a good password. An unknown name still
     * spends one hash at PHP's default cost.
     *
     * The hashing time alone depends on the stored hash (an account carried
     * over from main keeps its older cost until its next sign-in), so the
     * sign-in page goes through signIn(), which pads every failure to a fixed
     * deadline. Stricter than legacy, which waits a flat 400 ms after the check.
     *
     * @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}|null
     */
    public function verify(string $name, #[\SensitiveParameter] string $password): ?array
    {
        $user = $this->users->findByName($name);
        if ($user === null) {
            password_hash($password, PASSWORD_DEFAULT);

            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->rehash($user['id'], password_hash($password, PASSWORD_DEFAULT));

            return $this->users->find($user['id']) ?? $user;
        }

        return $user;
    }

    /**
     * Legacy login() after user_verify(): a pending account may not sign in
     * (403); an active one gets its sign-in time.
     *
     * @param array{id:int,status:string} $user
     */
    public function admit(array $user): void
    {
        if ($user['status'] !== 'active') {
            throw new UserError('Your account is waiting for an admin’s approval.', 403);
        }
        $this->users->touchLastLogin($user['id'], $this->clock->now());
    }

    /** Legacy user_name_valid(). */
    public function nameValid(string $name, ?int $except = null): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new UserError('Enter a user name.', 422);
        }
        if (mb_strlen($name) > self::USERNAME_MAX || preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._@-]*$/uD', $name) !== 1) {
            throw new UserError('User names use letters, digits and . _ @ - (at most ' . self::USERNAME_MAX . ' characters, no spaces).', 422);
        }
        $other = $this->users->findByName($name);
        if ($other !== null && $other['id'] !== $except) {
            throw new UserError('The user name “' . $name . '” is taken.', 422);
        }

        return $name;
    }

    /** Legacy user_password_valid(). */
    public function passwordValid(#[\SensitiveParameter] string $new, #[\SensitiveParameter] ?string $confirm = null): string
    {
        if (mb_strlen($new) < self::PASSWORD_MIN) {
            throw new UserError('Use at least ' . self::PASSWORD_MIN . ' characters for the password.', 422);
        }
        if (strlen($new) > 200) {
            throw new UserError('That password is too long.', 422);
        }
        if ($confirm !== null && !hash_equals($new, $confirm)) {
            throw new UserError('The two passwords do not match.', 422);
        }

        return $new;
    }

    /** Legacy user_register(): the role new accounts get (never admin; an unknown role is viewer). */
    public function registerRole(string $configured): string
    {
        $roles = $this->roles->all();

        return isset($roles[$configured]) && $configured !== 'guest' && $configured !== 'admin' ? $configured : 'viewer';
    }

    /**
     * Legacy user_register() and user_create(): name, then password; pending
     * when an admin must approve.
     *
     * @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}
     */
    public function register(string $name, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirm, string $configuredRole, bool $approval): array
    {
        $role = $this->registerRole($configuredRole);
        $name = $this->nameValid($name);
        $password = $this->passwordValid($password, $confirm);
        $id = $this->users->create($name, password_hash($password, PASSWORD_DEFAULT), $role, $approval ? 'pending' : 'active', $this->clock->now());

        return $this->users->find($id) ?? throw new RuntimeException('The new account could not be read back.');
    }

    /**
     * Legacy POST /account: the current password must match (403), then
     * user_set_password(), which renews the session epoch. Returns the new
     * epoch for the session that stays signed in.
     *
     * @param array{id:int,username:string} $me
     */
    public function changeOwnPassword(array $me, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new, #[\SensitiveParameter] string $confirm): string
    {
        if ($this->verify($me['username'], $current) === null) {
            throw new UserError('The current password is not correct.', 403);
        }
        if ($this->users->find($me['id']) === null) {
            throw new UserError('User not found.', 404);
        }
        $new = $this->passwordValid($new, $confirm);

        return $this->users->setPasswordHash($me['id'], password_hash($new, PASSWORD_DEFAULT));
    }

    /**
     * Legacy auth_enabled() on an install without accounts: with sign-in on,
     * APP_PASSWORD becomes the password of the account "admin". A blank or
     * too short APP_PASSWORD stops (the message never contains the value).
     */
    public function ensureFirstAdmin(bool $signInSetting, #[\SensitiveParameter] string $appPassword): void
    {
        if (!$signInSetting || $appPassword === '' || $this->users->count() > 0) {
            return;
        }
        if (trim($appPassword) === '' || mb_strlen($appPassword) < self::PASSWORD_MIN) {
            throw new RuntimeException('APP_PASSWORD is too short: use at least ' . self::PASSWORD_MIN . ' characters (not only spaces), or leave it empty.');
        }
        $this->users->create('admin', password_hash($appPassword, PASSWORD_DEFAULT), 'admin', 'active', $this->clock->now());
    }
}
