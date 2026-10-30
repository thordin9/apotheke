<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * API P1 (PM decision a): on HTTPS the session cookie is `__Host-apothekesid`
 * (Secure, Path=/, no Domain); on plain HTTP it stays `apothekesid`. For one
 * release an HTTPS request still resumes a live session from the plain name.
 */
final class Ci4ApiCookieTest extends TestCase
{
    public function testPlainHttpKeepsTheCurrentName(): void
    {
        $this->withServer([], function (Client $client): void {
            $line = $this->cookieLine($client->get('/login'));
            $this->assertMatchesRegularExpression('/^Set-Cookie: apothekesid=[A-Za-z0-9,-]+;/', $line);
            $this->assertStringNotContainsStringIgnoringCase('secure', $line);
        });
    }

    public function testHttpsUsesTheHostPrefixedCookie(): void
    {
        $this->withServer(['PUBLIC_URL' => 'https://127.0.0.1'], function (Client $client): void {
            $first = $client->get('/login');
            $line = $this->cookieLine($first);
            $this->assertMatchesRegularExpression('/^Set-Cookie: __Host-apothekesid=[A-Za-z0-9,-]+;/', $line);
            $this->assertStringContainsStringIgnoringCase('; secure', $line);
            $this->assertStringContainsStringIgnoringCase('; path=/;', $line);
            $this->assertStringContainsStringIgnoringCase('; httponly', $line);
            $this->assertStringNotContainsStringIgnoringCase('domain=', $line);
            $this->assertArrayNotHasKey('apothekesid', $client->cookies);
            $token = $this->token($first['body']);
            $again = $client->get('/login');
            $this->assertNull($this->cookieLineOrNull($again), 'the live session is resumed, no new cookie');
            $this->assertSame($token, $this->token($again['body']));
        });
    }

    /**
     * QA N-1144, PM decision: the session cookie stays SameSite=Lax while the HTML views exist (a link from
     * another site must still open the app signed in); Strict is reconsidered for 4.0. Both names, set by a
     * page and by the API.
     */
    public function testTheSessionCookieIsSameSiteLaxUnderBothNames(): void
    {
        foreach ([[[], 'apothekesid'], [['PUBLIC_URL' => 'https://127.0.0.1'], '__Host-apothekesid']] as [$env, $name]) {
            $this->withServer($env, function (Client $client) use ($name): void {
                foreach (['/login', '/api/v1/session'] as $path) {
                    $client->cookies = [];
                    $line = $this->cookieLine($client->get($path));
                    $this->assertStringStartsWith('Set-Cookie: ' . $name . '=', $line, $path);
                    $this->assertMatchesRegularExpression('/; samesite=Lax(;|$)/i', $line, $path);
                    $this->assertSame(1, preg_match_all('/samesite=/i', $line), $path);
                }
            });
        }
    }

    public function testHttpsResumesASessionFromThePlainNameOnce(): void
    {
        $this->withServer(['PUBLIC_URL' => 'https://127.0.0.1'], function (Client $client, string $base): void {
            $first = $client->get('/login');
            $id = (string)$client->cookies['__Host-apothekesid'];
            $old = new Client($base);
            $old->cookies['apothekesid'] = $id;
            $moved = $old->get('/login');
            $this->assertSame($this->token($first['body']), $this->token($moved['body']), 'same session');
            $this->assertSame($id, $old->cookies['__Host-apothekesid'] ?? null, 'moved to the new name, same id');
        });
    }

    /** @param array<string,string> $env @param callable(Client,string):void $test */
    private function withServer(array $env, callable $test): void
    {
        $dir = tmpdir('api-cookie');
        $server = new Server($dir, $env + ['APP_PASSWORD' => 'long-enough'], 'ci4');   // sign-in on: /login opens a session
        try {
            $test($server->client(), $server->base);
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    private function token(string $html): string
    {
        $this->assertSame(1, preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m), 'a CSRF field');

        return $m[1];
    }

    /** @param array{raw:list<string>} $response */
    private function cookieLine(array $response): string
    {
        $line = $this->cookieLineOrNull($response);
        $this->assertNotNull($line, 'missing Set-Cookie');

        return $line;
    }

    /** @param array{raw:list<string>} $response */
    private function cookieLineOrNull(array $response): ?string
    {
        foreach ($response['raw'] as $line) {
            if (stripos($line, 'set-cookie: ') === 0 && preg_match('/^set-cookie: (__Host-)?apothekesid=/i', $line)) {
                return 'Set-Cookie: ' . substr($line, 12);
            }
        }

        return null;
    }
}
