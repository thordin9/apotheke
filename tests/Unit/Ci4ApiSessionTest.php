<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * API P1: GET/POST/DELETE /api/v1/session and GET /api/v1/me (API plan §2.1,
 * §3) with sign-in on (APP_PASSWORD creates the account admin). Sign-in uses
 * the same limiter, pacing and session code as /login.
 */
final class Ci4ApiSessionTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('api-session');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4');
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

    public function testSignedOutSessionGivesATokenAndMeIs401(): void
    {
        $c = $this->client();
        $s = $c->rest('GET', '/api/v1/session');
        $this->assertSame(200, $s['status']);
        $this->assertSame('application/json; charset=utf-8', $s['headers']['content-type']);
        $this->assertFalse($s['json']['signed_in']);
        $this->assertTrue($s['json']['sign_in_enabled']);
        $this->assertNull($s['json']['user']);
        $this->assertSame([], $s['json']['permissions'], 'guest browsing is off by default');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $s['json']['csrf_token']);
        $this->assertArrayHasKey('apothekesid', $c->cookies, 'the token lives in a session');
        $me = $c->rest('GET', '/api/v1/me');
        $this->assertSame(401, $me['status']);
        $this->assertSame('application/problem+json', $me['headers']['content-type']);
        $this->assertSame('auth.required', $me['json']['code']);
        $this->assertArrayNotHasKey('location', $me['headers'], 'no redirect to /login');
    }

    public function testSignInMeAndSignOut(): void
    {
        $c = $this->client();
        $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $before = $c->cookies['apothekesid'];
        $in = $c->rest('POST', '/api/v1/session', ['username' => 'admin', 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token]);
        $this->assertSame(200, $in['status'], $in['body']);
        $this->assertTrue($in['json']['signed_in']);
        $this->assertSame('admin', $in['json']['user']['username']);
        $this->assertSame('admin', $in['json']['user']['role']);
        $this->assertContains('users', $in['json']['permissions']);
        $this->assertNotSame($before, $c->cookies['apothekesid'], 'a new session id on sign-in');
        $this->assertNotSame($token, $in['json']['csrf_token'], 'and a new token');
        $me = $c->rest('GET', '/api/v1/me');
        $this->assertSame(200, $me['status']);
        $this->assertSame('admin', $me['json']['username']);
        $this->assertIsInt($me['json']['id']);
        $this->assertContains('view', $me['json']['permissions']);
        $stale = $c->rest('DELETE', '/api/v1/session', null, ['X-CSRF-Token: ' . $token]);
        $this->assertSame([403, 'csrf.invalid'], [$stale['status'], $stale['json']['code']], 'the old token is gone');
        $out = $c->rest('DELETE', '/api/v1/session', null, ['X-CSRF-Token: ' . $in['json']['csrf_token']]);
        $this->assertSame(204, $out['status']);
        $this->assertSame('', $out['body']);
        $this->assertSame(401, $c->rest('GET', '/api/v1/me')['status']);
    }

    public function testWrongPasswordAndBadBodies(): void
    {
        $c = $this->client();
        $token = ['X-CSRF-Token: ' . $c->rest('GET', '/api/v1/session')['json']['csrf_token']];
        $wrong = $c->rest('POST', '/api/v1/session', ['username' => 'admin', 'password' => 'nope'], $token);
        $this->assertSame([401, 'auth.invalid_credentials'], [$wrong['status'], $wrong['json']['code']]);
        $unknown = $c->rest('POST', '/api/v1/session', ['username' => 'nobody', 'password' => 'nope'], $token);
        $this->assertSame($wrong['json'], $unknown['json'], 'the same answer for an unknown account');
        $missing = $c->rest('POST', '/api/v1/session', ['username' => 'admin'], $token);
        $this->assertSame([422, 'validation.failed'], [$missing['status'], $missing['json']['code']]);
        $this->assertArrayHasKey('password', $missing['json']['errors']);
        $broken = $c->req('POST', '/api/v1/session', [], [], array_merge($token, ['Origin: ' . $c->base, 'Content-Type: application/json']), '{"username":');
        $this->assertSame([400, 'bad_request'], [$broken['status'], $broken['json']['code'] ?? null]);
        $noToken = $c->rest('POST', '/api/v1/session', ['username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertSame([403, 'csrf.invalid'], [$noToken['status'], $noToken['json']['code']], 'sign-in needs the token too');
    }

    public function testTheSignInLimitIs429WithRetryAfter(): void
    {
        $c = $this->client();
        $token = ['X-CSRF-Token: ' . $c->rest('GET', '/api/v1/session')['json']['csrf_token']];
        $last = [];
        for ($i = 0; $i < 11; $i++) {
            $last = $c->rest('POST', '/api/v1/session', ['username' => 'limited', 'password' => 'nope'], $token);
        }
        $this->assertSame([429, 'rate_limited'], [$last['status'], $last['json']['code']]);
        $this->assertSame('900', $last['headers']['retry-after'] ?? null);
    }

    public function testTheLoginPageStillWorks(): void
    {
        $c = $this->client();
        $token = $c->csrf();
        $r = $c->req('POST', '/login', [], [], [], http_build_query(['csrf' => $token, 'username' => 'admin', 'password' => self::PASSWORD]));
        $this->assertSame(303, $r['status']);
        $this->assertSame(200, $c->rest('GET', '/api/v1/me')['status'], 'a page sign-in is an API session too');
    }
}
