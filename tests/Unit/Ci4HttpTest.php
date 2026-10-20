<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/** The CI4 dev server against the legacy health contract. */
final class Ci4HttpTest extends TestCase
{
    public function testHealthOriginalsCookiesAndRouter(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $legacyDir = tmpdir('ci4-leg');
        $ci4Dir = tmpdir('ci4-app');
        $legacy = new Server($legacyDir, [], 'legacy');
        $ci4 = new Server($ci4Dir, [], 'ci4');
        $asset = dirname(__DIR__, 2) . '/ci4/public/assets/ping.txt';
        try {
            $this->compareHealth($legacy, $ci4);
            $this->assertTrue($ci4->logHas('apotheke-harness app=ci4'));
            $this->assertFalse($legacy->logHas('apotheke-harness app=ci4'));
            if (test_driver() === 'pgsql') {
                $this->assertGreaterThan(0, $this->probeRows(), 'CI4 pgsql leg did not write apotheke_ci4_pgsql_probe');
                $this->cookies($ci4, null);
            } else {
                $pdo = new PDO('sqlite:' . $ci4Dir . '/catalog.sqlite');
                $this->assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
                $this->cookies($ci4, $pdo);
            }
            $this->originals($legacy->client(), $ci4->client());
            $this->assertSame(0750, fileperms($ci4Dir . '/originals') & 0777);
            $this->assertSame(0640, fileperms($ci4Dir . '/originals/.htaccess') & 0777);
            $this->router($ci4, $asset);
            $this->head($ci4);
        } finally {
            $legacy->stop();
            $ci4->stop();
            @unlink($asset);
            testdb_drop($legacyDir);
            testdb_drop($ci4Dir);
            rrmdir($legacyDir);
            rrmdir($ci4Dir);
            @unlink($legacyDir . '.server.log');
            @unlink($ci4Dir . '.server.log');
        }
    }

