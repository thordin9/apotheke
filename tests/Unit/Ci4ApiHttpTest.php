<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * API P1 over HTTP: /api/v1 routing, problem+json errors, the API security
 * headers and CORS (API plan §2.2, §2.5, §3). Sign-in is off on the shared
 * server, so the user is the guest admin.
 */
final class Ci4ApiHttpTest extends TestCase
{
    private const APP = 'https://app.example.test';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('api-http');
        self::$server = new Server(self::$dir, ['CORS_ALLOWED_ORIGINS' => self::APP . ', *'], 'ci4');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    private function client(): Client
    {
        $this->assertNotNull(self::$server);

        return self::$server->client();
    }

    public function testAnUnknownApiPathIsAProblem404WithTheApiHeaders(): void
    {
        $r = $this->client()->get('/api/v1/nothing-here');
        $this->assertSame(404, $r['status']);
        $this->assertSame('application/problem+json', $r['headers']['content-type']);
        $this->assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'No such API endpoint.', 'code' => 'not_found'], $r['json']);
        $this->assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $r['headers']['content-security-policy']);
        $this->assertSame('nosniff', $r['headers']['x-content-type-options']);
        $this->assertSame('no-referrer', $r['headers']['referrer-policy']);
        $this->assertSame('same-site', $r['headers']['cross-origin-resource-policy']);
        $this->assertSame('same-origin', $r['headers']['cross-origin-opener-policy']);
        $this->assertSame('no-store', $r['headers']['cache-control']);
        $this->assertArrayNotHasKey('strict-transport-security', $r['headers'], 'plain http: no HSTS');
        $this->assertArrayNotHasKey('access-control-allow-origin', $r['headers'], 'no Origin sent');
        $this->assertArrayNotHasKey('set-cookie', $r['headers'], 'no session for an API 404');
    }

    public function testAPreflightFromAListedOriginGetsTheCorsHeaders(): void
    {
        $r = $this->client()->req('OPTIONS', '/api/v1/session', [], [], ['Origin: ' . self::APP, 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: content-type, x-csrf-token']);
        $this->assertSame(204, $r['status']);
        $this->assertSame(self::APP, $r['headers']['access-control-allow-origin']);
        $this->assertSame('true', $r['headers']['access-control-allow-credentials']);
        $this->assertSame('GET, POST, PATCH, PUT, DELETE', $r['headers']['access-control-allow-methods']);
        $this->assertSame('Content-Type, X-CSRF-Token, If-Match, Idempotency-Key', $r['headers']['access-control-allow-headers']);
        $this->assertSame('600', $r['headers']['access-control-max-age']);
        $this->assertStringContainsString('Origin', $r['headers']['vary']);
        $this->assertSame('', $r['body']);
        $this->assertArrayNotHasKey('set-cookie', $r['headers']);
    }

    public function testOtherOriginsGetNoCorsHeaders(): void
    {
        foreach (['https://evil.example.test', 'null', 'http://app.example.test'] as $origin) {
            $r = $this->client()->req('OPTIONS', '/api/v1/session', [], [], ['Origin: ' . $origin, 'Access-Control-Request-Method: POST']);
            $this->assertSame(204, $r['status'], $origin);
            $this->assertArrayNotHasKey('access-control-allow-origin', $r['headers'], $origin);
            $this->assertArrayNotHasKey('access-control-allow-methods', $r['headers'], $origin);
        }
    }

    public function testAnAllowedOriginOnAnOrdinaryRequestAndNotOutsideTheApi(): void
    {
        $r = $this->client()->get('/api/v1/nothing-here', ['Origin: ' . self::APP]);
        $this->assertSame(self::APP, $r['headers']['access-control-allow-origin']);
        $this->assertSame('ETag, Location, Retry-After, Link', $r['headers']['access-control-expose-headers']);
        $this->assertArrayNotHasKey('access-control-allow-methods', $r['headers'], 'only on a preflight');
        $page = $this->client()->get('/healthz', ['Origin: ' . self::APP]);
        $this->assertSame(200, $page['status']);
        $this->assertArrayNotHasKey('access-control-allow-origin', $page['headers'], 'CORS is for /api only');
        $this->assertNotSame('no-referrer', $page['headers']['referrer-policy'] ?? '', 'the HTML headers stay');
    }

    public function testHstsWhenPublicUrlIsHttps(): void
    {
        $dir = tmpdir('api-hsts');
        $server = new Server($dir, ['PUBLIC_URL' => 'https://127.0.0.1'], 'ci4');
        try {
            $r = $server->client()->get('/api/v1/nothing-here');
            $this->assertSame('max-age=31536000', $r['headers']['strict-transport-security'] ?? null);
            $this->assertArrayNotHasKey('access-control-allow-origin', $server->client()->get('/api/v1/x', ['Origin: ' . self::APP])['headers'], 'CORS off by default');
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }
}
