<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Accounts;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Accounts::appPasswordProblem(), legacy app_password_problem(): the same
 * PASSWORD_MIN as any password, counted in characters, never only spaces, no
 * null character, and the message never contains the value.
 */
final class AppPasswordProblemTest extends CIUnitTestCase
{
    private const SHORT = 'APP_PASSWORD is too short: use at least 8 characters (not only spaces), or leave it empty.';

    public function testTheLegacyRule(): void
    {
        $this->assertNull(Accounts::appPasswordProblem(''), 'not set');
        $this->assertNull(Accounts::appPasswordProblem('eight-ch'));
        $this->assertNull(Accounts::appPasswordProblem('ääääääää'), 'characters, not bytes');
        $this->assertNull(Accounts::appPasswordProblem(' padded  '), 'spaces count when there is more');
        foreach (['x', 'seven-c', str_repeat(' ', 12), "\t\t\t\t\t\t\t\t", 'äääääää'] as $password) {
            $this->assertSame(self::SHORT, Accounts::appPasswordProblem($password), var_export($password, true));
        }
        $this->assertSame('APP_PASSWORD cannot contain a null character.', Accounts::appPasswordProblem("long-enough\0"));
        $this->assertSame(8, Accounts::PASSWORD_MIN);
    }
}
