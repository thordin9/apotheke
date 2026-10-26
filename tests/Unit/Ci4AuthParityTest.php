<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Golden (PLAN step 7, PR A): /login, /register, /logout, /account and
 * /account/signout on CI4 against legacy. The same requests go to a legacy and
 * a CI4 server with the same data, for the admin, an editor, a viewer, a
 * pending account, the guest (guest browsing on) and a signed-out visitor
 * (guests off): the pages, the redirects and fetch answers, the CSRF and
 * permission refusals, the session id change on sign-in and sign-out, keepSignIn
 * on a new password and on sign out everywhere, the sign-in and registration
 * rate limits (per address and account, window expiry, cleared by a sign-in,
 * X-Forwarded-For ignored with trust_proxy off),
 * a failed statement (500, nothing changed) and the tables at the end. Answers
 * are byte-identical except the asset cache busters, the session id and the
 * per-session CSRF token.
 */
final class Ci4AuthParityTest extends TestCase
{
    private const PW = 'long-enough';

    public function testTheAuthPagesMatchLegacy(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('auth-' . $app);
            $server = new Server($dir, ['TRUSTED_PROXIES' => '127.0.0.1'], $app);
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
        $this->assertGreaterThan(80, count($answers['legacy']));
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
        // The cases are what they claim to be.
        $l = $answers['legacy'];
        $this->assertSame(200, $l['signed-out GET /login']['status']);
        $this->assertStringContainsString('id="login-form"', (string)$l['signed-out GET /login']['body']);
        $this->assertStringContainsString('id="login-guest"', (string)$l['guest GET /login']['body']);
        $this->assertSame([303, '/'], [$l['ada GET /login']['status'], $l['ada GET /login']['location']]);
        $this->assertSame(419, $l['login without csrf']['status']);
        $this->assertSame([303, '/'], [$l['ada POST /login without csrf']['status'], $l['ada POST /login without csrf']['location']], 'legacy redirects a signed-in visitor before the token check');
        $this->assertSame(401, $l['login wrong password']['status']);
        $this->assertSame($l['login wrong password']['body'], str_replace('nobody', 'vera', (string)$l['login unknown user']['body']), 'an unknown name answers as a wrong password');
        $this->assertSame([303, '/', true, true], [$l['login vera']['status'], $l['login vera']['location'], $l['login vera']['new session'], $l['login vera']['old session gone']]);
        $this->assertSame(403, $l['login pending']['status']);
        $this->assertSame([401, 401], [$l['login unknown user, NUL in password']['status'], $l['login wrong password, NUL in password']['status']]);
        $this->assertTrue($l['login unknown user']['waited']);
        $this->assertSame([429, 401, 429], [$l['login vera 11th']['status'], $l['login eda after vera is limited']['status'], $l['login vera right password while limited']['status']]);
        $this->assertSame(303, $l['login vera from another address']['status'], 'the limit is per address');
        $this->assertSame(303, $l['login vera after the window']['status']);
        $this->assertSame(0, $l['eda attempts after a sign-in']['n']);
        $this->assertSame(403, $l['account wrong current, counted']['status']);
        $this->assertSame(422, $l['account right current clears']['status'], (string)$l['account right current clears']['body']);
        $this->assertSame([1, 10, 0], [$l['eda attempts after a wrong current password']['n'], $l['eda attempts while limited']['n'], $l['eda attempts after the right current password']['n']]);
        $this->assertSame(429, $l['account current limited']['status']);
        $this->assertStringContainsString('Too many attempts for this account from this address', (string)$l['account current limited']['body']);
        $this->assertSame([429, 30, 0], [$l['account current, address capped']['status'], $l['eda address attempts while capped']['n'], $l['eda attempts while address capped']['n']], 'capped before the account count');
        $this->assertStringContainsString('Too many attempts from this address', (string)$l['account current, address capped']['body']);
        $this->assertSame([403, 6], [$l['account wrong current, address counted']['status'], $l['eda address attempts after a wrong current password']['n']]);
        $this->assertSame([422, 6], [$l['account right current, own address row back']['status'], $l['eda address attempts after the right current password']['n']], 'only its own address row is taken back');

        $this->assertSame(0, $l['vera attempts from the other address, cleared']['n']);
        $this->assertSame([401, 1, 0], [$l['login trust_proxy off']['status'], $l['login trust_proxy off']['peer'], $l['login trust_proxy off']['forwarded']], 'trust_proxy off: the peer address');
        $this->assertSame([303, '/?done=welcome'], [$l['register rex']['status'], $l['register rex']['location']]);
        $this->assertSame('/login?registered=pending', $l['register pia (approval)']['location']);
        $this->assertSame([303, 429, 422, 422], [$l['rex POST /register']['status'], $l['register 6th from one address']['status'], $l['register from another address']['status'], $l['register after the window']['status']]);
        $this->assertSame(404, $l['register closed']['status']);
        $this->assertSame([303, '/login'], [$l['logout ada']['status'], $l['logout ada']['location']]);
        $this->assertSame(419, $l['logout ada without csrf']['status']);
        $this->assertSame(303, $l['logout ended session without csrf']['status']);
        $this->assertSame([303, '/account?done=pw', true], [$l['eda new password']['status'], $l['eda new password']['location'], $l['eda new password']['kept signed_in_at']]);
        $this->assertSame([303, '/login'], [$l['eda other session after new password']['status'], $l['eda other session after new password']['location']]);
        $this->assertSame(200, $l['eda this session after new password']['status']);
        $this->assertSame([303, '/account?done=out', true], [$l['ada signs out everywhere']['status'], $l['ada signs out everywhere']['location'], $l['ada signs out everywhere']['kept signed_in_at']]);
        $this->assertSame(403, $l['account wrong current']['status']);
        $this->assertSame(403, $l['off GET /account']['status']);
        foreach (['fail register: users insert', 'fail register: attempts insert', 'fail login: attempts insert', 'fail login: attempts prune', 'fail login: last login', 'fail login: last login fetch', 'fail new password', 'fail current password: attempts insert', 'fail sign out everywhere'] as $case) {
            $this->assertSame([500, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
        }
        $this->assertStringContainsString('"rex"', (string)$l['tables']['body']);
    }

    /**
     * N-261, a deliberate difference until main's N-251 lands: a name with a
     * null character at either end. Legacy trims it into "rez" and creates that
     * account; CI4 refuses it (422, the invalid-name message) and creates none.
     * When main refuses it too, make this an equality check.
     */
    public function testANullCharacterInARegisteredNameIsRefusedOnCi4(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $seen = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('auth-nul-' . $app);
            $server = new Server($dir, [], $app);
            try {
                $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
                $pdo = testdb($dir);
                $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute(['ada', 'ada', password_hash(self::PW, PASSWORD_BCRYPT, ['cost' => 4]), 'admin', 'active', '2026-01-01 00:00:00']);
                $this->setting($pdo, 'auth_enabled', 'true');
                $this->setting($pdo, 'auth_register_disabled', 'false');
                foreach (["\0rez", "rez\0"] as $i => $name) {
                    $client = $server->client();
                    $token = bin2hex(random_bytes(32));
                    $sid = bin2hex(random_bytes(13));
                    file_put_contents($dir . '/sessions/sess_' . $sid, 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';');
                    $client->cookies['apothekesid'] = $sid;
                    $r = $client->req('POST', '/register', ['csrf' => $token, 'username' => $name, 'new' => self::PW, 'confirm' => self::PW]);
                    $users = $pdo->query('SELECT username FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
                    $seen[$app][] = [$r['status'], str_contains((string)$r['body'], 'User names use letters, digits and . _ @ - (at most 40 characters, no spaces).'), $users];
                    $pdo->exec("DELETE FROM users WHERE username <> 'ada'");
                }
            } finally {
                $server->stop();
                testdb_drop($dir);
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
        $this->assertSame([[303, false, ['ada', 'rez']], [303, false, ['ada', 'rez']]], $seen['legacy'], 'legacy trims the null character (N-251, open on main)');
        $this->assertSame([[422, true, ['ada']], [422, true, ['ada']]], $seen['ci4'], 'CI4 refuses the name as sent (N-261)');
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $add = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['ada', 'admin', 'active'], ['eda', 'editor', 'active'], ['vera', 'viewer', 'active'], ['Pam', 'viewer', 'pending']] as [$name, $role, $status]) {
            $add->execute([$name, mb_strtolower($name), password_hash(self::PW, PASSWORD_BCRYPT, ['cost' => 4]), $role, $status, '2026-01-01 00:00:00']);
        }
        $ids = [];
        foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) {
            $ids[$u['username']] = (int)$u['id'];
        }
        $this->setting($pdo, 'trust_proxy', 'true');
        $this->setting($pdo, 'auth_enabled', 'true');
        $this->setting($pdo, 'auth_guest_disabled', 'true');
        $address = 0;
        // A browser from its own address, holding a live session (with a CSRF token), signed in as $account when given.
        $browser = function (?string $account, int $signedInAt = 0) use ($server, $pdo, $ids, $dir, &$address): array {
            $client = $server->client();
            $ip = '10.0.0.' . (++$address);
            $token = bin2hex(random_bytes(32));
            $at = $signedInAt ?: time() - 100;
            $session = 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . $at . ';';
            if ($account !== null) {
                $row = $pdo->query('SELECT id, password_hash, session_epoch FROM users WHERE id = ' . $ids[$account])->fetch();
                $epoch = (string)$row['session_epoch'] !== '' ? (string)$row['session_epoch'] : substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16);
                $session = 'uid|i:' . $ids[$account] . ';auth_epoch|s:' . strlen($epoch) . ':"' . $epoch . '";' . $session;
            }
            $sid = bin2hex(random_bytes(13));
            file_put_contents($dir . '/sessions/sess_' . $sid, $session);
            $client->cookies['apothekesid'] = $sid;

            return [$client, $token, $ip, $sid];
        };
        $out = [];
        $record = function (string $case, array $r, string $token = '') use (&$out): void {
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
        $as = function (string $case, string $method, string $path, array $fields = [], array $headers = [], ?string $who = null, bool $csrf = true) use ($browser, $record): array {
            [$client, $token, $ip] = $browser($who);
            if ($method === 'POST' && $csrf) {
                $fields = ['csrf' => $token] + $fields;
            }
            $r = $client->req($method, $path, $fields, [], [...$headers, 'X-Forwarded-For: ' . $ip]);
            $record($case, $r, $token);

            return $r;
        };
        // Pages for every kind of visitor.
        foreach (['ada' => ['ada', 'true'], 'eda' => ['eda', 'true'], 'vera' => ['vera', 'true'], 'guest' => [null, 'false'], 'signed-out' => [null, 'true']] as $visitor => [$who, $guestOff]) {
            $this->setting($pdo, 'auth_guest_disabled', $guestOff);
            foreach (['/login', '/register', '/account', '/account?done=pw', '/account?done=out', '/login?registered=pending'] as $path) {
                $as($visitor . ' GET ' . $path, 'GET', $path, [], [], $who);
            }
            $as($visitor . ' GET /login fetch', 'GET', '/login', [], $fetch, $who);
            $as($visitor . ' HEAD /account', 'HEAD', '/account', [], [], $who);
            $as($visitor . ' POST /login without csrf', 'POST', '/login', ['username' => 'x', 'password' => 'y'], [], $who, false);
            $as($visitor . ' signout without csrf', 'POST', '/account/signout', [], [], $who, false);
            $as($visitor . ' signout fetch without csrf', 'POST', '/account/signout', [], $fetch, $who, false);
        }
        $this->setting($pdo, 'auth_guest_disabled', 'true');
        $as('GET /login?registered[]=x', 'GET', '/login?registered[]=x');
        $as('GET /login cookie-less', 'GET', '/login');
        $this->setting($pdo, 'auth_register_disabled', 'true');
        $as('GET /login, registration closed', 'GET', '/login');
        $as('register closed', 'GET', '/register');
        $as('register closed POST', 'POST', '/register', ['username' => 'zed', 'new' => self::PW, 'confirm' => self::PW], $fetch);
        $this->setting($pdo, 'auth_register_disabled', 'false');
        $this->setting($pdo, 'auth_register_role', '"editor"');
        $as('GET /register as editor', 'GET', '/register');
        $this->setting($pdo, 'auth_register_role', '"admin"');
        $this->setting($pdo, 'auth_register_approval', 'true');
        $as('GET /register admin role, approval', 'GET', '/register');

        // Sign-in.
        $as('login without csrf', 'POST', '/login', ['username' => 'vera', 'password' => self::PW], [], null, false);
        $as('login without csrf fetch', 'POST', '/login', ['username' => 'vera', 'password' => self::PW], $fetch, null, false);
        $as('login wrong password', 'POST', '/login', ['username' => ' vera ', 'password' => 'nope-nope']);
        $t = hrtime(true);
        $as('login unknown user', 'POST', '/login', ['username' => 'nobody', 'password' => 'nope-nope']);
        // Legacy waits 400 ms after a failed sign-in.
        $out['login unknown user']['waited'] = hrtime(true) - $t >= 400_000_000;
        $as('login unknown user, NUL in password', 'POST', '/login', ['username' => 'nobody', 'password' => "nope\0nope"]);
        $as('login wrong password, NUL in password', 'POST', '/login', ['username' => 'vera', 'password' => "nope\0nope"]);
        $as('login array name', 'POST', '/login', ['username' => ['a'], 'password' => 'x']);
        $as('login long name', 'POST', '/login', ['username' => str_repeat('ü ', 60), 'password' => 'x']);
        $as('login pending', 'POST', '/login', ['username' => 'pam', 'password' => self::PW]);
        [$client, $token, $ip, $sid] = $browser(null);
        $r = $client->req('POST', '/login', ['csrf' => $token, 'username' => 'VERA', 'password' => self::PW], [], ['X-Forwarded-For: ' . $ip]);
        $record('login vera', $r);
        $out['login vera']['new session'] = ($client->cookies['apothekesid'] ?? $sid) !== $sid;
        $out['login vera']['old session gone'] = !is_file($dir . '/sessions/sess_' . $sid);
        $record('vera GET /account after sign-in', $client->req('GET', '/account', [], [], ['X-Forwarded-For: ' . $ip]));
        // Rate limits: 10 sign-in attempts per address and account in 15 minutes.
        [$client, $token, $ip] = $browser(null);
        $try = static fn (string $who, string $pw, string $from) => $client->req('POST', '/login', ['csrf' => $token, 'username' => $who, 'password' => $pw], [], ['X-Forwarded-For: ' . $from]);
        for ($i = 1; $i <= 10; $i++) {
            $r = $try('vera', 'wrong-' . $i, $ip);
        }
        $record('login vera 10th', $r);
        $record('login vera 11th', $try('Vera ', 'wrong', $ip));
        $record('login vera right password while limited', $try('vera', self::PW, $ip));
        $record('login eda after vera is limited', $try('eda', 'wrong', $ip));
        $record('login vera from another address', $try('vera', self::PW, '10.9.9.9'));
        $pdo->exec('UPDATE auth_attempts SET at = at - 901');
        $record('login vera after the window', $try('vera', self::PW, $ip));
        [$client, $token, $ip] = $browser(null);
        $try('eda', 'wrong', $ip);
        $try('eda', self::PW, $ip);
        $bucket = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'login' AND client = ?");
        $bucket->execute([substr(hash('sha256', $ip . "\0" . 'eda'), 0, 32)]);
        $out['eda attempts after a sign-in'] = ['n' => (int)$bucket->fetchColumn()];
        $bucket->closeCursor();
        $bucket->execute([substr(hash('sha256', '10.9.9.9' . "\0" . 'vera'), 0, 32)]);
        $out['vera attempts from the other address, cleared'] = ['n' => (int)$bucket->fetchColumn()];
        $bucket->closeCursor();
        // trust_proxy off with a trusted proxy configured: X-Forwarded-For is ignored and the key is the peer address.
        $this->setting($pdo, 'trust_proxy', 'false');
        [$client, $token] = $browser(null);
        $record('login trust_proxy off', $client->req('POST', '/login', ['csrf' => $token, 'username' => 'eda', 'password' => 'wrong'], [], ['X-Forwarded-For: 10.7.7.7']));
        foreach (['peer' => '127.0.0.1', 'forwarded' => '10.7.7.7'] as $what => $from) {
            $bucket->execute([substr(hash('sha256', $from . "\0" . 'eda'), 0, 32)]);
            $out['login trust_proxy off'][$what] = (int)$bucket->fetchColumn();
            $bucket->closeCursor();
        }
        $this->setting($pdo, 'trust_proxy', 'true');

        // Registration (approval on from above, role admin falls back to viewer).
        $this->setting($pdo, 'auth_register_approval', 'false');
        $this->setting($pdo, 'auth_register_role', '"editor"');
        [$client, $token, $ip] = $browser(null);
        $reg = static fn (array $f) => $client->req('POST', '/register', ['csrf' => $token] + $f, [], ['X-Forwarded-For: ' . $ip]);
        $record('register taken', $reg(['username' => 'ADA', 'new' => self::PW, 'confirm' => self::PW]));
        $record('register bad name', $reg(['username' => 'a b<x>', 'new' => self::PW, 'confirm' => self::PW]));
        $record('register short password', $reg(['username' => 'rex', 'new' => 'short', 'confirm' => 'short']));
        $record('register mismatch', $reg(['username' => 'rex', 'new' => self::PW, 'confirm' => self::PW . 'x']));
        $record('register rex', $reg(['username' => ' rex ', 'new' => self::PW, 'confirm' => self::PW]));
        $record('rex GET /account', $client->req('GET', '/account', [], [], ['X-Forwarded-For: ' . $ip]));
        $record('rex POST /register', $reg(['username' => 'rey', 'new' => self::PW, 'confirm' => self::PW]));
        [$second, $token2] = $browser(null);
        $record('register 6th from one address', $second->req('POST', '/register', ['csrf' => $token2, 'username' => 'rey', 'new' => self::PW, 'confirm' => self::PW], [], ['X-Forwarded-For: ' . $ip]));
        $record('register from another address', $second->req('POST', '/register', ['csrf' => $token2, 'username' => 'rey', 'new' => 'short', 'confirm' => 'short'], [], ['X-Forwarded-For: 10.8.8.8']));
        $pdo->exec("UPDATE auth_attempts SET at = at - 3601 WHERE kind = 'register'");
        $record('register after the window', $second->req('POST', '/register', ['csrf' => $token2, 'username' => 'rey', 'new' => 'short', 'confirm' => 'short'], [], ['X-Forwarded-For: ' . $ip]));
        $this->setting($pdo, 'auth_register_approval', 'true');
        $as('register pia (approval)', 'POST', '/register', ['username' => 'pia', 'new' => self::PW, 'confirm' => self::PW]);
        $as('register without csrf', 'POST', '/register', ['username' => 'zoe', 'new' => self::PW, 'confirm' => self::PW], [], null, false);
        $as('ada POST /register', 'POST', '/register', ['username' => 'zoe', 'new' => self::PW, 'confirm' => self::PW], [], 'ada', false);

        // Sign-out.
        [$client, $token, $ip, $sid] = $browser('ada');
        $record('logout ada without csrf', $client->req('POST', '/logout', [], [], ['X-Forwarded-For: ' . $ip]));
        $r = $client->req('POST', '/logout', ['csrf' => $token], [], ['X-Forwarded-For: ' . $ip]);
        $record('logout ada', $r);
        $out['logout ada']['old session gone'] = !is_file($dir . '/sessions/sess_' . $sid);
        $record('ada GET /account after sign-out', $client->req('GET', '/account', [], [], ['X-Forwarded-For: ' . $ip]));
        [$client, , $ip] = $browser('ada');
        $pdo->exec("UPDATE users SET session_epoch = 'renewed' WHERE username = 'ada'");
        $record('logout ended session without csrf', $client->req('POST', '/logout', [], [], ['X-Forwarded-For: ' . $ip]));
        $as('logout without a session', 'POST', '/logout');
        $as('logout fetch', 'POST', '/logout', [], $fetch, 'eda');

        // Your account: a new password, sign out everywhere.
        $as('account wrong current', 'POST', '/account', ['current' => 'wrong', 'new' => 'new-password', 'confirm' => 'new-password'], [], 'eda');
        $as('account short', 'POST', '/account', ['current' => self::PW, 'new' => 'short', 'confirm' => 'short'], [], 'eda');
        $as('account mismatch', 'POST', '/account', ['current' => self::PW, 'new' => 'new-password', 'confirm' => 'other-password'], [], 'eda');
        $as('account without csrf', 'POST', '/account', ['current' => self::PW, 'new' => 'new-password', 'confirm' => 'new-password'], [], 'eda', false);
        // The current password is limited like a sign-in for that account from this address (#106).
        [$client, $token, $ip] = $browser('eda');
        $edaBucket = substr(hash('sha256', $ip . "\0eda"), 0, 32);
        $count = static function () use ($pdo, $edaBucket): array {
            $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'login' AND client = ?");
            $st->execute([$edaBucket]);
            $n = (int)$st->fetchColumn();
            $st->closeCursor();

            return ['n' => $n];
        };
        $own = static fn (string $current, string $new) => $client->req('POST', '/account', ['csrf' => $token, 'current' => $current, 'new' => $new, 'confirm' => $new], [], ['X-Forwarded-For: ' . $ip]);
        $record('account wrong current, counted', $own('wrong', 'new-password'));
        $out['eda attempts after a wrong current password'] = $count();
        $fill = $pdo->prepare("INSERT INTO auth_attempts (kind, client, at) VALUES ('login', ?, ?)");
        for ($i = 0; $i < 9; $i++) {
            $fill->execute([$edaBucket, time()]);
        }
        $record('account current limited', $own(self::PW, 'new-password'));
        $out['eda attempts while limited'] = $count();
        $pdo->prepare('DELETE FROM auth_attempts WHERE client = ? AND id IN (SELECT id FROM auth_attempts WHERE client = ? ORDER BY id DESC LIMIT 9)')->execute([$edaBucket, $edaBucket]);
        $record('account right current clears', $own(self::PW, 'short'));
        $out['eda attempts after the right current password'] = $count();
        // ... and shares the per-address cap of the sign-in form; a right one takes back only its own address row (#112, L-170).
        $addressBucket = substr(hash('sha256', $ip), 0, 32);
        $addressCount = static function () use ($pdo, $addressBucket): array {
            $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'login_address' AND client = ?");
            $st->execute([$addressBucket]);
            $n = (int)$st->fetchColumn();
            $st->closeCursor();

            return ['n' => $n];
        };
        $pdo->prepare("DELETE FROM auth_attempts WHERE kind = 'login_address' AND client = ?")->execute([$addressBucket]);
        $fillAddress = $pdo->prepare("INSERT INTO auth_attempts (kind, client, at) VALUES ('login_address', ?, ?)");
        for ($i = 0; $i < 30; $i++) {
            $fillAddress->execute([$addressBucket, time()]);
        }
        $record('account current, address capped', $own(self::PW, 'short'));
        $out['eda address attempts while capped'] = $addressCount();
        $out['eda attempts while address capped'] = $count();
        $pdo->prepare("DELETE FROM auth_attempts WHERE kind = 'login_address' AND client = ? AND id IN (SELECT id FROM auth_attempts WHERE kind = 'login_address' AND client = ? ORDER BY id DESC LIMIT 25)")->execute([$addressBucket, $addressBucket]);
        $record('account wrong current, address counted', $own('wrong', 'new-password'));
        $out['eda address attempts after a wrong current password'] = $addressCount();
        $record('account right current, own address row back', $own(self::PW, 'short'));
        $out['eda address attempts after the right current password'] = $addressCount();
        [$other] = $browser('eda');
        $at = time() - 5000;
        [$client, $token, $ip, $sid] = $browser('eda', $at);
        $record('eda new password', $client->req('POST', '/account', ['csrf' => $token, 'current' => self::PW, 'new' => 'new-password', 'confirm' => 'new-password'], [], ['X-Forwarded-For: ' . $ip]));
        $out['eda new password']['kept signed_in_at'] = str_contains((string)@file_get_contents($dir . '/sessions/sess_' . ($client->cookies['apothekesid'] ?? '')), 'signed_in_at|i:' . $at . ';');
        $record('eda this session after new password', $client->req('GET', '/account?done=pw', [], [], ['X-Forwarded-For: ' . $ip]));
        $record('eda other session after new password', $other->req('GET', '/account'));
        $pdo->exec("UPDATE users SET session_epoch = '' WHERE username = 'ada'");
        [$other] = $browser('ada');
        [$client, $token, $ip] = $browser('ada', $at);
        $record('ada signs out everywhere', $client->req('POST', '/account/signout', ['csrf' => $token], [], ['X-Forwarded-For: ' . $ip]));
        $out['ada signs out everywhere']['kept signed_in_at'] = str_contains((string)@file_get_contents($dir . '/sessions/sess_' . ($client->cookies['apothekesid'] ?? '')), 'signed_in_at|i:' . $at . ';');
        $record('ada this session after sign out everywhere', $client->req('GET', '/account?done=out', [], [], ['X-Forwarded-For: ' . $ip]));
        $record('ada other session after sign out everywhere', $other->req('GET', '/account'));
        $as('ada signs out everywhere fetch', 'POST', '/account/signout', [], $fetch, 'ada');
        $as('vera new password fetch', 'POST', '/account', ['current' => 'wrong'], $fetch, 'vera');

        // Sign-in off: the anonymous admin has no account.
        $this->setting($pdo, 'auth_enabled', 'false');
        $as('off GET /login', 'GET', '/login');
        $as('off GET /register', 'GET', '/register');
        $as('off GET /account', 'GET', '/account');
        $as('off POST /account/signout', 'POST', '/account/signout');
        $as('off logout', 'POST', '/logout');
        $this->setting($pdo, 'auth_enabled', 'true');

        // A statement that fails (a trigger stands in for a broken disk or constraint) is a 500 and changes nothing.
        $snapshot = static fn (): array => [json_encode($pdo->query('SELECT id, username, password_hash, status, session_epoch, last_login_at FROM users ORDER BY id')->fetchAll()),
            json_encode($pdo->query('SELECT kind, client, at FROM auth_attempts ORDER BY id')->fetchAll())];
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        $this->setting($pdo, 'auth_register_approval', 'false');
        foreach ([['fail register: users insert', 'users', 'INSERT', '/register', ['username' => 'fay', 'new' => self::PW, 'confirm' => self::PW], null],
            ['fail register: attempts insert', 'auth_attempts', 'INSERT', '/register', ['username' => 'fay', 'new' => self::PW, 'confirm' => self::PW], null],
            ['fail login: attempts insert', 'auth_attempts', 'INSERT', '/login', ['username' => 'vera', 'password' => self::PW], null],
            ['fail login: attempts prune', 'auth_attempts', 'DELETE', '/login', ['username' => 'vera', 'password' => self::PW], null],
            ['fail login: last login', 'users', 'UPDATE', '/login', ['username' => 'vera', 'password' => self::PW], null],
            ['fail login: last login fetch', 'users', 'UPDATE', '/login', ['username' => 'vera', 'password' => self::PW], null],
            ['fail new password', 'users', 'UPDATE', '/account', ['current' => self::PW, 'new' => 'newer-password', 'confirm' => 'newer-password'], 'vera'],
            ['fail current password: attempts insert', 'auth_attempts', 'INSERT', '/account', ['current' => self::PW, 'new' => 'newer-password', 'confirm' => 'newer-password'], 'vera'],
            ['fail sign out everywhere', 'users', 'UPDATE', '/account/signout', [], 'vera']] as [$case, $table, $op, $path, $fields, $who]) {
            $pdo->exec($pg ? "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()"
                : "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            if ($op === 'DELETE') {
                $pdo->exec($pg ? 'ALTER TABLE auth_attempts DISABLE TRIGGER apotheke_test_fail' : 'SELECT 1');
                $pdo->exec("INSERT INTO auth_attempts (kind, client, at) VALUES ('login', 'old', 1)");
                if ($pg) {
                    $pdo->exec('ALTER TABLE auth_attempts ENABLE TRIGGER apotheke_test_fail');
                }
            }
            $before = $snapshot();
            $as($case, 'POST', $path, $fields, str_ends_with($case, 'fetch') ? $fetch : [], $who);
            $after = $snapshot();
            // The attempt is counted before the account write, as legacy does; the failing table stays as it was.
            $out[$case]['unchanged'] = $table === 'users' ? $before[0] === $after[0] : $before === $after;
            $pdo->exec($pg ? "DROP TRIGGER apotheke_test_fail ON $table" : 'DROP TRIGGER apotheke_test_fail');
        }

        $rows = $pdo->query('SELECT id, username, username_key, role, status, created_at, last_login_at, password_hash, session_epoch FROM users ORDER BY id')->fetchAll();
        foreach ($rows as &$row) {
            $row['last_login_at'] = $row['last_login_at'] === null ? null : 'set';
            $row['created_at'] = $row['created_at'] === '2026-01-01 00:00:00' ? 'seed' : 'new';
            $row['password_hash'] = substr((string)$row['password_hash'], 0, 7);
            $row['session_epoch'] = in_array((string)$row['session_epoch'], ['', 'renewed'], true) ? (string)$row['session_epoch'] : 'renewed here';
            $row = array_map(static fn ($v) => $v === null ? null : (string)$v, $row);
        }
        $attempts = $pdo->query('SELECT kind, COUNT(*) AS n FROM auth_attempts GROUP BY kind ORDER BY kind')->fetchAll();
        $out['tables'] = ['body' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];
        $out['auth attempts'] = $attempts;

        return $out;
    }

    private function setting(PDO $pdo, string $name, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, $value, '2026-01-01']);
    }
}
