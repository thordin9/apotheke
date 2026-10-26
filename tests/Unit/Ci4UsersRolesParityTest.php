<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Golden (PLAN step 7, PR B): Control Panel → Users & sign-in on CI4 against
 * legacy. The same requests go to a legacy and a CI4 server with the same
 * data, for the admin, a non-admin with the Users permission, an editor, a
 * viewer, the guest (guest browsing on) and a signed-out visitor: the page and
 * its settings form, sign-in on and off, your own password, every account and
 * role action, CSRF and permission refusals, unknown and out-of-range ids,
 * changes to admin accounts by a non-admin, the last active admin, the
 * sessions a password change or a deletion ends, failed statements (500,
 * nothing changed) and the tables at the end. Answers are byte-identical
 * except the asset cache busters, the session id and the per-session CSRF token.
 */
final class Ci4UsersRolesParityTest extends TestCase
{
    private const PW = 'long-enough';

    public function testTheUsersAndRolesPagesMatchLegacy(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('users-' . $app);
            $server = new Server($dir, [], $app);
            try {
                $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
                $answers[$app] = $this->visit($server, $dir);
            } finally {
                $server->stop();
                testdb_drop($dir);
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
        $this->assertGreaterThan(150, count($answers['legacy']));
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
        // A password with a NUL byte: both refuse it with a 422 and the form (#106 on legacy).
        $this->assertStringContainsString('Passwords cannot contain a null character.', (string)$answers['legacy']['add NUL in password']['body']);
        $this->assertSame([422, 422], [$answers['legacy']['add NUL in password']['status'], $answers['legacy']['vera password NUL']['status']]);
        // The cases are what they claim to be.
        $l = $answers['legacy'];
        $this->assertSame(200, $l['ada GET /settings/security']['status']);
        $this->assertStringContainsString('id="users"', (string)$l['ada GET /settings/security']['body']);
        $this->assertSame(200, $l['dee GET /settings/security']['status']);
        $this->assertSame([403, 403, 303, 303], [$l['eda GET /settings/security']['status'], $l['vera GET /settings/security']['status'],
            $l['guest GET /settings/security']['status'], $l['signed-out GET /settings/security']['status']]);
        $this->assertSame(419, $l['ada role without csrf']['status']);
        $this->assertSame([303, '/settings/security?done=user-added#users'], [$l['add neo']['status'], $l['add neo']['location']]);
        $this->assertSame([404, 404, 404], [$l['role of an unknown id']['status'], $l['delete an out-of-range id']['status'], $l['user without action']['status']]);
        foreach (['dee demotes ada', 'dee sets ada password', 'dee renames ada', 'dee deletes ada', 'dee approves pal', 'dee adds an admin', 'dee makes neo admin', 'dee turns sign-in off'] as $case) {
            $this->assertSame(403, $l[$case]['status'], $case);
        }
        $this->assertSame([409, 409, 409], [$l['ada demotes herself, only admin']['status'], $l['ada deletes herself']['status'], $l['off: delete the only admin']['status']]);
        $this->assertSame([303, 303], [$l['vera other session after a new password']['status'], $l['neo session after deletion']['status']]);
        $this->assertSame(200, $l['eda session after a role change']['status'], 'a role change keeps the sessions, as legacy does');
        $this->assertSame([200, 303], [$l['ada this session after her new password']['status'], $l['ada other session after her new password']['status']]);
        $this->assertSame(['ok' => true, 'key' => 'help-team'], $l['role add fetch']['json']);
        $this->assertSame([409, 409, 422, 409], [$l['role update admin']['status'], $l['role delete built-in']['status'], $l['role delete, move to guest']['status'], $l['role lockout']['status']]);
        $this->assertSame([303, '/settings/security?done=pw', true, 303], [$l['own password']['status'], $l['own password']['location'], $l['own password']['kept signed_in_at'], $l['ada other session after her own password']['status']]);
        $this->assertTrue($l['ada sets her own password']['kept signed_in_at']);
        $this->assertSame('"viewer"', $l['role delete packers']['register role'], 'registrations fall back to viewer');
        $this->assertSame([303, '/settings/security?done=pw-off'], [$l['ada turns sign-in off']['status'], $l['ada turns sign-in off']['location']]);
        $this->assertSame([303, '/settings/security?done=pw'], [$l['turn on as ada']['status'], $l['turn on as ada']['location']]);
        $this->assertSame(403, $l['turn on as eda']['status']);
        foreach (['fail add user', 'fail set role', 'fail set password', 'fail rename', 'fail approve', 'fail delete user', 'fail add role', 'fail update role',
            'fail delete role: roles', 'fail delete role: moving users', 'fail save settings', 'fail first admin', 'fail turn on'] as $case) {
            $this->assertSame([500, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
        }
        $this->assertStringContainsString('"root"', (string)$l['tables']['body']);
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $pdo->prepare('INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?, ?, ?, 0, ?)')
            ->execute(['desk', 'Help desk', json_encode(['view', 'users']), '2026-01-01 00:00:00']);
        $add = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['ada', 'admin', 'active'], ['dee', 'desk', 'active'], ['eda', 'editor', 'active'], ['vera', 'viewer', 'active'],
            ['Pam', 'viewer', 'pending'], ['pal', 'admin', 'pending']] as [$name, $role, $status]) {
            $add->execute([$name, mb_strtolower($name), password_hash(self::PW, PASSWORD_BCRYPT, ['cost' => 4]), $role, $status, '2026-01-01 00:00:00']);
        }
        $id = static function (string $name) use ($pdo): int {
            $q = $pdo->prepare('SELECT id FROM users WHERE username_key = ?');
            $q->execute([mb_strtolower($name)]);
            $v = $q->fetchColumn();
            $q->closeCursor();

            return (int)$v;
        };
        $this->setting($pdo, 'auth_enabled', 'true');
        $this->setting($pdo, 'auth_guest_disabled', 'true');
        // A browser holding a live session (with a CSRF token), signed in as $account when given.
        $browser = function (?string $account, int $signedInAt = 0) use ($server, $pdo, $id, $dir): array {
            $client = $server->client();
            $token = bin2hex(random_bytes(32));
            $at = $signedInAt ?: time() - 100;
            $session = 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . $at . ';';
            if ($account !== null) {
                $row = $pdo->query('SELECT id, password_hash, session_epoch FROM users WHERE id = ' . $id($account))->fetch();
                $epoch = (string)$row['session_epoch'] !== '' ? (string)$row['session_epoch'] : substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16);
                $session = 'uid|i:' . $row['id'] . ';auth_epoch|s:' . strlen($epoch) . ':"' . $epoch . '";' . $session;
            }
            $sid = bin2hex(random_bytes(13));
            file_put_contents($dir . '/sessions/sess_' . $sid, $session);
            $client->cookies['apothekesid'] = $sid;

            return [$client, $token, $sid];
        };
        $out = [];
        $record = function (string $case, array $r) use (&$out): void {
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            $body = preg_replace('/\b[0-9a-f]{64}\b/', 'SESSION-TOKEN', (string)$body);
            $cache = $r['headers']['cache-control'] ?? '';
            $cache = $r['status'] === 200 || $r['json'] !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache);
            $cookies = [];
            foreach ($r['raw'] as $line) {
                if (stripos($line, 'set-cookie:') === 0) {
                    $cookies[] = preg_replace(['/apothekesid=[^;]*/', '/expires=[^;]*;\s*Max-Age=\d+;\s*/i'], ['apothekesid=SID', ''], trim(substr($line, 11)));
                }
            }
            sort($cookies);
            $out[$case] = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $r['headers']['location'] ?? null,
                'cache-control' => $cache, 'set-cookie' => $cookies, 'json' => $r['json'], 'body' => $r['json'] === null ? $body : null];
        };
        $fetch = ['X-Requested-With: fetch'];
        $as = function (string $case, string $method, string $path, array $fields = [], array $headers = [], ?string $who = 'ada', bool $csrf = true) use ($browser, $record): array {
            [$client, $token] = $browser($who);
            if ($method === 'POST' && $csrf) {
                $fields = ['csrf' => $token] + $fields;
            }
            $r = $client->req($method, $path, $fields, [], $headers);
            $record($case, $r);

            return $r;
        };
        $user = static fn (string $name): string => '/settings/users/' . $id($name);

        // The page for every kind of visitor, and what is refused before CSRF.
        foreach (['ada' => ['ada', 'true'], 'dee' => ['dee', 'true'], 'eda' => ['eda', 'true'], 'vera' => ['vera', 'true'], 'guest' => [null, 'false'], 'signed-out' => [null, 'true']] as $visitor => [$who, $guestOff]) {
            $this->setting($pdo, 'auth_guest_disabled', $guestOff);
            foreach (['/settings/security', '/settings/security?done=user-added', '/settings/security?done=nope', '/settings/security?done[]=x', '/settings/security/', '/settings/users', '/settings/security/reset'] as $path) {
                $as($visitor . ' GET ' . $path, 'GET', $path, [], [], $who);
            }
            $as($visitor . ' HEAD /settings/security', 'HEAD', '/settings/security', [], [], $who);
            $as($visitor . ' GET /settings/security fetch', 'GET', '/settings/security', [], $fetch, $who);
            $as($visitor . ' role without csrf', 'POST', $user('vera') . '/role', ['role' => 'editor'], [], $who, false);
            $as($visitor . ' role without csrf fetch', 'POST', $user('vera') . '/role', ['role' => 'editor'], $fetch, $who, false);
            $as($visitor . ' add role without csrf', 'POST', '/settings/roles', ['name' => 'X'], [], $who, false);
            $as($visitor . ' password without csrf', 'POST', '/settings/password', ['mode' => 'disable'], [], $who, false);
            $as($visitor . ' save without csrf', 'POST', '/settings/security', ['session_days' => '3'], [], $who, false);
        }
        $this->setting($pdo, 'auth_guest_disabled', 'true');

        // Accounts.
        $add = static fn (string $name, string $role, string $pw = self::PW, ?string $confirm = null): array => ['username' => $name, 'new' => $pw, 'confirm' => $confirm ?? $pw, 'role' => $role];
        $as('add bad name', 'POST', '/settings/users', $add('a b<x>', 'editor'));
        $as('add short password', 'POST', '/settings/users', $add('neo', 'editor', 'short'));
        $as('add mismatch', 'POST', '/settings/users', $add('neo', 'editor', self::PW, self::PW . 'x'));
        $as('add NUL in password', 'POST', '/settings/users', $add('neo', 'editor', "long\0enough"));
        $as('add taken name', 'POST', '/settings/users', $add('ADA', 'editor'));
        $as('add guest role', 'POST', '/settings/users', $add('neo', 'guest'));
        $as('add unknown role', 'POST', '/settings/users', $add('neo', 'nope'));
        $as('add array role', 'POST', '/settings/users', ['role' => ['x']] + $add('neo', 'x'));
        $as('add neo', 'POST', '/settings/users', $add(' neo ', 'editor'));
        $as('add fetch', 'POST', '/settings/users/0', $add('nia', 'viewer'), $fetch);
        $as('dee adds an admin', 'POST', '/settings/users', $add('zed', 'admin'), [], 'dee');
        $as('dee adds an editor', 'POST', '/settings/users', $add('zed', 'editor'), [], 'dee');
        $as('ada adds an admin', 'POST', '/settings/users', $add('abe', 'admin'));
        $as('role of an unknown id', 'POST', '/settings/users/99999/role', ['role' => 'editor']);
        $as('delete an out-of-range id', 'POST', '/settings/users/99999999999999999999999/delete');
        $as('user without action', 'POST', $user('vera'));
        $as('unknown action', 'POST', $user('vera') . '/bogus');
        $as('dee demotes ada', 'POST', $user('ada') . '/role', ['role' => 'viewer'], [], 'dee');
        $as('dee sets ada password', 'POST', $user('ada') . '/password', ['new' => 'other-password', 'confirm' => 'other-password'], [], 'dee');
        $as('dee renames ada', 'POST', $user('ada') . '/rename', ['username' => 'eve'], [], 'dee');
        $as('dee deletes ada', 'POST', $user('ada') . '/delete', [], [], 'dee');
        $as('dee approves pal', 'POST', $user('pal') . '/approve', [], [], 'dee');
        $as('dee makes neo admin', 'POST', $user('neo') . '/role', ['role' => 'admin'], [], 'dee');
        $as('dee renames neo', 'POST', $user('neo') . '/rename', ['username' => 'Neon'], [], 'dee');
        $as('rename to a taken name', 'POST', $user('neon') . '/rename', ['username' => ' Vera ']);
        $as('rename to its own name', 'POST', $user('neon') . '/rename', ['username' => 'NEON']);
        $as('ada demotes abe', 'POST', $user('abe') . '/role', ['role' => 'editor']);
        $as('ada demotes herself, only admin', 'POST', $user('ada') . '/role', ['role' => 'editor']);
        $as('ada keeps herself admin', 'POST', $user('ada') . '/role', ['role' => 'admin']);
        $as('ada deletes herself', 'POST', $user('ada') . '/delete');
        $as('approve Pam', 'POST', $user('pam') . '/approve', [], $fetch);
        [$eda] = $browser('eda');
        $as('eda becomes viewer', 'POST', $user('eda') . '/role', ['role' => 'viewer']);
        $record('eda session after a role change', $eda->req('GET', '/account'));
        $as('vera password short', 'POST', $user('vera') . '/password', ['new' => 'short', 'confirm' => 'short']);
        $as('vera password NUL', 'POST', $user('vera') . '/password', ['new' => "nope\0nope-nope", 'confirm' => "nope\0nope-nope"]);
        [$vera] = $browser('vera');
        $as('vera new password by ada', 'POST', $user('vera') . '/password', ['new' => 'vera-password', 'confirm' => 'vera-password']);
        $record('vera other session after a new password', $vera->req('GET', '/account'));
        [$adaOther] = $browser('ada');
        $at = time() - 5000;
        [$client, $token, $sid] = $browser('ada', $at);
        $record('ada sets her own password', $client->req('POST', $user('ada') . '/password', ['csrf' => $token, 'new' => 'ada-password', 'confirm' => 'ada-password']));
        $out['ada sets her own password']['kept signed_in_at'] = str_contains((string)@file_get_contents($dir . '/sessions/sess_' . ($client->cookies['apothekesid'] ?? '')), 'signed_in_at|i:' . $at . ';');
        $record('ada this session after her new password', $client->req('GET', '/settings/security?done=user-pw'));
        $record('ada other session after her new password', $adaOther->req('GET', '/settings/security'));
        [$neo] = $browser('neon');
        $as('delete neon', 'POST', $user('neon') . '/delete');
        $record('neo session after deletion', $neo->req('GET', '/account'));
        $as('delete pal (pending admin)', 'POST', $user('pal') . '/delete', [], [], 'ada');

        // Roles.
        $as('role add empty name', 'POST', '/settings/roles', ['name' => ' ']);
        $as('role add no letters', 'POST', '/settings/roles', ['name' => '!!!', 'perms' => ['view']]);
        $as('role add unknown permission', 'POST', '/settings/roles', ['name' => 'Packers', 'perms' => ['view', 'fly']]);
        $as('role add taken', 'POST', '/settings/roles', ['name' => 'Help desk']);
        $as('role add taken fetch', 'POST', '/settings/roles', ['name' => 'EDITOR'], $fetch);
        $as('role add fetch', 'POST', '/settings/roles', ['name' => 'Help team', 'perms' => ['view', 'users', ['x']]], $fetch);
        $as('role add perms as a string', 'POST', '/settings/roles', ['name' => 'Packers', 'perms' => 'create']);
        $as('role update desk', 'POST', '/settings/roles/desk', ['name' => 'Front desk', 'perms' => ['view', 'users', 'labels']]);
        $as('role update without name', 'POST', '/settings/roles/packers', ['perms' => ['view']]);
        $as('role update admin', 'POST', '/settings/roles/admin', ['perms' => ['view']]);
        $as('role update guest', 'POST', '/settings/roles/guest', ['perms' => ['view', 'users', 'settings']]);
        $as('role update unknown', 'POST', '/settings/roles/nosuch', ['perms' => ['view']]);
        $as('role update upper-case key', 'POST', '/settings/roles/Desk', ['perms' => ['view']]);
        $as('role update by dee fetch', 'POST', '/settings/roles/help-team', ['name' => 'Help team', 'perms' => ['view']], $fetch, 'dee');
        $pdo->exec("UPDATE users SET status = 'pending' WHERE username = 'ada'");
        $as('role lockout', 'POST', '/settings/roles/desk', ['perms' => ['view']], [], 'dee');
        $as('role delete lockout', 'POST', '/settings/roles/desk/delete', ['move_to' => 'viewer'], [], 'dee');
        $pdo->exec("UPDATE users SET status = 'active' WHERE username = 'ada'");
        $as('role delete built-in', 'POST', '/settings/roles/viewer/delete');
        $as('role delete, move to guest', 'POST', '/settings/roles/packers/delete', ['move_to' => 'guest']);
        $as('role delete, move to itself', 'POST', '/settings/roles/packers/delete', ['move_to' => 'packers']);
        $as('role delete, move to an array', 'POST', '/settings/roles/packers/delete', ['move_to' => ['x']]);
        $as('role delete unknown', 'POST', '/settings/roles/nosuch/delete');
        $this->setting($pdo, 'auth_register_role', '"packers"');
        $pdo->exec("UPDATE users SET role = 'packers' WHERE username = 'nia'");
        $as('role delete packers', 'POST', '/settings/roles/packers/delete');
        $out['role delete packers']['register role'] = $pdo->query("SELECT value FROM settings WHERE name = 'auth_register_role'")->fetchColumn();
        $as('role delete fetch', 'POST', '/settings/roles/help-team/delete', ['move_to' => 'editor'], $fetch);

        // The section's settings.
        $as('save invalid', 'POST', '/settings/security', ['session_days' => '0', 'session_idle_hours' => ['x'], 'auth_register_role' => 'admin', 'trust_proxy' => '1']);
        $as('save', 'POST', '/settings/security', ['session_days' => '14', 'session_idle_hours' => '24', 'auth_register_role' => 'editor', 'auth_register_disabled' => '1', 'auth_guest_disabled' => '1']);
        $as('save by dee', 'POST', '/settings/security', ['session_days' => '15', 'session_idle_hours' => '24', 'auth_register_role' => 'desk', 'auth_guest_disabled' => '1'], [], 'dee');
        $as('GET after save', 'GET', '/settings/security?done=saved');
        $as('reset', 'POST', '/settings/security/reset');

        // Sign-in on and off, your own password.
        $as('own password wrong current', 'POST', '/settings/password', ['current' => 'wrong', 'new' => 'ada-password-2', 'confirm' => 'ada-password-2']);
        $as('own password mismatch', 'POST', '/settings/password', ['current' => 'ada-password', 'new' => 'ada-password-2', 'confirm' => 'x']);
        [$adaOther] = $browser('ada');
        [$client, $token] = $browser('ada', $at);
        $record('own password', $client->req('POST', '/settings/password', ['csrf' => $token, 'current' => 'ada-password', 'new' => 'ada-password-2', 'confirm' => 'ada-password-2']));
        $out['own password']['kept signed_in_at'] = str_contains((string)@file_get_contents($dir . '/sessions/sess_' . ($client->cookies['apothekesid'] ?? '')), 'signed_in_at|i:' . $at . ';');
        $record('ada other session after her own password', $adaOther->req('GET', '/settings/security'));
        $as('dee turns sign-in off', 'POST', '/settings/password', ['mode' => 'disable', 'current' => self::PW], [], 'dee');
        $as('dee own password', 'POST', '/settings/password', ['current' => self::PW, 'new' => 'dee-password', 'confirm' => 'dee-password'], [], 'dee');
        $as('ada turns sign-in off, wrong current', 'POST', '/settings/password', ['mode' => 'disable', 'current' => 'nope']);
        $as('ada turns sign-in off', 'POST', '/settings/password', ['mode' => 'disable', 'current' => 'ada-password-2']);
        $as('off GET /settings/security', 'GET', '/settings/security', [], [], null);
        $as('off: delete the only admin', 'POST', $user('ada') . '/delete', [], [], null);
        $as('turn on as eda', 'POST', '/settings/password', ['username' => 'eda', 'new' => self::PW], [], null);
        $as('turn on as ada, wrong password', 'POST', '/settings/password', ['username' => 'ADA', 'new' => 'nope'], [], null);
        [$client, $token, $sid] = $browser(null);
        $record('turn on as ada', $client->req('POST', '/settings/password', ['csrf' => $token, 'username' => ' ada ', 'new' => 'ada-password-2']));
        $out['turn on as ada']['new session'] = ($client->cookies['apothekesid'] ?? $sid) !== $sid;
        $record('ada after turning sign-in on', $client->req('GET', '/settings/security?done=pw'));
        $this->setting($pdo, 'auth_enabled', 'false');
        $as('first admin, short password', 'POST', '/settings/password', ['username' => 'root', 'new' => 'short', 'confirm' => 'short'], [], null);
        $as('first admin', 'POST', '/settings/password', ['username' => 'root', 'new' => self::PW, 'confirm' => self::PW], [], null);
        $this->setting($pdo, 'auth_enabled', 'false');
        $pdo->exec("UPDATE users SET status = 'pending' WHERE role = 'admin'");
        $as('turn on, no active admin', 'POST', '/settings/password', ['username' => 'root', 'new' => self::PW], [], null);
        $pdo->exec("UPDATE users SET status = 'active' WHERE role = 'admin'");
        $as('turn off while off', 'POST', '/settings/password', ['mode' => 'disable'], [], null);
        $this->setting($pdo, 'auth_enabled', 'true');

        // A statement that fails (a trigger stands in for a broken disk or constraint) is a 500 and changes nothing.
        $snapshot = static fn (): string => json_encode([$pdo->query('SELECT id, username, role, status, password_hash, session_epoch FROM users ORDER BY id')->fetchAll(),
            $pdo->query('SELECT role_key, name, perms FROM roles ORDER BY role_key')->fetchAll(), $pdo->query('SELECT name, value FROM settings ORDER BY name')->fetchAll()]);
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('temp', 'Temp', '[\"view\"]', 0, '2026-01-01 00:00:00')");
        $pdo->exec("UPDATE users SET role = 'temp' WHERE username = 'nia'");
        foreach ([['fail add user', 'users', 'INSERT', '/settings/users', $add('fay', 'editor'), 'ada', true],
            ['fail set role', 'users', 'UPDATE', $user('eda') . '/role', ['role' => 'editor'], 'ada', true],
            ['fail set password', 'users', 'UPDATE', $user('eda') . '/password', ['new' => 'eda-password', 'confirm' => 'eda-password'], 'ada', true],
            ['fail rename', 'users', 'UPDATE', $user('eda') . '/rename', ['username' => 'edda'], 'ada', true],
            ['fail approve', 'users', 'UPDATE', $user('zed') . '/approve', [], 'ada', true],
            ['fail delete user', 'users', 'DELETE', $user('zed') . '/delete', [], 'ada', true],
            ['fail add role', 'roles', 'INSERT', '/settings/roles', ['name' => 'Fail', 'perms' => ['view']], 'ada', true],
            ['fail update role', 'roles', 'UPDATE', '/settings/roles/temp', ['name' => 'Tmp', 'perms' => ['view']], 'ada', true],
            ['fail delete role: roles', 'roles', 'DELETE', '/settings/roles/temp/delete', ['move_to' => 'editor'], 'ada', true],
            ['fail delete role: moving users', 'users', 'UPDATE', '/settings/roles/temp/delete', ['move_to' => 'editor'], 'ada', true],
            ['fail save settings', 'settings', 'INSERT', '/settings/security', ['session_days' => '9', 'session_idle_hours' => '9', 'auth_register_role' => 'viewer'], 'ada', true],
            ['fail first admin', 'users', 'INSERT', '/settings/password', ['username' => 'fox', 'new' => self::PW, 'confirm' => self::PW], null, false],
            ['fail turn on', 'settings', 'INSERT', '/settings/password', ['username' => 'root', 'new' => self::PW], null, false]] as [$case, $table, $op, $path, $fields, $who, $on]) {
            $this->setting($pdo, 'auth_enabled', $on ? 'true' : 'false');
            $pdo->exec($pg ? "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()"
                : "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $before = $snapshot();
            $as($case, 'POST', $path, $fields, [], $who);
            $out[$case]['unchanged'] = $before === $snapshot();
            $pdo->exec($pg ? "DROP TRIGGER apotheke_test_fail ON $table" : 'DROP TRIGGER apotheke_test_fail');
        }
        $this->setting($pdo, 'auth_enabled', 'true');

        $rows = $pdo->query('SELECT id, username, username_key, role, status, created_at, last_login_at, password_hash, session_epoch FROM users ORDER BY id')->fetchAll();
        foreach ($rows as &$row) {
            $row['last_login_at'] = $row['last_login_at'] === null ? null : 'set';
            $row['created_at'] = $row['created_at'] === '2026-01-01 00:00:00' ? 'seed' : 'new';
            $row['password_hash'] = substr((string)$row['password_hash'], 0, 4);
            $row['session_epoch'] = (string)$row['session_epoch'] === '' ? '' : 'renewed';
            $row = array_map(static fn ($v) => $v === null ? null : (string)$v, $row);
        }
        unset($row);
        $roles = $pdo->query('SELECT role_key, name, perms, builtin, created_at FROM roles ORDER BY role_key')->fetchAll();
        foreach ($roles as &$role) {
            $role['created_at'] = $role['created_at'] === '2026-01-01 00:00:00' ? 'seed' : 'new';
            $role = array_map(static fn ($v) => $v === null ? null : (string)$v, $role);
        }
        unset($role);
        $settings = $pdo->query("SELECT name, value FROM settings WHERE name LIKE 'auth\\_%' ESCAPE '\\' OR name LIKE 'session\\_%' ESCAPE '\\' OR name = 'trust_proxy' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
        $out['tables'] = ['body' => json_encode([$rows, $roles, $settings], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];

        return $out;
    }

    private function setting(PDO $pdo, string $name, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, $value, '2026-01-01']);
    }
}
