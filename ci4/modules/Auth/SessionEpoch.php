<?php

declare(strict_types=1);

namespace Apotheke\Auth;

/**
 * The fingerprint a signed-in session remembers (legacy user_epoch()). A new
 * password hash for the account changes it, which signs out every session
 * that still holds the old one.
 */
final class SessionEpoch
{
    /** @param array{id:int,password_hash:string} $user */
    public function of(array $user): string
    {
        return substr(hash('sha256', $user['password_hash'] . '|' . $user['id']), 0, 16);
    }
}
