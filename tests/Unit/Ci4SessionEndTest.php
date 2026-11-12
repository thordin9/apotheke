<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * How a session ends: sign-out (DELETE /api/v1/session, POST /logout), the
 * notice's Decline (POST /consent) and a request that no longer carries
 * consent. The session is removed from the store, the answer expires its
 * cookie with the attributes it was set with, and no new session id is issued.
 */
final class Ci4SessionEndTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    /** The expiry line SessionGate::expireCookies() sends for the plain-HTTP name. */
    private const EXPIRY = '/^apothekesid=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=\/; HttpOnly; SameSite=Lax$/D';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('session-end');
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

    public function testApiSignOutRemovesTheSessionAndExpiresTheCookie(): void
    {
        $c = $this->signedInOverApi();
        $old = $c->cookies['apothekesid'];
        $this->assertFileExists($this->file($old));
        $out = $c->rest('DELETE', '/api/v1/session', null, ['X-CSRF-Token: ' . $c->rest('GET', '/api/v1/session')['json']['csrf_token']]);
        $this->assertSame(204, $out['status'], $out['body']);
        ApiContract::assertMatches('DELETE', '/api/v1/session', $out);
        $this->assertOnlyExpiry($out);
        $this->assertFileDoesNotExist($this->file($old));
        $this->assertOldCookieRejected($old);
    }

    public function testWebSignOutRemovesTheSessionAndExpiresTheCookie(): void
    {
        $c = $this->client();
        $in = $c->post('/login', ['csrf' => $c->csrf(), 'username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertSame(303, $in['status'], $in['body']);
        $old = $c->cookies['apothekesid'];
        $this->assertSame(200, $c->get('/')['status']);
        $out = $c->post('/logout', ['csrf' => $c->csrf()]);
        $this->assertSame(303, $out['status'], $out['body']);
        $this->assertSame('/login', parse_url($out['headers']['location'], PHP_URL_PATH));
        $this->assertOnlyExpiry($out);
        $this->assertFileDoesNotExist($this->file($old));
        $this->assertOldCookieRejected($old);
        $again = $this->client();
        $again->cookies['apothekesid'] = $old;
        $this->assertSame(303, $again->get('/')['status'], 'the old cookie no longer signs in');
    }

    public function testARequestWithoutConsentEndsTheSession(): void
    {
        $c = $this->signedInOverApi();
        $old = $c->cookies['apothekesid'];
        $c->consent = null;
        $r = $c->rest('GET', '/api/v1/me');
        $this->assertSame(401, $r['status']);
        $this->assertOnlyExpiry($r);
        $this->assertFileDoesNotExist($this->file($old));
        $this->assertOldCookieRejected($old);
    }

    public function testDeclineEndsTheSessionAndExpiresBothCookies(): void
    {
        $c = $this->signedInOverApi();
        $old = $c->cookies['apothekesid'];
        $r = $c->post('/consent', ['consent' => 'decline', 'return' => '/login'], [], ['Origin: ' . $c->base, 'Sec-Fetch-Site: same-origin']);
        $this->assertSame(303, $r['status'], $r['body']);
        $this->assertSame('/login', $r['headers']['location']);
        $cookies = self::setCookies($r);
        $this->assertCount(2, $cookies, implode("\n", $cookies));
        $this->assertContains('apotheke_consent=; Path=/; Max-Age=0; SameSite=Lax', $cookies);
        $this->assertSame(1, count(preg_grep(self::EXPIRY, $cookies) ?: []));
        $this->assertFileDoesNotExist($this->file($old));
        $this->assertOldCookieRejected($old);
    }

    public function testDeclineWithoutASessionOnlyExpiresTheConsentCookie(): void
    {
        $c = $this->client(null);
        $r = $c->post('/consent', ['consent' => 'decline'], [], ['Origin: ' . $c->base, 'Sec-Fetch-Site: same-origin']);
        $this->assertSame(303, $r['status'], $r['body']);
        $this->assertSame(['apotheke_consent=; Path=/; Max-Age=0; SameSite=Lax'], self::setCookies($r));
        $cross = $c->post('/consent', ['consent' => 'decline'], [], ['Origin: https://elsewhere.example', 'Sec-Fetch-Site: cross-site']);
        $this->assertSame(403, $cross['status'], 'Decline is strictly same-origin, as Accept');
        $this->assertSame([], self::setCookies($cross));
    }

    private function signedInOverApi(): Client
    {
        $c = $this->client();
        $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $in = $c->rest('POST', '/api/v1/session', ['username' => 'admin', 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token]);
        $this->assertSame(200, $in['status'], $in['body']);
        $this->assertSame(200, $c->rest('GET', '/api/v1/me')['status']);

        return $c;
    }

    /** The old cookie, sent again with consent, signs nobody in and is not taken over as the next session. */
    private function assertOldCookieRejected(string $old): void
    {
        $c = $this->client();
        $c->cookies['apothekesid'] = $old;
        $this->assertSame(401, $c->rest('GET', '/api/v1/me')['status']);
        $s = $c->rest('GET', '/api/v1/session');
        $this->assertFalse($s['json']['signed_in']);
        $this->assertNotSame($old, $c->cookies['apothekesid'], 'a token needs a new session, never the old id');
        $this->assertFileDoesNotExist($this->file($old));
    }

    /** @param array{raw:list<string>} $r */
    private function assertOnlyExpiry(array $r): void
    {
        $cookies = self::setCookies($r);
        $this->assertCount(1, $cookies, 'one Set-Cookie, the expiry; no new session id: ' . implode(' | ', array_map(static fn (string $l): string => (string)preg_replace('/=[^;]*/', '=…', $l, 1), $cookies)));
        $this->assertMatchesRegularExpression(self::EXPIRY, $cookies[0]);
    }

    private function file(string $id): string
    {
        return self::$dir . '/sessions/sess_' . $id;
    }

    private function client(?string $consent = 'v1'): Client
    {
        $this->assertNotNull(self::$server);
        $c = self::$server->client();
        $c->consent = $consent;

        return $c;
    }

    /** @param array{raw:list<string>} $r @return list<string> */
    private static function setCookies(array $r): array
    {
        $out = [];
        foreach ($r['raw'] as $line) {
            if (stripos($line, 'set-cookie:') === 0) {
                $out[] = trim(substr($line, 11));
            }
        }

        return $out;
    }
}
