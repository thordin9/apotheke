<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Security\ActorGate;

/**
 * The admin-only account actions of main #71 (SEC-M1): only an admin, or the
 * command line, grants the admin role, changes an admin account (role, name,
 * password, approval, deletion) or turns sign-in off. The Users controllers and
 * the account CLI forms call these before they write.
 */
final class AdminGuard
{
    public function __construct(private readonly ActorGate $actor)
    {
    }

    /** @param array{role:string} $target the account about to change */
    public function target(array $target): void
    {
        if ($target['role'] === 'admin' && !$this->actor->isAdmin()) {
            throw new UserError('Only an admin can change an admin account.', 403);
        }
    }

    public function grant(string $role): void
    {
        if ($role === 'admin' && !$this->actor->isAdmin()) {
            throw new UserError('Only an admin can grant the admin role.', 403);
        }
    }

    public function signInOff(): void
    {
        if (!$this->actor->isAdmin()) {
            throw new UserError('Only an admin can turn sign-in off.', 403);
        }
    }
}
