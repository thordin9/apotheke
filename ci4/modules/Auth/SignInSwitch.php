<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Settings\SettingsService;

/**
 * Sign-in on or off (legacy auth_turn_on() and auth_turn_off()). On needs an
 * active admin account; off needs an admin (or the command line) as the
 * actor (AdminGuard). Holds no state.
 */
final class SignInSwitch
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly UserRepository $users,
        private readonly AdminGuard $guard,
    ) {
    }

    public function on(): void
    {
        if ($this->users->countActive('admin') === 0) {
            throw new UserError('Create an admin account first.', 422);
        }
        $this->settings->store(['auth_enabled' => true]);
    }

    public function off(): void
    {
        $this->guard->signInOff();
        $this->settings->store(['auth_enabled' => false]);
    }
}
