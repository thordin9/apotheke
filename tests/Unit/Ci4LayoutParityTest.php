<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/legacy-bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/views.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Layout/Icons.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Layout/Theme.php';

use Apotheke\Layout\Icons;
use Apotheke\Layout\Theme;

/**
 * Golden (PLAN PR 8, Layout): the CI4 page shell against legacy. The icon
 * set, safe_back() and the bundled assets; then the same requests to a
 * legacy and a CI4 server with the same data: the error pages (404, the
 * Permission filter's 403, CSRF 419, the same-origin 403) for every kind of
 * visitor, theme and branding, POST /theme, and the menu and simple header.
 * Bodies are byte-identical except the asset cache busters (file times) and
 * the per-session CSRF token.
 */
final class Ci4LayoutParityTest extends TestCase
{
    /** @var array<string,array<string,array{0:int,1:?string}>> app => case => [status, Location] (N-18) */
    private array $controlBack = [];

    public function testIconsAndSafeBackMatchLegacy(): void
    {
        $this->assertGreaterThan(30, count(Icons::names()));
        foreach ([...Icons::names(), 'no-such-icon'] as $name) {
            $this->assertSame(icon($name), Icons::svg($name), $name);
        }
        foreach (['/', '/x?y=1', '//evil.example', '/\\evil', 'https://evil.example/', '/a b', '', 'x', "/a\nb", '/ok/../x'] as $back) {
            $this->assertSame(safe_back($back), Theme::safeBack($back), json_encode($back));
        }
        foreach (['auto', 'light', 'dark', 'bogus', '', null, ['dark']] as $cookie) {
            $this->assertSame(in_array($cookie, THEMES, true) ? $cookie : 'dark', Theme::pref($cookie, 'dark'), json_encode($cookie));
        }
    }

    /** The CI4 copy of the bundled assets is byte-identical to legacy public/assets. */
    public function testTheBundledAssetsAreTheLegacyFiles(): void
    {
        $list = static function (string $dir): array {
            $out = [];
            foreach (scandir($dir) ?: [] as $f) {
                if ($f[0] !== '.' && is_file("$dir/$f")) {
                    $out[$f] = hash_file('sha256', "$dir/$f");
                }
            }

            return $out;
        };
        $legacy = $list(dirname(__DIR__, 2) . '/public/assets');
        $this->assertNotEmpty($legacy);
        $this->assertSame($legacy, $list(dirname(__DIR__, 2) . '/ci4/public/assets'));
    }

