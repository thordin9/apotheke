<?php

declare(strict_types=1);

namespace Apotheke\Core\Security;

/**
 * Who is acting, as far as the admin-only actions care (legacy
 * actor_is_admin()): granting admin, turning sign-in off, changing an admin
 * account, and importing or restoring sign-in settings (SEC-M1).
 */
interface ActorGate
{
    /** An admin account, or the command line. */
    public function isAdmin(): bool;
}
