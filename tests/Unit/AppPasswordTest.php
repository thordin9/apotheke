<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** APP_PASSWORD meets PASSWORD_MIN like any other password (hardening, #75 QA L-2). The value is never printed. */
final class AppPasswordTest extends TestCase
{
    private string $data = '';

    protected function setUp(): void
    {
        $this->data = sys_get_temp_dir() . '/apotheke-apppw-' . bin2hex(random_bytes(4));
        mkdir($this->data, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->data . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->data);
    }

    public function testProblem(): void
    {
        $this->assertNull(app_password_problem(''), 'not set');
        $this->assertNull(app_password_problem('eight-ch'));
        $this->assertNull(app_password_problem('ääääääää'), 'characters, not bytes');
        foreach (['x', 'seven-c', str_repeat(' ', 12), "\t\t\t\t\t\t\t\t"] as $pw) {
            $msg = app_password_problem($pw);
            $this->assertNotNull($msg, var_export($pw, true));
            $this->assertStringContainsString('at least ' . PASSWORD_MIN . ' characters', $msg);
        }
        $this->assertStringNotContainsString('seven-c', (string)app_password_problem('seven-c'));
    }

    public function testStartCheckRefusesAShortPasswordOnANewInstall(): void
    {
        $run = $this->php(['bin/settings.php', 'auth', 'status'], 'Short1');
        $this->assertSame(4, $run['code'], $run['err']);
        $this->assertStringContainsString('APP_PASSWORD is too short', $run['err']);
        $this->assertStringNotContainsString('Short1', $run['out'] . $run['err']);
        $this->assertFileDoesNotExist($this->data . '/catalog.sqlite');

        $this->assertSame(4, $this->php(['bin/settings.php', 'auth', 'status'], str_repeat(' ', 10))['code'], 'spaces only');
        $this->assertSame(0, $this->php(['bin/settings.php', 'auth', 'status'], 'Long-enough-1')['code']);
    }

    public function testSeedingRefusesAShortPassword(): void
    {
        $run = $this->php(['bin/migrate.php'], 'Short1');
        $this->assertSame(1, $run['code'], $run['out'] . $run['err']);
        $this->assertStringContainsString('APP_PASSWORD is too short', $run['err']);
        $this->assertStringNotContainsString('Short1', $run['out'] . $run['err']);
        $this->assertStringNotContainsString('Stack trace', $run['err']);
        $this->assertSame(3, $this->php(['bin/settings.php', 'auth', 'status'], '')['code'], 'no password was stored');
    }

    public function testAShortPasswordIsIgnoredOnceAnAccountExists(): void
    {
        $this->assertSame(0, $this->php(['bin/migrate.php'], 'Long-enough-1')['code']);
        $this->assertSame(0, $this->php(['bin/settings.php', 'auth', 'status'], 'Short1')['code'], 'the account decides, not APP_PASSWORD');
    }

    public function testTheFirstRequestCreatesAdminOnlyFromALongEnoughPassword(): void
    {
        $this->assertSame(0, $this->php(['bin/migrate.php'], 'Long-enough-1')['code']);
        // sign-in on and no account yet: auth_enabled() makes "admin" from APP_PASSWORD
        $code = 'require "src/bootstrap.php"; require "src/items.php"; db()->exec("DELETE FROM users"); try { echo auth_enabled() ? "on" : "off"; }'
            . ' catch (AppPasswordError $e) { echo "refused: ", $e->getMessage(); } echo " ", users_count();';
        $run = $this->php(['-r', $code], 'Short1');
        $this->assertStringStartsWith('refused: APP_PASSWORD is too short', $run['out'], $run['err']);
        $this->assertStringEndsWith(' 0', $run['out'], 'no account was created');
        $this->assertStringNotContainsString('Short1', $run['out'] . $run['err']);
        $run = $this->php(['-r', $code], 'Long-enough-2');
        $this->assertSame('on 1', $run['out'], $run['err']);
    }

    /** @param list<string> $args @return array{out:string,err:string,code:int} */
    private function php(array $args, string $appPassword): array
    {
        $root = dirname(__DIR__, 2);
        $env = [
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $this->data,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
            'DB_DRIVER' => 'sqlite',
            'APP_PASSWORD' => $appPassword,
        ];
        $cmd = [PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-d', 'display_errors=stderr'];
        $proc = proc_open(array_merge($cmd, [$args[0] === '-r' ? '-r' : $root . '/' . $args[0]], array_slice($args, 1)),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['out' => $out, 'err' => $err, 'code' => proc_close($proc)];
    }
}
