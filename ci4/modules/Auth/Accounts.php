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

    /**
     * A hash at PHP's default cost of a random value nobody kept: what an
     * unknown name is checked against. SignInAccountsTest keeps it at the default.
     */
    public const DUMMY_HASH = '$2y$12$rGbXD6ftSShKrjjQ/V0Mp.DKflUCQZ2CgnF1mN0dY.pmyaxcHRNr2';

    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly Clock $clock,
        private readonly SignInPace $pace = new SignInPace(),
    ) {
    }

    /** The failed sign-in deadline in milliseconds (Config\SignIn, clamped). */
    public function failDeadlineMs(): int
    {
        return $this->pace->deadlineMs;
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
     * older options is replaced on a good password.
     *
     * Stricter than legacy: an unknown name is checked against DUMMY_HASH (one
     * verify at PHP's default cost, which never throws), a password with a
     * NUL byte never matches (no stored password can contain one), and an
     * error from the hashing functions counts as a wrong password. The
     * hashing time alone still depends on the stored hash (an account carried
     * over from main keeps its older cost until its next sign-in), so the
     * sign-in page goes through signIn(), which pads every failure to a fixed
     * deadline; legacy waits a flat 400 ms after the check.
     *
     * @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}|null
     */
    public function verify(string $name, #[\SensitiveParameter] string $password): ?array
    {
        $user = $this->users->findByName($name);
        try {
            if ($user === null) {
                password_verify($password, self::DUMMY_HASH);

                return null;
            }
            if (!password_verify($password, $user['password_hash']) || str_contains($password, "\0")) {
                return null;
            }
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $this->users->rehash($user['id'], password_hash($password, PASSWORD_DEFAULT));

                return $this->users->find($user['id']) ?? $user;
            }
        } catch (\ValueError) {
            return null;
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

    /** Legacy user_name_valid(); stricter: a null character anywhere, before trimming, is refused (N-261). */
    public function nameValid(string $name, ?int $except = null): string
    {
        if (str_contains($name, "\0")) {
            throw new UserError('User names use letters, digits and . _ @ - (at most ' . self::USERNAME_MAX . ' characters, no spaces).', 422);
        }
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
        if (str_contains($new, "\0")) {
            // bcrypt stops at a NUL byte and reads only the first 72 bytes (docs/control-panel.md)
            throw new UserError('Passwords cannot contain a null character.', 422);
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
     * Legacy user_check_current(): the signed-in account's current password,
     * limited like a sign-in: the per-address cap first, then that account
     * from this address (429); a wrong one is 403 at SignInPace's deadline,
     * as a failed sign-in (L-310). A right one clears that
     * account's sign-in attempts and takes back only its own per-address try.
     *
     * @param array{username:string} $me
     */
    public function checkCurrent(array $me, #[\SensitiveParameter] string $current, RateLimiter $limiter): void
    {
        $started = $this->pace->start();
        $mine = $limiter->hit('login_address');   // the same per-address cap as the sign-in form
        $limiter->hit('login', $me['username']);
        if ($this->signIn($started, $me['username'], $current) === null) {   // a failure answers at the sign-in deadline (L-310)
            throw new UserError('The current password is not correct.', 403);
        }
        $limiter->clear('login', $me['username']);
        $limiter->forget($mine);
    }

    /**
     * Legacy POST /account: checkCurrent(), then user_set_password(), which
     * renews the session epoch. Returns the new epoch for the session that
     * stays signed in.
     *
     * @param array{id:int,username:string} $me
     */
    public function changeOwnPassword(array $me, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new, #[\SensitiveParameter] string $confirm, RateLimiter $limiter): string
    {
        $this->checkCurrent($me, $current, $limiter);
        if ($this->users->find($me['id']) === null) {
            throw new UserError('User not found.', 404);
        }
        $new = $this->passwordValid($new, $confirm);

        return $this->users->setPasswordHash($me['id'], password_hash($new, PASSWORD_DEFAULT));
    }

    /**
     * Legacy auth_enabled() on an install without accounts: with sign-in on,
     * APP_PASSWORD becomes the password of the account "admin". A blank or
     * too short APP_PASSWORD, or one with a null character, stops (legacy
     * app_password_problem(); the message never contains the value).
     */
    public function ensureFirstAdmin(bool $signInSetting, #[\SensitiveParameter] string $appPassword): void
    {
        if (!$signInSetting || $appPassword === '' || $this->users->count() > 0) {
            return;
        }
        if (str_contains($appPassword, "\0")) {
            throw new RuntimeException('APP_PASSWORD cannot contain a null character.');
        }
        if (trim($appPassword) === '' || mb_strlen($appPassword) < self::PASSWORD_MIN) {
            throw new RuntimeException('APP_PASSWORD is too short: use at least ' . self::PASSWORD_MIN . ' characters (not only spaces), or leave it empty.');
        }
        $this->users->create('admin', password_hash($appPassword, PASSWORD_DEFAULT), 'admin', 'active', $this->clock->now());
    }
}
