<?php

declare(strict_types=1);

namespace Apotheke\Auth\Commands;

use Apotheke\Core\Security\ActorGate;
use Closure;

/**
 * The command line as the actor of the account forms: always an admin
 * (legacy actor_is_admin() on the CLI). The first time it is asked, it runs
 * legacy auth_enabled()'s first-admin step, as legacy's current_user() does
 * once per process, so an install with sign-in on, no accounts and
 * APP_PASSWORD gets the account "admin" at the same point as on legacy.
 */
final class CommandLineActor implements ActorGate
{
    private bool $asked = false;

    /** @param Closure():void $firstAdmin */
    public function __construct(private readonly Closure $firstAdmin)
    {
    }

    public function isAdmin(): bool
    {
        if (!$this->asked) {
            $this->asked = true;
            ($this->firstAdmin)();
        }

        return true;
    }
}