    public function testTheSameRequestsGetTheSamePages(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('layout-' . $app);
            $server = new Server($dir, $app === 'ci4' ? ['APOTHEKE_TEST_HTML_PAGE' => '1'] : [], $app);
            try {
                $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
                $answers[$app] = $this->visit($server, $dir, $app);
            } finally {
                $server->stop();
                testdb_drop($dir);
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
        $this->assertGreaterThan(40, count($answers['legacy']));
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
        // The cases are what they claim to be.
        $legacy = $answers['legacy'];
        $this->assertSame(404, $legacy['off 404 fresh']['status']);
        $this->assertStringContainsString('<p>Page not found.</p>', (string)$legacy['off 404 fresh']['body']);
        $this->assertStringContainsString('<meta name="csrf" content="">', (string)$legacy['off 404 fresh']['body']);
        $this->assertStringContainsString('<meta name="csrf" content="SESSION-TOKEN">', (string)$legacy['vera 404 session']['body']);
        $this->assertSame(403, $legacy['dee suggest']['status']);
        $this->assertStringContainsString('<body class="role-desk no-create no-edit', (string)$legacy['dee suggest']['body']);
        $this->assertStringContainsString('Help &lt;desk&gt; &amp; &quot;Co&quot;', (string)$legacy['dee suggest']['body']);
        $this->assertStringContainsString('is-guest', (string)$legacy['guest 404 session']['body']);
        $this->assertSame(419, $legacy['ada suggest no csrf']['status']);
        $this->assertSame([303, '/x?y=1'], [$legacy['ada theme dark']['status'], $legacy['ada theme dark']['location']]);
        $this->assertSame('/', $legacy['ada theme light']['location']);
        $this->assertSame('theme=auto; Max-Age=34560000; path=/; SameSite=Lax', $legacy['ada theme bogus']['cookie']);
        $this->assertSame(['ok' => true, 'theme' => 'light'], $legacy['ada theme fetch']['json']);
        $this->assertSame(403, $legacy['cross-site']['status']);
        $this->assertStringContainsString('id="menu-account"', (string)$legacy['o<b> menu auto']['menu']);
        $this->assertStringContainsString('o&lt;b&gt;', (string)$legacy['o<b> menu auto']['menu']);
        $this->assertStringContainsString('id="menu-login"', (string)$legacy['guest menu auto']['menu']);
        $this->assertStringContainsString('aria-checked="true" class="seg-btn on"><svg', (string)$legacy['off menu dark']['menu']);
        $this->assertStringContainsString('<h1 class="title-sm">Your account</h1>', (string)$legacy['vera header']['header']);
        $this->assertStringContainsString('id="accent-css"', (string)$legacy['branded']['body']);
        $this->assertStringContainsString('&lt;Box&gt; &amp; &quot;Co&quot;', (string)$legacy['branded']['body']);
        $this->assertStringContainsString('data-theme="dark"', (string)$legacy['cookie dark']['body']);
        // N-18: CI4 answers 303 to "/" where legacy redirects to the raw path (or, for NUL, cannot redirect at all).
        $this->assertSame(['nul' => [200, null], 'soh' => [303, "/a\x01b"], 'del' => [303, "/a\x7fb"]], $this->controlBack['legacy']);
        $this->assertSame(['nul' => [303, '/'], 'soh' => [303, '/'], 'del' => [303, '/']], $this->controlBack['ci4']);
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir, string $app): array
    {
        $pdo = testdb($dir);
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('desk', 'Help <desk> & \"Co\"', '[\"view\",\"settings\"]', 0, '2026-01-01 00:00:00')");
        $ids = [];
        foreach (['ada' => 'admin', 'eda' => 'editor', 'vera' => 'viewer', 'dee' => 'desk', 'o<b>' => 'viewer'] as $name => $role) {
            $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
            $ids[$name] = (int)$pdo->query("SELECT id FROM users WHERE username_key = " . $pdo->quote($name))->fetchColumn();
        }
        // A browser holding a live session (with a CSRF token), signed in as $account when given.
        $signIn = function (?string $account) use ($server, $pdo, $ids, $dir): array {
            $client = $server->client();
            $token = bin2hex(random_bytes(32));
            $session = 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . time() . ';';
            if ($account !== null) {
                $row = $pdo->query('SELECT id, password_hash FROM users WHERE id = ' . $ids[$account])->fetch();
                $epoch = substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16);
                $session = 'uid|i:' . $ids[$account] . ';auth_epoch|s:16:"' . $epoch . '";' . $session;
            }
            $sid = bin2hex(random_bytes(13));
            file_put_contents($dir . '/sessions/sess_' . $sid, $session);
            $client->cookies['apothekesid'] = $sid;

            return [$client, $token];
        };
        $out = [];
        $record = function (string $case, array $r, ?string $token = null) use (&$out): void {
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            if ($token !== null) {
                $body = str_replace($token, 'SESSION-TOKEN', (string)$body);
            }
            $cookie = $r['headers']['set-cookie'] ?? null;
            $answer = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $r['headers']['location'] ?? null,
                'json' => $r['json'], 'body' => $r['json'] === null ? $body : null,
                'cookie' => $cookie === null ? null : preg_replace('#expires=X; #', '', cookie_times_settled(str_starts_with($cookie, 'apothekesid=') ? 'apothekesid=SID' : $cookie))];
            foreach (['x-content-type-options', 'x-frame-options', 'referrer-policy', 'content-security-policy'] as $name) {
                $answer[$name] = $r['headers'][$name] ?? null;
            }
            $out[$case] = $answer;
        };
        $visitors = ['off' => null, 'signed-out' => null, 'guest' => null, 'ada' => 'ada', 'eda' => 'eda', 'vera' => 'vera', 'dee' => 'dee', 'o<b>' => 'o<b>'];
        foreach ($visitors as $visitor => $account) {
            $this->setting($pdo, 'auth_enabled', $visitor === 'off' ? 'false' : 'true');
            $this->setting($pdo, 'auth_guest_disabled', $visitor === 'guest' ? 'false' : 'true');
            // 404 without a session, then with one (the page shows that session's token, never starts one).
            $record($visitor . ' 404 fresh', $server->client()->get('/no-such-page'));
            [$client, $token] = $signIn($account);
            $record($visitor . ' 404 session', $client->get('/no-such-page'), $token);
            // The Permission filter's 403 (Add items), the CSRF 419, a fetch.
            [$client, $token] = $signIn($account);
            $record($visitor . ' suggest', $client->post('/autofill/suggest', ['csrf' => $token]), $token);
            [$client, $token] = $signIn($account);
            $record($visitor . ' suggest no csrf', $client->post('/autofill/suggest', []), $token);
            [$client, $token] = $signIn($account);
            $record($visitor . ' token no csrf fetch', $client->post('/settings/autofill/token', [], [], ['X-Requested-With: fetch']), $token);
            // POST /theme: cookie and the way back, or JSON.
            foreach (['dark' => '/x?y=1', 'light' => '//evil.example', 'bogus' => '/a b'] as $theme => $back) {
                [$client, $token] = $signIn($account);
                $record($visitor . ' theme ' . $theme, $client->post('/theme', ['csrf' => $token, 'theme' => $theme, 'back' => $back]), $token);
            }
            [$client, $token] = $signIn($account);
            $record($visitor . ' theme fetch', $client->post('/theme', ['csrf' => $token, 'theme' => 'light'], [], ['X-Requested-With: fetch']), $token);
            [$client, $token] = $signIn($account);
            $record($visitor . ' theme no csrf', $client->post('/theme', ['theme' => 'dark']), $token);
            // The menu (legacy: the list page's header) and the simple header (legacy: /account).
            foreach (['auto', 'dark'] as $theme) {
                [$client, $token] = $signIn($account);
                $client->cookies['theme'] = $theme;
                $page = $client->get($app === 'ci4' ? '/test-layout-menu' : '/');
                $menu = preg_match('#<details class="menu" id="menu">.*?</details>#s', $page['body'], $m) ? $m[0] : 'status ' . $page['status'];
                $out[$visitor . ' menu ' . $theme] = ['menu' => preg_replace('#name="back" value="[^"]*"#', 'name="back" value="BACK"', str_replace($token, 'SESSION-TOKEN', $menu))];
            }
            if ($account !== null) {
                [$client, $token] = $signIn($account);
                $page = $client->get($app === 'ci4' ? '/test-layout-header' : '/account');
                $header = preg_match('#<header class="top top-simple">.*?</header>#s', $page['body'], $m) ? $m[0] : 'status ' . $page['status'];
                $out[$visitor . ' header'] = ['header' => preg_replace('#name="back" value="[^"]*"#', 'name="back" value="BACK"', str_replace($token, 'SESSION-TOKEN', $header))];
            }
        }
        // Sign-in off: a form from another site (SEC-M3), theme cookies, branding and escaping.
        $this->setting($pdo, 'auth_enabled', 'false');
        $record('cross-site', $server->client()->post('/settings/autofill/token', [], [], ['Origin: http://other.example']));
        foreach (['light', 'dark', 'auto', 'bogus'] as $theme) {
            $client = $server->client();
            $client->cookies['theme'] = $theme;
            $record('cookie ' . $theme, $client->get('/no-such-page'));
        }
        $this->setting($pdo, 'theme_default', '"dark"');
        $this->setting($pdo, 'accent', '"#C8502D"');
        $this->setting($pdo, 'app_name', '"<Box> & \"Co\""');
        $this->setting($pdo, 'app_tagline', '"tag <i>x</i>"');
        $record('branded', $server->client()->get('/no-such-page'));
        // N-18: a control character in `back`. Legacy sends \x01 and DEL on in the Location header and
        // drops a NUL one (a blank 200); CI4 refuses all three in a header, so it goes back to "/" (303).
        foreach (['nul' => "/a\0b", 'soh' => "/a\x01b", 'del' => "/a\x7fb"] as $name => $back) {
            [$client, $token] = $signIn(null);
            $r = $client->post('/theme', ['csrf' => $token, 'theme' => 'dark', 'back' => $back]);
            $location = $r['headers']['location'] ?? null;
            $this->controlBack[$app][$name] = [$r['status'], $location];
            $out['theme back ' . $name] = ['cookie' => preg_replace('#expires=X; #', '', cookie_times_settled((string)($r['headers']['set-cookie'] ?? ''))),
                'redirect' => $name === 'nul' ? null : $r['status']];
        }
        return $out;
    }

    private function setting(PDO $pdo, string $name, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, $value, '2026-01-01']);
    }
}
