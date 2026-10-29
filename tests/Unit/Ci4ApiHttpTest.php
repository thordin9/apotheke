<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

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

    public function testNonSafeRequestsNeedOriginTypeAndToken(): void
    {
        $c = $this->client();
        $own = $c->rest('POST', '/api/v1/nothing-here', ['a' => 1]);
        $this->assertSame([403, 'csrf.invalid'], [$own['status'], $own['json']['code'] ?? null], 'own origin, but no token');
        $this->assertSame('application/problem+json', $own['headers']['content-type']);
        $this->assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $own['headers']['content-security-policy']);
        foreach ([
            'no Origin' => [[], 'csrf.invalid', 403],
            'another site' => [['Origin: https://evil.example.test', 'Sec-Fetch-Site: cross-site'], 'csrf.invalid', 403],
        ] as $case => [$headers, $code, $status]) {
            $r = $c->req('POST', '/api/v1/nothing-here', [], [], array_merge($headers, ['Content-Type: application/json']), '{}');
            $this->assertSame([$status, $code], [$r['status'], $r['json']['code'] ?? null], $case);
        }
        $form = $c->req('POST', '/api/v1/nothing-here', [], [], ['Origin: ' . $c->base, 'Sec-Fetch-Site: same-origin'], 'a=1');
        $this->assertSame([415, 'unsupported_media_type'], [$form['status'], $form['json']['code'] ?? null], 'a form body');
        $delete = $c->rest('DELETE', '/api/v1/nothing-here');
        $this->assertSame([403, 'csrf.invalid'], [$delete['status'], $delete['json']['code'] ?? null], 'DELETE too');
        $listed = $c->rest('POST', '/api/v1/nothing-here', [], ['Origin: ' . self::APP, 'Sec-Fetch-Site: same-site']);
        $this->assertSame([403, 'csrf.invalid'], [$listed['status'], $listed['json']['code'] ?? null], 'a listed origin still needs the token');
        $this->assertSame(self::APP, $listed['headers']['access-control-allow-origin'] ?? null, 'so the app can read the refusal');
        $big = $c->req('POST', '/api/v1/nothing-here', [], [], ['Origin: ' . $c->base, 'Content-Type: application/json'], str_repeat(' ', 23 * 1024 * 1024));
        $this->assertSame([413, 'upload.too_large'], [$big['status'], $big['json']['code'] ?? null], 'over post_max_size');
    }

    /** Sign-in off: the visitor is the anonymous admin, not an account, so `self` refuses (PM decision c). */
    public function testWithSignInOffMeIsForbiddenAndSignInIsOff(): void
    {
        $c = $this->client();
        $s = $c->rest('GET', '/api/v1/session');
        $this->assertFalse($s['json']['signed_in']);
        $this->assertFalse($s['json']['sign_in_enabled']);
        $this->assertContains('users', $s['json']['permissions']);
        $me = $c->rest('GET', '/api/v1/me');
        $this->assertSame([403, 'permission.denied'], [$me['status'], $me['json']['code']]);
        $this->assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $me['headers']['content-security-policy']);
        $in = $c->rest('POST', '/api/v1/session', ['username' => 'admin', 'password' => 'x'], ['X-CSRF-Token: ' . $s['json']['csrf_token']]);
        $this->assertSame([409, 'auth.sign_in_off'], [$in['status'], $in['json']['code']]);
        ApiContract::assertMatches('GET', '/api/v1/session', $s);
        ApiContract::assertMatches('GET', '/api/v1/me', $me);
        ApiContract::assertMatches('POST', '/api/v1/session', $in);
        $ok = $c->rest('POST', '/api/v1/nothing-here', [], ['X-CSRF-Token: ' . $s['json']['csrf_token']]);
        $this->assertSame([404, 'not_found'], [$ok['status'], $ok['json']['code']], 'with the token the request gets through');
    }

    public function testTheOpenApiDocumentIsServed(): void
    {
        $r = $this->client()->get('/api/v1/openapi.json');
        $this->assertSame(200, $r['status']);
        ApiContract::assertMatches('GET', '/api/v1/openapi.json', $r);
        $this->assertSame(json_decode((string)file_get_contents(ApiContract::SPEC), true), $r['json']);
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
