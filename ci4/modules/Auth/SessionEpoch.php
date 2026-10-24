<?php

declare(strict_types=1);

namespace Apotheke\Auth;

/**
 * The value a signed-in session must hold to stay valid (legacy user_epoch()
 * on main, migration 0028). The account's stored session_epoch wins;
 * without one it is the password-hash fingerprint sessions held before that
 * column existed, which is also what 0028 fills in, so an upgrade signs
 * nobody out. A new password or "sign out everywhere" stores a new value
 * (UserRepository), which signs out every session holding the old one.
 */
final class SessionEpoch
{
    /** @param array{id:int,password_hash:string,session_epoch?:string} $user */
    public function of(array $user): string
    {
        $stored = $user['session_epoch'] ?? '';
        if ($stored !== '') {
            return $stored;
        }

        return substr(hash('sha256', $user['password_hash'] . '|' . $user['id']), 0, 16);
    }

    /** A new random epoch (legacy user_renew_epoch()). */
    public function fresh(): string
    {
        return bin2hex(random_bytes(8));
    }
}