    public function testSymlinkIntoTheWebRootRefusesWithAGenericPage(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $web = dirname(__DIR__, 2) . '/ci4/public/hidden-originals';
        $link = sys_get_temp_dir() . '/apo-orig-link-' . bin2hex(random_bytes(3));
        mkdir($web, 0775, true);
        symlink($web, $link);
        $dir = tmpdir('ci4-refuse');
        $server = new Server($dir, ['ORIGINALS_DIR' => $link], 'ci4');
        try {
            $response = $server->client()->get('/');
            $this->assertSame(500, $response['status']);
            $this->assertSame(
                'The catalog cannot start because photo storage is inside the public web directory. Set originals_dir to a directory outside public/.',
                $response['body']
            );
            $this->assertStringNotContainsString($link, $response['body']);
            $this->assertStringNotContainsString($web, $response['body']);
            $this->assertTrue($server->logHas('ORIGINALS_DIR is inside the web root'));
            $this->assertTrue($server->logHas($link));
            $this->assertFileDoesNotExist($web . '/.htaccess');
        } finally {
            $server->stop();
            @unlink($link);
            @rmdir($web);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    public function testTrustProxyFollowsTheSchemaOnPostgres(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        if (test_driver() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL leg only');
        }
        $legacyDir = tmpdir('ci4-trust-leg');
        $ci4Dir = tmpdir('ci4-trust');
        $legacy = new Server($legacyDir, [], 'legacy');
        $ci4 = new Server($ci4Dir, [], 'ci4');
        try {
            $this->assertNotSame('', test_db_env($ci4Dir)['DB_SCHEMA']);
            $this->assertNotSame('public', test_db_env($ci4Dir)['DB_SCHEMA']);
            $legacy->client()->get('/');
            $this->seedTrust($legacyDir);
            $this->seedTrust($ci4Dir);
            $header = ['X-Forwarded-Proto: https'];
            $left = (new Client($legacy->base))->get('/', $header);
            $right = (new Client($ci4->base))->get('/', $header);
            $this->assertStringContainsString('secure', strtolower($this->cookieLine($left)));
            $this->assertStringContainsString('secure', strtolower($this->cookieLine($right)));
        } finally {
            $legacy->stop();
            $ci4->stop();
            testdb_drop($legacyDir);
            testdb_drop($ci4Dir);
            rrmdir($legacyDir);
            rrmdir($ci4Dir);
            @unlink($legacyDir . '.server.log');
            @unlink($ci4Dir . '.server.log');
        }
    }

    public function testHealthzAcceptsABackslashPasswordOnPostgres(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        if (test_driver() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL leg only');
        }
        $password = (string)getenv('TEST_PG_PASSWORD');
        if (getenv('CI') === 'true' || getenv('CI') === '1') {
            $this->assertStringContainsString('\\', $password, 'the CI PostgreSQL password must contain a backslash');
        }
        if (!str_contains($password, '\\')) {
            $this->markTestSkipped('PostgreSQL password has no backslash');
        }
        $dir = tmpdir('ci4-slash');
        $server = new Server($dir, [], 'ci4');
        try {
            $response = $server->client()->get('/healthz');
            $this->assertSame(200, $response['status'], (string)@file_get_contents($server->log));
            $this->assertSame('ok', $response['body']);
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    public function testBadDsnFailsAtBootAndRedactsThePassword(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $dir = tmpdir('ci4-dsn');
        $server = new Server($dir, [
            'DB_DRIVER' => 'pgsql',
            'DB_DSN' => 'mysql:host=localhost;password=s3cret;dbname=app',
        ], 'ci4');
        try {
            $response = $server->client()->get('/healthz');
            $this->assertSame(500, $response['status']);
            $this->assertStringContainsString('Unsupported database DSN', $response['body']);
            $this->assertStringContainsString('password=***', $response['body']);
            $this->assertStringNotContainsString('s3cret', $response['body']);
        } finally {
            $server->stop();
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    private function compareHealth(Server $legacy, Server $ci4): void
    {
        $left = $legacy->client()->get('/healthz');
        $right = $ci4->client()->get('/healthz');
        $this->assertSame(200, $right['status']);
        $this->assertSame($left['body'], $right['body']);
        $this->assertSame('ok', $right['body']);
        $this->assertSame($left['headers']['content-type'], $right['headers']['content-type']);
        $this->assertSame($left['headers']['x-apotheke-version'], $right['headers']['x-apotheke-version']);
        foreach (['x-content-type-options', 'x-frame-options', 'referrer-policy', 'content-security-policy'] as $name) {
            $this->assertSame($left['headers'][$name], $right['headers'][$name], $name);
        }
        $this->assertArrayNotHasKey('set-cookie', $right['headers']);
        $healthLeft = $legacy->client()->get('/health');
        $healthRight = $ci4->client()->get('/health');
        $this->assertSame($healthLeft['body'], $healthRight['body']);
        $this->assertSame($healthLeft['headers']['content-type'], $healthRight['headers']['content-type']);
        $this->assertSame('no-store', $healthRight['headers']['cache-control']);
        $this->assertSame($healthLeft['json'], $healthRight['json']);
    }

    private function originals(Client $legacy, Client $ci4): void
    {
        foreach (['/originals/guess.jpg', '/Originals/guess.jpg', '/ORIGINALS/guess.jpg'] as $path) {
            $left = $legacy->get($path);
            $right = $ci4->get($path);
            $this->assertSame(404, $right['status'], $path);
            $this->assertSame($left['body'], $right['body'], $path);
            $this->assertSame('Not found', $right['body']);
            $this->assertSame($left['headers']['content-type'], $right['headers']['content-type'], $path);
            $this->assertArrayNotHasKey('content-security-policy', $right['headers'], $path);
            $this->assertArrayNotHasKey('set-cookie', $right['headers'], $path);
        }
        $plain = $ci4->get('/no-such-page');
        $this->assertSame(404, $plain['status']);
        $this->assertSame('Not found', $plain['body']);
        $this->assertArrayHasKey('set-cookie', $plain['headers']);
    }

    private function seedTrust(string $dir): void
    {
        $pdo = testdb($dir);
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec("DELETE FROM settings WHERE name = 'trust_proxy'");
        $pdo->exec("INSERT INTO settings (name, value, updated_at) VALUES ('trust_proxy', 'true', '2026-01-01')");
    }

    private function probeRows(): int
    {
        $env = test_db_env(sys_get_temp_dir());
        $pdo = new PDO(
            "pgsql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']}",
            $env['DB_USER'],
            $env['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        return (int)$pdo->query('SELECT count(*) FROM public.apotheke_ci4_pgsql_probe')->fetchColumn();
    }

    private function cookies(Server $server, ?PDO $pdo): void
    {
        $plain = $server->client()->get('/');
        $this->assertSame(404, $plain['status']);
        $this->assertStringContainsString('apothekesid=', $this->cookieLine($plain));
        $this->assertStringNotContainsString('secure', strtolower($this->cookieLine($plain)));

        $forwarded = $server->client()->get('/', ['X-Forwarded-Proto: https']);
        $this->assertStringNotContainsString('secure', strtolower($this->cookieLine($forwarded)));
        if (!$pdo instanceof PDO) {
            return;
        }

        $pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO settings (name, value, updated_at) VALUES ('trust_proxy', 'true', '2026-01-01')");
        $trusted = new Client($server->base);
        $on = $trusted->get('/', ['X-Forwarded-Proto: HTTPS, http']);
        $this->assertStringContainsString('secure', strtolower($this->cookieLine($on)));
        $off = new Client($server->base);
        $http = $off->get('/', ['X-Forwarded-Proto: http']);
        $this->assertStringNotContainsString('secure', strtolower($this->cookieLine($http)));
    }

    /** @param array{raw:list<string>} $response */
    private function cookieLine(array $response): string
    {
        foreach ($response['raw'] as $line) {
            if (stripos($line, 'set-cookie:') === 0) return $line;
        }
        $this->fail('missing Set-Cookie');
    }

    private function router(Server $server, string $asset): void
    {
        file_put_contents($asset, 'pong');
        $file = $server->client()->get('/assets/ping.txt');
        $this->assertSame(200, $file['status']);
        $this->assertSame('pong', $file['body']);
        $php = $server->client()->get('/index.php');
        $this->assertSame(404, $php['status']);
        $this->assertStringNotContainsString('pong', $php['body']);
        $parts = parse_url($server->base);
        $socket = fsockopen((string)$parts['host'], (int)$parts['port']);
        $this->assertNotFalse($socket);
        fwrite($socket, "GET /../VERSION HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $raw = (string)stream_get_contents($socket);
        fclose($socket);
        $this->assertStringNotContainsString(' 200 ', substr($raw, 0, 20));
        $this->assertStringNotContainsString('pong', $raw);
        $version = trim((string)file_get_contents(dirname(__DIR__, 2) . '/VERSION'));
        $this->assertStringNotContainsString($version, $raw);
    }

    private function head(Server $server): void
    {
        $response = $server->client()->req('HEAD', '/healthz');
        $this->assertSame(200, $response['status']);
        $this->assertNotSame('', $response['headers']['x-apotheke-version'] ?? '');
    }
}
