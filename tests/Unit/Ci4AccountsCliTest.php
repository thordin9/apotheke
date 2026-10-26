<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * CLI goldens (PLAN step 7, PR B): the account and sign-in forms of
 * `php bin/settings.php` (users, user …, roles, auth, password) on legacy and
 * with APOTHEKE_APP=ci4 (spark apotheke:accounts), on twin databases of the
 * configured driver. Every step compares stdout, stderr and the exit code,
 * then the users, roles and settings tables (a password by which of the
 * test's passwords it verifies, a session epoch by whether it changed). Also:
 * the first admin from APP_PASSWORD, a too short APP_PASSWORD, failed writes
 * (nothing changed), and that no password reaches the output.
 */
final class Ci4AccountsCliTest extends TestCase
{
    private const CLOCK = '2020-06-15 12:00:00';

    private const PASSWORDS = ['long-enough', 'bob-password', 'bob-password-2', 'pal-password', 'admin-password', 'admin-password-2', 'placeholder-app-pw', 'root-password'];

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        if (!is_file(ROOT . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            try {
                testdb_drop($dir);
            } catch (Throwable) {
            }
            rrmdir($dir);
        }
    }

    public function testAccountFormsMatchLegacy(): void
    {
        [$left, $right] = $this->twins('acli');
        $sql = static function (string $statement) use ($left, $right): void {
            foreach ([$left, $right] as $dir) {
                testdb($dir)->exec($statement);
            }
        };
        $this->runBoth($left, $right, [[['users']], [['roles']], [['auth']]]);
        $sql("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('desk', 'Help desk', '[\"view\",\"users\"]', 0, '2026-01-01 00:00:00')");
        $this->runBoth($left, $right, [
            [['user', 'add', 'ada', 'admin', 'long-enough']],
            [['user', 'add'], "long-enough\nlong-enough\n"],
            [['user', 'add', 'bob', 'editor'], "bob-password\nbob-password\n"],
            [['user', 'add', 'bo', 'editor'], "bob-password\nother-password\n"],
            [['user', 'add', 'bo', 'editor'], ''],
            [['user', 'add', 'carl', 'viewer', 'short']],
            [['user', 'add', 'carl', 'viewer', '        ']],
            [['user', 'add', 'dave', 'guest', 'long-enough']],
            [['user', 'add', 'dave', 'nope', 'long-enough']],
            [['user', 'add', 'dave', '', 'long-enough']],
            [['user', 'add', ' ADA ', 'viewer', 'long-enough']],
            [['user', 'add', 'a b<x>', 'viewer', 'long-enough']],
            [['user', 'add', 'dee', 'desk', 'long-enough']],
            [['user', 'add', 'pal', 'admin', 'pal-password']],
            [['users']],
        ]);
        // N-111: a pending admin may be demoted while one active admin remains, as on legacy.
        $sql("UPDATE users SET status = 'pending' WHERE username = 'pal'");
        $this->runBoth($left, $right, [
            [['users']],
            [['user', 'role', 'pal', 'editor']],
        ]);
        $this->assertSame(['editor', 'pending'], array_values(testdb($right)->query("SELECT role, status FROM users WHERE username = 'pal'")->fetch(PDO::FETCH_ASSOC)), 'N-111: demoted');
        $this->assertSame(1, (int)testdb($right)->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn());
        $this->runBoth($left, $right, [
            [['user', 'role', 'pal', 'admin']],
            [['user', 'role', 'ada', 'editor']],
            [['user', 'role', 'ada', 'admin']],
            [['user', 'delete', 'ada']],
            [['user', 'approve', 'pal']],
            [['user', 'approve', 'nobody']],
            [['user', 'role', 'bob', 'viewer']],
            [['user', 'role', 'bob', 'guest']],
            [['user', 'role', 'bob']],
            [['user', 'role', 'nobody', 'editor']],
            [['user', 'role', 'ada', 'editor']],
            [['user', 'role', 'pal', 'viewer']],
            [['user', 'password', 'bob', 'bob-password-2']],
            [['user', 'password', 'bob'], "bob-password\nbob-password\n"],
            [['user', 'password', 'BOB', 'short']],
            [['user', 'password', 'nobody'], "bob-password\nbob-password\n"],
            [['user', 'password', 'bob'], "x\ny\n"],
            [['user', 'rename', 'bob', 'Bobby']],
            [['user', 'rename', 'bobby', ' ada ']],
            [['user', 'rename', 'bobby', '']],
            [['user', 'rename', 'bobby', 'BOBBY']],
            [['user', 'signout', 'Bobby']],
            [['user', 'signout', 'nobody']],
            [['user', 'delete', 'nobody']],
            [['user', 'delete', 'dee']],
            [['user', 'frob', 'bobby']],
            [['user']],
            [['users']],
        ]);
        $sql("UPDATE roles SET perms = '[\"view\"]', name = 'Looker' WHERE role_key = 'viewer'");
        $sql("UPDATE roles SET perms = '[]' WHERE role_key = 'editor'");
        $this->runBoth($left, $right, [
            [['roles']], [['roles', 'reset']], [['roles']], [['roles', 'other']],
            [['auth', 'on']], [['auth']], [['auth', 'off']], [['auth', 'frob']], [['auth', 'status']], [['auth', 'on']], [['auth', 'status']],
            [['password', '--off']],
            [['password', 'short']],
            [['password', 'admin-password']],
            [['users']],
        ]);
        $sql("UPDATE users SET role = 'viewer', status = 'pending' WHERE username = 'admin'");
        $this->runBoth($left, $right, [
            [['password'], "admin-password-2\nadmin-password-2\n"],
            [['password'], "admin-password-2\nadmin-password-3\n"],
            [['users']],
            [['user', 'delete', 'admin']],
            [['user', 'delete', 'ada']],
            [['user', 'delete', 'pal']],
            [['password', '--off']],
            [['auth', 'on']],
            [['users']],
        ]);
        // No account left: sign-in cannot go on.
        $sql('DELETE FROM users');
        $this->runBoth($left, $right, [[['auth', 'on']], [['users']], [['auth', 'off']]]);
    }

    /**
     * Sign-in on, no accounts and APP_PASSWORD set: the account "admin" is
     * created where legacy auth_enabled() does (users, or the first admin check).
     */
    public function testTheFirstAdminComesFromAppPassword(): void
    {
        $env = ['APP_PASSWORD' => 'placeholder-app-pw'];
        foreach ([[['users']], [['user', 'add', 'root', 'editor', 'root-password']], [['user', 'add', 'root', 'admin', 'root-password']],
            [['password', '--off']], [['auth', 'off']]] as $i => $first) {
            [$left, $right] = $this->twins('acli-first');
            foreach ([$left, $right] as $dir) {
                $this->cli($dir, ['users'], $dir === $right, null, $env);
                testdb($dir)->exec('DELETE FROM users');
                testdb($dir)->exec("UPDATE settings SET value = 'true' WHERE name = 'auth_enabled'");
            }
            $this->runBoth($left, $right, [$first, [['users']]], $env);
        }
        // A too short APP_PASSWORD stops with its message (never the value).
        [$left, $right] = $this->twins('acli-short');
        foreach ([$left, $right] as $dir) {
            $this->cli($dir, ['users'], $dir === $right);
            testdb($dir)->exec("UPDATE settings SET value = 'true' WHERE name = 'auth_enabled'");
        }
        $this->runBoth($left, $right, [[['users']], [['user', 'add', 'root', 'admin', 'root-password']], [['auth', 'off']]], ['APP_PASSWORD' => 'shortpw']);
        $r = $this->cli($right, ['users'], true, null, ['APP_PASSWORD' => 'shortpw']);
        $this->assertSame([1, "APP_PASSWORD is too short: use at least 8 characters (not only spaces), or leave it empty.\n"], [$r['exit'], $r['stderr']]);
    }

    /**
     * Where CI4 differs on purpose. A failed write exits 1 with a short
     * message and changes nothing; legacy dies with PHP's uncaught exception
     * (exit 255), which can print the call's arguments. A password with a
     * null character is refused (exit 1) on both.
     */
    public function testFailuresExitCleanly(): void
    {
        [$left, $right] = $this->twins('acli-fail');
        foreach ([$left, $right] as $dir) {
            $this->cli($dir, ['user', 'add', 'ada', 'admin', 'long-enough'], $dir === $right);
            $this->cli($dir, ['user', 'add', 'bob', 'editor', 'bob-password'], $dir === $right);
            testdb($dir)->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('desk', 'Help desk', '[\"view\"]', 0, '2026-01-01 00:00:00')");
        }
        $pg = testdb($left)->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $cases = [
            ['users', 'INSERT', ['user', 'add', 'cleo', 'viewer', 'long-enough'], 'Database write failed: users insert'],
            ['users', 'UPDATE', ['user', 'role', 'bob', 'viewer'], 'Database write failed: users role'],
            ['users', 'UPDATE', ['user', 'password', 'bob', 'bob-password-2'], 'Database write failed: users password'],
            ['users', 'UPDATE', ['user', 'rename', 'bob', 'bobby'], 'Database write failed: users rename'],
            ['users', 'UPDATE', ['user', 'approve', 'bob'], 'Database write failed: users approve'],
            ['users', 'UPDATE', ['user', 'signout', 'bob'], 'Database write failed: users session epoch'],
            ['users', 'DELETE', ['user', 'delete', 'bob'], 'Database write failed: users delete'],
            ['roles', 'UPDATE', ['roles', 'reset'], 'Database write failed: roles reset'],
            ['settings', 'INSERT', ['auth', 'on'], 'Could not save the settings.'],
            // The recovery shortcut runs its steps one by one, as legacy: "admin" exists, sign-in stays off.
            ['settings', 'INSERT', ['password', 'admin-password'], null],
        ];
        foreach ($cases as [$table, $op, $args, $message]) {
            foreach ([$left, $right] as $dir) {
                $db = testdb($dir);
                if ($pg) {
                    $db->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
                    $db->exec("CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()");
                } else {
                    $db->exec("CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
                }
            }
            $label = implode(' ', $args);
            $before = [$this->state($left), $this->state($right)];
            $l = $this->cli($left, $args, false);
            $r = $this->cli($right, $args, true);
            $this->assertSame(255, $l['exit'], $label . ': ' . $l['stdout'] . $l['stderr']);
            $this->assertSame(1, $r['exit'], $label);
            $this->assertSame('', $r['stdout'], $label);
            $this->assertSame(($message ?? 'Could not save the settings.') . "\n", $r['stderr'], $label);
            $this->assertStringNotContainsString('-password', $r['stdout'] . $r['stderr'], $label);
            if ($message !== null) {
                $this->assertSame($before[1], $this->state($right), $label . ': nothing changed on CI4');
            }
            $this->assertSame($this->state($left), $this->state($right), $label . ': the same tables as legacy');
            foreach ([$left, $right] as $dir) {
                testdb($dir)->exec($pg ? "DROP TRIGGER apotheke_test_fail ON $table" : 'DROP TRIGGER apotheke_test_fail');
            }
        }
        foreach ([['user', 'add', 'nul', 'editor'], ['user', 'password', 'bob'], ['password']] as $args) {
            $stdin = "long\0enough\nlong\0enough\n";
            $before = [$this->state($left), $this->state($right)];
            $l = $this->cli($left, $args, false, $stdin);
            $r = $this->cli($right, $args, true, $stdin);
            $this->assertSame([1, 'New password: Repeat: ', "Passwords cannot contain a null character.\n"], [$r['exit'], $r['stdout'], $r['stderr']], implode(' ', $args));
            $this->assertSame([$l['exit'], $l['stdout'], $l['stderr']], [$r['exit'], $r['stdout'], $r['stderr']], implode(' ', $args) . ': as legacy');
            $this->assertSame($before, [$this->state($left), $this->state($right)]);
        }
    }

    /** A spark-only check: apotheke:accounts answers unknown forms and auth status like bin/settings.php's dispatcher would not reach it. */
    public function testSparkEntryPoint(): void
    {
        [, $right] = $this->twins('acli-spark');
        $r = $this->proc([PHP_BINARY, ROOT . '/ci4/spark', 'apotheke:accounts', '--no-header', 'frob'], $this->env($right, true), null);
        $this->assertSame([2, "Unknown command. See the comment at the top of bin/settings.php.\n"], [$r['exit'], $r['stderr']]);
        $r = $this->proc([PHP_BINARY, ROOT . '/ci4/spark', 'apotheke:accounts', '--no-header', 'auth', 'status'], $this->env($right, true), null);
        $this->assertSame([1, "Use: auth on|off|status\n"], [$r['exit'], $r['stderr']], 'auth status runs in bin/settings.php, without migrating');
        // A value that starts with "-" reaches the command unchanged.
        $r = $this->proc([PHP_BINARY, ROOT . '/ci4/spark', 'apotheke:accounts', '--no-header', 'user', 'add', 'neo', 'editor', '-long-enough-'], $this->env($right, true), null);
        $this->assertSame([0, "Added neo.\n"], [$r['exit'], $r['stdout']]);
    }

    /**
     * On a terminal (a pty) the typed password is not shown and echo is back
     * on after the command: on success, on a refusal, and (with pcntl) after
     * Ctrl-C at the prompt, which exits 130.
     */
    public function testPasswordPromptsOnATerminal(): void
    {
        $python = trim((string)shell_exec('command -v python3 2>/dev/null'));
        if ($python === '' || !is_readable('/dev/ptmx')) {
            $this->assertFalse((bool)getenv('CI'), 'CI runs the pty test');
            $this->markTestSkipped('python3 and a pty are required');
        }
        [, $dir] = $this->twins('acli-pty');
        $env = $this->env($dir, true);
        $typed = 'pty-typed-secret';
        $other = 'pty-other-secret';

        $r = $this->pty($python, [PHP_BINARY, ROOT . '/bin/settings.php', 'user', 'add', 'bob', 'editor'], $env, [
            ['wait', 'New password: '], ['send', "$typed\n"], ['wait', 'Repeat: '], ['send', "$typed\n"],
        ]);
        $this->assertSame(0, $r['exit'], $r['shown']);
        $this->assertStringContainsString("New password: \r\nRepeat: \r\nAdded bob.", $r['shown'], 'a newline after each hidden line');
        $this->assertTerminalClean($r, [$typed]);

        $r = $this->pty($python, [PHP_BINARY, ROOT . '/bin/settings.php', 'user', 'password', 'bob'], $env, [
            ['wait', 'New password: '], ['send', "$other\n"], ['wait', 'Repeat: '], ['send', "$typed\n"],
        ]);
        $this->assertSame(1, $r['exit'], $r['shown']);
        $this->assertStringContainsString('The passwords do not match.', $r['shown']);
        $this->assertTerminalClean($r, [$typed, $other]);

        $probe = $this->proc([PHP_BINARY, '-r', 'echo (int)function_exists("pcntl_async_signals");'], $env, null);
        if ($probe['stdout'] !== '1') {
            $this->assertFalse((bool)getenv('CI'), 'CI runs the Ctrl-C case');
            $this->markTestSkipped('pcntl is required for the Ctrl-C case');
        }
        $r = $this->pty($python, [PHP_BINARY, ROOT . '/ci4/spark', 'apotheke:accounts', '--no-header', 'password'], $env, [
            ['wait', 'New password: '], ['send', $typed], ['sigint'],
        ]);
        $this->assertSame(130, $r['exit'], $r['shown']);
        $this->assertTerminalClean($r, [$typed]);
    }

    /**
     * @param array{shown:string,exit:int,stty:string} $r
     * @param list<string> $secrets
     */
    private function assertTerminalClean(array $r, array $secrets): void
    {
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $r['shown'], 'the typed password is not shown');
        }
        $flags = preg_split('/[\s;]+/', $r['stty']) ?: [];
        $this->assertContains('echo', $flags, "echo is on again:\n" . $r['stty']);
        $this->assertNotContains('-echo', $flags);
    }

    /**
     * @param list<string> $cmd
     * @param array<string,string> $env
     * @param list<list<string>> $steps
     * @return array{shown:string,exit:int,stty:string}
     */
    private function pty(string $python, array $cmd, array $env, array $steps): array
    {
        $spec = json_encode(['cmd' => $cmd, 'cwd' => ROOT, 'steps' => $steps], JSON_THROW_ON_ERROR);
        $r = $this->proc([$python, ROOT . '/tests/fixtures/pty-run.py', $spec], $env, null);
        $this->assertSame(0, $r['exit'], $r['stderr']);

        return json_decode($r['stdout'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{0:list<string>,1?:string}> $steps
     * @param array<string,string> $extraEnv
     */
    private function runBoth(string $left, string $right, array $steps, array $extraEnv = []): void
    {
        foreach ($steps as $i => $step) {
            [$args, $stdin] = $step + [1 => null];
            $before = $this->state($right);
            $l = $this->cli($left, $args, false, $stdin, $extraEnv);
            $r = $this->cli($right, $args, true, $stdin, $extraEnv);
            $label = "step $i: settings.php " . implode(' ', $args);
            $this->assertSame($l['stdout'], $r['stdout'], "$label (stdout)");
            $this->assertSame($l['stderr'], $r['stderr'], "$label (stderr)");
            $this->assertSame($l['exit'], $r['exit'], "$label (exit)");
            $this->assertSame($this->state($left), $this->state($right), "$label (tables)");
            foreach (self::PASSWORDS as $password) {
                if ($stdin !== null || !in_array($password, $args, true)) {
                    $this->assertStringNotContainsString($password, $r['stdout'] . $r['stderr'], "$label: a password never reaches the output");
                }
            }
            if ($r['exit'] !== 0) {
                $this->assertSame($before, $this->state($right), "$label: a refusal changes nothing");
            }
        }
    }

    /**
     * The tables that the account forms touch: a password as the test password
     * it verifies, an epoch as empty or set (and which one, per row, across steps).
     *
     * @return array<string,mixed>
     */
    private function state(string $dir): array
    {
        $db = testdb($dir);
        try {
            $db->query('SELECT 1 FROM users, roles, settings LIMIT 1');
        } catch (PDOException) {
            return [];   // not migrated yet
        }
        $users = [];
        foreach ($db->query('SELECT id, username, username_key, role, status, created_at, last_login_at, password_hash, session_epoch FROM users ORDER BY id')->fetchAll() as $u) {
            $hash = (string)$u['password_hash'];
            $match = [$this->verified[$hash] ??= (string)(array_values(array_filter(self::PASSWORDS, static fn (string $p): bool => password_verify($p, $hash)))[0] ?? '?')];
            $users[] = [(int)$u['id'], (string)$u['username'], (string)$u['username_key'], (string)$u['role'], (string)$u['status'], (string)$u['created_at'],
                $u['last_login_at'], $match[0] ?? '?', (string)$u['session_epoch'] === '' ? '' : 'set'];
        }
        $roles = $db->query('SELECT role_key, name, perms, builtin, created_at FROM roles ORDER BY role_key')->fetchAll(PDO::FETCH_NUM);
        $settings = [];
        foreach ($db->query('SELECT name, value, updated_at FROM settings ORDER BY name')->fetchAll() as $row) {
            if ($row['name'] !== 'password_hash') {
                $settings[(string)$row['name']] = [(string)$row['value'], (string)$row['updated_at']];
            }
        }

        return ['users' => $users, 'epochs' => $this->epochs($dir), 'roles' => array_map(static fn (array $r): array => array_map('strval', $r), $roles), 'settings' => $settings];
    }

    /** @var array<string,string> hash => the test password it verifies */
    private array $verified = [];

    /** @var array<string,array<int,string>> dir => id => the epochs seen, numbered in order of appearance */
    private array $seen = [];

    /** @return array<int,int> id => how many different epochs the account has had */
    private function epochs(string $dir): array
    {
        $out = [];
        foreach (testdb($dir)->query('SELECT id, session_epoch FROM users ORDER BY id')->fetchAll() as $u) {
            $id = (int)$u['id'];
            $epoch = (string)$u['session_epoch'];
            $this->seen[$dir][$id] ??= '';
            if ($epoch !== '' && !str_contains($this->seen[$dir][$id], '|' . $epoch . '|')) {
                $this->seen[$dir][$id] .= '|' . $epoch . '|';
            }
            $out[$id] = substr_count($this->seen[$dir][$id], '|') / 2;
        }

        return $out;
    }

    /**
     * @param list<string> $args
     * @param array<string,string> $extraEnv
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function cli(string $dir, array $args, bool $ci4, ?string $stdin = null, array $extraEnv = []): array
    {
        return $this->proc([PHP_BINARY, ROOT . '/bin/settings.php', ...$args], array_merge($this->env($dir, $ci4), $extraEnv), $stdin);
    }

    /**
     * @param list<string> $cmd
     * @param array<string,string> $env
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function proc(array $cmd, array $env, ?string $stdin): array
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], (string)$stdin);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    /** @return array<string,string> */
    private function env(string $dir, bool $ci4): array
    {
        $env = array_merge(getenv(), test_server_env($dir), [
            'APOTHEKE_CLOCK' => self::CLOCK,
            'CI_ENVIRONMENT' => 'production',
            'PHP_INI_SCAN_DIR' => '',
        ]);
        unset($env['APOTHEKE_APP'], $env['APP_PASSWORD']);
        if ($ci4) {
            $env['APOTHEKE_APP'] = 'ci4';
        }

        return array_map('strval', $env);
    }

    /** @return array{0:string,1:string} */
    private function twins(string $prefix): array
    {
        $left = tmpdir($prefix . '-legacy');
        $right = tmpdir($prefix . '-ci4');
        array_push($this->dirs, $left, $right);

        return [$left, $right];
    }
}
