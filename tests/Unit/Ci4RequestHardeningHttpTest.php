<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * The CI4 dev server: the Host allow-list in front of /healthz and every route
 * and the same-origin rule with sign-in off (SEC-M3), sessions on demand
 * (SEC-L2) and the idle limit (SEC-N6).
 */
final class Ci4RequestHardeningHttpTest extends TestCase
{
    private const CROSS_SITE = 'This request came from another site and was refused. Open the app directly and try again.';

    private const REFUSAL = "Unknown host name. Open the app by its address, or add this name to ALLOWED_HOSTS (or PUBLIC_URL).\n";

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
    }

    public function testUnknownHostNamesGet421WithoutTheName(): void
    {
        $this->withServer([], function (Server $server, string $dir): void {
            $client = $server->client();
            foreach (['/healthz', '/health', '/theme.css', '/no-such-page'] as $path) {
                $refused = $client->get($path, ['Host: rebind-check.example:9091']);
                $this->assertSame(421, $refused['status'], $path);
                $this->assertSame(self::REFUSAL, $refused['body'], $path);
                // php -S itself echoes the request's Host line; every other header comes from the app.
                $raw = array_filter($refused['raw'], static fn (string $line): bool => stripos($line, 'Host:') !== 0);
                $this->assertStringNotContainsString('rebind-check', implode("\n", $raw), $path);
                $this->assertSame('no-store', $refused['headers']['cache-control'] ?? '', $path);
                $this->assertSame('text/plain; charset=UTF-8', $refused['headers']['content-type'] ?? '', $path);
                $this->assertSame('nosniff', $refused['headers']['x-content-type-options'] ?? '', $path);
                $this->assertArrayNotHasKey('set-cookie', $refused['headers'], $path);
            }
            foreach (['127.0.0.1:9091', '[::1]:9091', 'localhost', 'App.Localhost:9091', strtolower((string)gethostname())] as $host) {
                $this->assertSame(200, $client->get('/healthz', ['Host: ' . $host])['status'], $host);
            }
            foreach (['bad host', 'a..b', '::1', 'localhost:0'] as $host) {
                $this->assertSame(421, $client->get('/healthz', ['Host: ' . $host])['status'], $host);
            }
            // The Control Panel's public address counts too (legacy reads setting('public_url')).
            $pdo = testdb($dir);
            $pdo->exec("DELETE FROM settings WHERE name = 'public_url'");
            $pdo->exec("INSERT INTO settings (name, value, updated_at) VALUES ('public_url', '\"https://shelf.example\"', '2026-01-01')");
            $this->assertSame(200, $client->get('/healthz', ['Host: shelf.example'])['status']);
            $this->assertSame(421, $client->get('/healthz', ['Host: other.example'])['status']);
        });
    }

    public function testPublicUrlAndAllowedHostsAreAccepted(): void
    {
        $this->withServer(['PUBLIC_URL' => 'https://inv.example.org:8443', 'ALLOWED_HOSTS' => 'apotheke.lan, stock.example.net:9091'], function (Server $server): void {
            $client = $server->client();
            foreach (['inv.example.org', 'INV.example.org:8443', 'apotheke.lan:9091', 'stock.example.net', '10.0.0.5'] as $host) {
                $this->assertSame(200, $client->get('/healthz', ['Host: ' . $host])['status'], $host);
            }
            foreach (['example.org', 'x.inv.example.org', 'lan'] as $host) {
                $this->assertSame(421, $client->get('/healthz', ['Host: ' . $host])['status'], $host);
            }
        });
        $this->withServer(['ALLOWED_HOSTS' => '*'], function (Server $server): void {
            $this->assertSame(200, $server->client()->get('/healthz', ['Host: anything.example'])['status']);
            $this->assertSame(421, $server->client()->get('/healthz', ['Host: bad host'])['status']);
        });
    }

    public function testCrossSiteWritesAreRefusedWithSignInOff(): void
    {
        $this->withServer([], function (Server $server, string $dir): void {
            $client = $server->client();
            foreach (['/no-such-page', '/healthz', '/settings'] as $path) {
                $refused = $client->post($path, [], [], ['Origin: http://other.example']);
                $this->assertSame(403, $refused['status'], $path);
                $this->assertStringContainsString('<p>' . self::CROSS_SITE . '</p>', $refused['body'], $path);
                $this->assertStringStartsWith('text/html', $refused['headers']['content-type'] ?? '', $path);
                $this->assertSame('nosniff', $refused['headers']['x-content-type-options'] ?? '', $path);
            }
            $json = $client->post('/no-such-page', [], [], ['Sec-Fetch-Site: cross-site', 'Accept: application/json']);
            $this->assertSame(403, $json['status']);
            $this->assertSame(['ok' => false, 'error' => self::CROSS_SITE], $json['json']);
            foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
                $this->assertSame(403, $client->req($method, '/x', [], [], ['Sec-Fetch-Site: same-site'])['status'], $method);
            }
            // Same origin, no headers at all (curl), and safe methods go on to routing (no POST route exists yet).
            foreach ([['Sec-Fetch-Site: same-origin'], ['Sec-Fetch-Site: none'], ['Origin: ' . $server->base], []] as $headers) {
                $this->assertSame(404, $client->post('/no-such-page', [], [], $headers)['status'], implode(' ', $headers));
            }
            $this->assertSame(404, $client->get('/no-such-page', ['Origin: http://other.example', 'Sec-Fetch-Site: cross-site'])['status']);
            // Sign-in on: sign-in and the session CSRF token protect writes, not this rule. Not signed in,
            // the Permission filter sends the request to /login, as legacy does.
            $pdo = testdb($dir);
            $pdo->exec("INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES ('root', 'root', 'x', 'admin', 'active', '2026-01-01')");
            $pdo->exec("DELETE FROM settings WHERE name = 'auth_enabled'");
            $pdo->exec("INSERT INTO settings (name, value, updated_at) VALUES ('auth_enabled', 'true', '2026-01-01')");
            $signedOut = $client->post('/no-such-page', [], [], ['Origin: http://other.example']);
            $this->assertSame([303, '/login'], [$signedOut['status'], $signedOut['headers']['location'] ?? null]);
        });
    }

    /** #91 N-15: a path with characters the router refuses is a 400, not a 500, and is not logged as an error. */
    public function testDisallowedPathCharactersGet400(): void
    {
        $this->withServer([], function (Server $server): void {
            $client = $server->client();
            foreach (['/a;b', '/settings%3B', '/a%01b', '/a%0Ab', '/%00'] as $path) {
                $r = $client->get($path);
                $this->assertSame(400, $r['status'], $path);
                $this->assertSame('Bad request', $r['body'], $path);
                $this->assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null, $path);
            }
            $this->assertFalse($server->logHas('BadRequestException'), 'not logged as a server error');
            $this->assertSame(200, $client->get('/healthz')['status']);
        });
    }

    public function testAnonymousRequestsLeaveNoSession(): void
    {
        $this->withServer([], function (Server $server, string $dir): void {
            $client = $server->client();
            // The list (GET /) carries a CSRF token, so it starts a session as on legacy.
            foreach (['/no-such-page', '/stats', '/theme.css', '/healthz'] as $path) {
                $this->assertArrayNotHasKey('set-cookie', $client->get($path)['headers'], $path);
            }
            // A cookie whose session is gone (expired, or made up) does not create one either.
            $client->cookies['apothekesid'] = str_repeat('d', 26);
            $this->assertArrayNotHasKey('set-cookie', $client->get('/no-such-page')['headers']);
            $this->assertSame([], glob($dir . '/sessions/sess_*') ?: []);
            // A live session is loaded, stamped and given a token, without a new cookie.
            $id = str_repeat('e', 26);
            file_put_contents($dir . '/sessions/sess_' . $id, 'uid|i:3;');
            $client->cookies['apothekesid'] = $id;
            $this->assertArrayNotHasKey('set-cookie', $client->get('/no-such-page')['headers']);
            $data = (string)file_get_contents($dir . '/sessions/sess_' . $id);
            $this->assertMatchesRegularExpression('/csrf\|s:64:"[0-9a-f]{64}"/', $data);
            $this->assertMatchesRegularExpression('/last_activity\|i:\d+;/', $data);
            $this->assertStringContainsString('uid|i:3;', $data);
        });
    }

    public function testAnIdleSessionEnds(): void
    {
        $this->withServer(['APOTHEKE_TEST_SESSION_ON_DEMAND' => '1'], function (Server $server, string $dir): void {
            $client = $server->client();
            $first = $client->get('/');
            $this->assertStringContainsString('apothekesid=', $first['headers']['set-cookie'] ?? '');
            $id = (string)$client->cookies['apothekesid'];
            $file = $dir . '/sessions/sess_' . $id;
            $this->assertFileExists($file);
            $this->assertArrayNotHasKey('set-cookie', $client->get('/')['headers'], 'the same session');
            // Used 29 days ago: still inside session_days (30), so it is kept and re-stamped.
            $recent = time() - 29 * 86400;
            file_put_contents($file, preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . $recent . ';', (string)file_get_contents($file)));
            $this->assertArrayNotHasKey('set-cookie', $client->get('/')['headers']);
            $this->assertDoesNotMatchRegularExpression('/last_activity\|i:' . $recent . ';/', (string)file_get_contents($file));
            // Not used for 31 days: emptied and removed; this request gets a new session.
            file_put_contents($file, preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . (time() - 31 * 86400) . ';', (string)file_get_contents($file)));
            $after = $client->get('/');
            $this->assertFileDoesNotExist($file);
            $this->assertStringContainsString('apothekesid=', $after['headers']['set-cookie'] ?? '');
            $this->assertNotSame($id, $client->cookies['apothekesid']);
        });
    }

    /**
     * Main #84 on CI4: a signed-in session ends after session_idle_hours with no
     * request, and session_days after sign-in even while it is in use. A
     * session from before the sign-in time existed gets one and stays.
     */
    public function testTheIdleAndAbsoluteLimitsEndASignedInSession(): void
    {
        $this->withServer([], function (Server $server, string $dir): void {
            $client = $server->client();
            $now = time();
            $write = function (string $id, string $data) use ($dir, $client): string {
                file_put_contents($dir . '/sessions/sess_' . $id, $data);
                $client->cookies['apothekesid'] = $id;

                return $dir . '/sessions/sess_' . $id;
            };
            $old = $write(str_repeat('a', 26), 'uid|i:3;last_activity|i:' . ($now - 30) . ';');
            $client->get('/');
            $this->assertMatchesRegularExpression('/signed_in_at\|i:\d+;/', (string)file_get_contents($old), 'stamped, not ended');

            $pdo = testdb($dir);
            $pdo->exec("UPDATE settings SET value = '1' WHERE name = 'session_idle_hours'");
            $idle = $write(str_repeat('b', 26), 'uid|i:3;last_activity|i:' . ($now - 2 * 3600) . ';signed_in_at|i:' . ($now - 3 * 3600) . ';');
            $client->get('/');
            $this->assertFileDoesNotExist($idle, 'two hours idle with a one-hour limit');
            $kept = $write(str_repeat('c', 26), 'uid|i:3;last_activity|i:' . ($now - 1800) . ';signed_in_at|i:' . ($now - 3 * 3600) . ';');
            $client->get('/');
            $this->assertFileExists($kept, 'half an hour idle');

            $long = $write(str_repeat('d', 26), 'uid|i:3;last_activity|i:' . ($now - 30) . ';signed_in_at|i:' . ($now - 31 * 86400) . ';');
            $client->get('/');
            $this->assertFileDoesNotExist($long, 'signed in 31 days ago, in use');
        });
    }

    /**
     * @param array<string,string|null> $env
     * @param Closure(Server,string):void $test
     */
    private function withServer(array $env, Closure $test): void
    {
        $dir = tmpdir('ci4-hard');
        $server = new Server($dir, $env, 'ci4');
        try {
            $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
            $test($server, $dir);
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }
}
