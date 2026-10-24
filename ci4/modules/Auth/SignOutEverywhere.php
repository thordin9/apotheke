<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Exceptions\UserError;

/**
 * "Sign out everywhere" (SEC-N6). The account's password is hashed again with a
 * new salt: the password stays the same, but the session epoch changes, so
 * every other session of the account is signed out on its next request. No
 * schema change and no session-store scan, so it works the same on any
 * session storage. The caller stores the returned epoch in the current
 * session (and renews its id) to keep this one signed in.
 */
final class SignOutEverywhere
{
    public function __construct(private readonly UserRepository $users, private readonly SessionEpoch $epoch = new SessionEpoch())
    {
    }

    /** @return string the account's new epoch */
    public function run(int $userId, string $password): string
    {
        $user = $this->users->find($userId);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw new UserError('That password is not right.', 403);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->users->setPasswordHash($userId, $hash);

        return $this->epoch->of(['id' => $userId, 'password_hash' => $hash]);
    }
}
