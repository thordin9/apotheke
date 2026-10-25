<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Sign-in pacing (Auth\SignInPace). A failed sign-in answers this many
 * milliseconds after the attempt began, whatever the account and its stored
 * hash. Clamped to 400..10000. Override with the environment variable
 * `signin.failDeadlineMs` in `.env`, or
 * `signin_failDeadlineMs` in the environment.
 */
class SignIn extends BaseConfig
{
    public int $failDeadlineMs = 1000;
}
