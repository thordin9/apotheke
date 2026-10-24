<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/** SEC-M3 on the CI4 dev server: the Host allow-list in front of /healthz and every route. */
final class Ci4RequestHardeningHttpTest extends TestCase
{
    private const REFUSAL = "Unknown host name. Open the app by its address, or add this name to ALLOWED_HOSTS (or PUBLIC_URL).\n";

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
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
