<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * API P2: GET /api/v1/users, /api/v1/roles and /api/v1/security (users permission) and GET /api/v1/branding
 * (anyone). Accounts are exactly their allowlisted fields; the security read is the sign-in policy; nothing in
 * any answer is a hash, a token, a session value or an e-mail address (2.6.x stores none).
 */
final class Ci4ApiUsersTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private const READS = ['/api/v1/users', '/api/v1/roles', '/api/v1/security'];

    private const USER_KEYS = ['id', 'name', 'role', 'status', 'created_at', 'last_login_at'];

    private const POLICY_KEYS = ['auth_guest_disabled', 'auth_register_disabled', 'auth_register_role', 'auth_register_approval',
        'session_days', 'session_idle_hours', 'trust_proxy'];

    /** No key anywhere in an answer may look like one of these. */
    private const FORBIDDEN_KEY = '/hash|token|secret|password|passwd|epoch|^session_id$|^sid$|csrf|e_?mail|cookie|key_?material/i';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('api-users');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4');
        self::$server->client()->rest('GET', '/api/v1/session');   // migrates and creates admin
        $pdo = testdb(self::$dir);
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $user->execute(['vera', 'vera', $hash, 'viewer', 'active', '2026-01-01 00:00:00']);
        $user->execute(['Ed', 'ed', $hash, 'editor', 'active', '2026-01-02 00:00:00']);
        $user->execute(['hana', 'hana', $hash, 'helpdesk', 'active', '2026-01-03 00:00:00']);
        $user->execute(['sam', 'sam', $hash, 'settler', 'active', '2026-01-04 00:00:00']);
        $user->execute(['pia', 'pia', $hash, 'viewer', 'pending', '2026-01-05 00:00:00']);
        $role = $pdo->prepare('INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?, ?, ?, 0, ?)');
        $role->execute(['helpdesk', 'Help desk', '["view","users"]', '2026-01-01 00:00:00']);
        $role->execute(['settler', 'Settings only', '["view","settings"]', '2026-01-01 00:00:00']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    /** Signed out, a viewer and an editor get exactly what the settings read gives them. */
    public function testRefusalsMatchTheSettingsRead(): void
    {
        foreach (['anon' => $this->client(), 'vera' => $this->signedIn('vera'), 'Ed' => $this->signedIn('Ed')] as $who => $c) {
            $settings = $c->rest('GET', '/api/v1/settings');
            $this->assertContains($settings['status'], [401, 403], $who);
            foreach (self::READS as $path) {
                $r = $c->rest('GET', $path);
                $same = $settings['json'];
                if (isset($same['detail'])) {
                    $same['detail'] = str_replace('“Settings”', '“Users”', $same['detail']);   // the permission each needs
                }
                $this->assertSame([$settings['status'], $same], [$r['status'], $r['json']], $who . ' ' . $path);
                $this->assertSame('no-store', $r['headers']['cache-control']);
                ApiContract::assertMatches('GET', $path, $r);
                $this->assertStringNotContainsString('hana', $r['body']);
            }
        }
        $this->assertSame([401, 'auth.required'], [$this->client()->rest('GET', '/api/v1/users')['status'], $this->client()->rest('GET', '/api/v1/users')['json']['code'] ?? null]);
        $this->assertSame('permission.denied', $this->signedIn('vera')->rest('GET', '/api/v1/users')['json']['code'] ?? null);
    }

    /** The permission is the ACL's users permission: a custom role with it reads, one with only settings does not. */
    public function testTheUsersPermissionDecides(): void
    {
        $hana = $this->signedIn('hana');
        foreach (self::READS as $path) {
            $r = $hana->rest('GET', $path);
            $this->assertSame(200, $r['status'], $path . ' ' . $r['body']);
            ApiContract::assertMatches('GET', $path, $r);
        }
        $this->assertSame(403, $hana->rest('GET', '/api/v1/settings')['status'], 'users is not settings');
        $sam = $this->signedIn('sam');
        $this->assertSame(200, $sam->rest('GET', '/api/v1/settings')['status']);
        foreach (self::READS as $path) {
            $this->assertSame([403, 'permission.denied'], [$sam->rest('GET', $path)['status'], $sam->rest('GET', $path)['json']['code'] ?? null], $path);
        }
    }

    public function testUsersAreTheAllowlistedFieldsOnly(): void
    {
        $r = $this->signedIn('admin')->rest('GET', '/api/v1/users');
        $this->assertSame(200, $r['status'], $r['body']);
        ApiContract::assertMatches('GET', '/api/v1/users', $r);
        $users = $r['json']['data'];
        $this->assertSame(['admin', 'Ed', 'hana', 'pia', 'sam', 'vera'], array_column($users, 'name'), 'sorted by name, case-insensitive');
        foreach ($users as $u) {
            $this->assertSame(self::USER_KEYS, array_keys($u), $u['name']);
            $this->assertIsInt($u['id']);
        }
        $byName = array_column($users, null, 'name');
        $this->assertSame(['role' => 'viewer', 'status' => 'pending', 'created_at' => '2026-01-05 00:00:00', 'last_login_at' => null],
            array_intersect_key($byName['pia'], array_flip(['role', 'status', 'created_at', 'last_login_at'])));
        $this->assertNotNull($byName['admin']['last_login_at'], 'signed in just now');
        $this->assertSame('helpdesk', $byName['hana']['role']);
    }

    public function testRolesAreAReadOnlyList(): void
    {
        $admin = $this->signedIn('admin');
        $r = $admin->rest('GET', '/api/v1/roles');
        $this->assertSame(200, $r['status'], $r['body']);
        ApiContract::assertMatches('GET', '/api/v1/roles', $r);
        $roles = array_column($r['json']['data'], null, 'key');
        $this->assertSame(['admin', 'editor', 'viewer', 'guest', 'helpdesk', 'settler'], array_keys($roles), 'built-in first');
        $this->assertSame(['key' => 'helpdesk', 'name' => 'Help desk', 'builtin' => false, 'permissions' => ['view', 'users'], 'users' => 1], $roles['helpdesk']);
        $this->assertTrue($roles['admin']['builtin']);
        $this->assertSame(2, $roles['viewer']['users']);
        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $w = $admin->rest($method, '/api/v1/roles', [], ['X-CSRF-Token: ' . $admin->rest('GET', '/api/v1/session')['json']['csrf_token']]);
            $this->assertSame(404, $w['status'], $method . ' is not routed in this release');
        }
    }

    public function testSecurityIsThePolicyOnly(): void
    {
        $admin = $this->signedIn('admin');
        $r = $admin->rest('GET', '/api/v1/security');
        $this->assertSame(200, $r['status'], $r['body']);
        ApiContract::assertMatches('GET', '/api/v1/security', $r);
        $this->assertSame(['sign_in_enabled', 'policy'], array_keys($r['json']));
        $this->assertTrue($r['json']['sign_in_enabled']);
        $this->assertSame(self::POLICY_KEYS, array_keys($r['json']['policy']));
        $this->assertSame([true, true, 'viewer', false, 30, 168, false], array_values($r['json']['policy']), 'the defaults');
        $this->setting('session_days', 7);
        $this->setting('auth_register_disabled', false);
        $after = $admin->rest('GET', '/api/v1/security')['json']['policy'];
        $this->assertSame([7, false], [$after['session_days'], $after['auth_register_disabled']]);
        $this->setting('session_days', 30);
        $this->setting('auth_register_disabled', true);
    }

    /** A new key in the security section is a decision: it is not read until it is added to the policy list. */
    public function testThePolicyIsTheSecuritySectionsReadableKeys(): void
    {
        $this->assertSame(self::POLICY_KEYS, (new \Apotheke\Settings\SettingsSchema())->sectionKeys('security'));
        $this->assertSame(self::POLICY_KEYS, \Apotheke\Auth\Controllers\Api\UsersController::POLICY);
    }

    /** Recursive key scan of every answer, and no stored hash or session epoch value anywhere in a body. */
    public function testNoHashTokenOrSessionValueEverAppears(): void
    {
        $admin = $this->signedIn('admin');
        $stored = [];
        foreach (testdb(self::$dir)->query('SELECT password_hash, session_epoch FROM users') as $row) {
            $stored[] = (string)$row['password_hash'];
            if ((string)$row['session_epoch'] !== '') {
                $stored[] = (string)$row['session_epoch'];
            }
        }
        $this->assertNotEmpty($stored);
        $csrf = $admin->rest('GET', '/api/v1/session')['json']['csrf_token'];
        foreach ([...self::READS, '/api/v1/branding'] as $path) {
            $r = $admin->rest('GET', $path);
            $this->assertSame(200, $r['status'], $path);
            $keys = self::keys($r['json']);
            $this->assertNotEmpty($keys);
            $this->assertSame([], preg_grep(self::FORBIDDEN_KEY, $keys), $path);
            foreach ([...$stored, $csrf, '$2y$', '$argon2'] as $secret) {
                $this->assertStringNotContainsString($secret, $r['body'], $path);
            }
            foreach ($admin->cookies as $value) {
                $this->assertStringNotContainsString((string)$value, $r['body'], $path . ': no cookie value');
            }
        }
    }

    /** Public, like /manifest.webmanifest: the sign-in page shows the same name and icons to anyone. */
    public function testBrandingIsWhatTheSignInPageShows(): void
    {
        $anon = $this->client();
        $r = $anon->rest('GET', '/api/v1/branding');
        $this->assertSame(200, $r['status'], $r['body']);
        ApiContract::assertMatches('GET', '/api/v1/branding', $r);
        $this->assertSame([], $anon->cookies, 'no session for a public read');
        $b = $r['json'];
        $this->assertSame(['name' => 'Apotheke', 'accent' => '#4f46e5', 'theme_default' => 'auto'],
            array_intersect_key($b, array_flip(['name', 'accent', 'theme_default'])));
        $login = $this->client()->get('/login');
        $this->assertSame(200, $login['status']);
        foreach (['logo_url', 'favicon_url', 'touch_icon_url'] as $key) {
            $this->assertStringContainsString('"' . htmlspecialchars($b[$key]) . '"', $login['body'], $key);
        }
        $this->assertStringContainsString(htmlspecialchars($b['name'] . ', ' . $b['tagline']), $login['body']);
        $this->setting('app_name', 'Pantry');
        $this->setting('accent', '#336699');
        $now = $anon->rest('GET', '/api/v1/branding')['json'];
        $this->assertSame(['Pantry', '#336699'], [$now['name'], $now['accent']]);
        $this->assertStringContainsString('Pantry', $this->client()->get('/manifest.webmanifest')['body']);
        $this->setting('app_name', 'Apotheke');
        $this->setting('accent', '#4f46e5');
    }

    /**
     * @return list<string> every key at any depth
     */
    private static function keys(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $out[] = $k;
            }
            array_push($out, ...self::keys($v));
        }

        return $out;
    }

    private function setting(string $name, mixed $value): void
    {
        $pdo = testdb(self::$dir);
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value), '2026-01-01 00:00:00']);
    }

    private function client(): Client
    {
        $this->assertNotNull(self::$server);

        return self::$server->client();
    }

    private function signedIn(string $user): Client
    {
        $c = $this->client();
        $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $in = $c->rest('POST', '/api/v1/session', ['username' => $user, 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token]);
        $this->assertSame(200, $in['status'], $in['body']);

        return $c;
    }
}
