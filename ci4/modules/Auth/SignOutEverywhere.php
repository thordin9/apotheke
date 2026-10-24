<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Exceptions\UserError;

/**
 * "Sign out everywhere" (SEC-N6, legacy user_sign_out_everywhere() on main
 * #84). The account gets a new session epoch, so every session still holding
 * the old one is signed out on its next request. The password is not
 * touched, and there is no session-store scan, so it works the same on any
 * session storage. The caller signs the current session back in with the
 * returned epoch (SessionGate::signIn() with keepSignIn) when this device
 * should stay signed in; `bin/settings.php user signout NAME` does not.
 */
final class SignOutEverywhere
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return string the account's new epoch */
    public function run(int $userId): string
    {
        if ($this->users->find($userId) === null) {
            throw new UserError('User not found.', 404);
        }

        return $this->users->renewSessionEpoch($userId);
    }
}
