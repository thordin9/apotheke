<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * A path no route serves is 404, and a route asked with a method it does not take is 405 with Allow, whether or
 * not the request carries consent or a session; under /api both are problem+json. Real routes still answer
 * as before: / sends a visitor to /login, a protected API read is 401, and a 405 never carries a page's data.
 */
final class Ci4RouteMethodTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('route-method');
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

    /** @return iterable<string,array{0:?string}> */
    public static function consent(): iterable
    {
        yield 'with consent' => ['v1'];
        yield 'without consent' => [null];
    }

    #[DataProvider('consent')]
    public function testAnUnknownPageIs404NotARedirect(?string $consent): void
    {
        $c = $this->client($consent);
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $r = $c->req($method, '/does-not-exist');
            $this->assertSame(404, $r['status'], $method);
            $this->assertArrayNotHasKey('location', $r['headers'], $method);
            $this->assertStringStartsWith('text/html', $r['headers']['content-type'], $method);
            $this->assertStringContainsString('Page not found.', $r['body'], $method);
        }
        $this->assertSame(404, $c->req('HEAD', '/does-not-exist')['status']);
    }

    #[DataProvider('consent')]
    public function testAWrongMethodIs405WithAllow(?string $consent): void
    {
        $c = $this->client($consent);
        foreach ([['PATCH', '/', 'GET, HEAD'], ['DELETE', '/healthz', 'GET, HEAD'], ['PUT', '/login', null], ['DELETE', '/logout', 'POST']] as [$method, $path, $allow]) {
            $r = $c->req($method, $path);
            if ($allow === null) {
                // /login takes every method (WebRoutes::every): its own answer, not 405.
                $this->assertNotSame(405, $r['status'], $method . ' ' . $path);
                continue;
            }
            $this->assertSame(405, $r['status'], $method . ' ' . $path . ': ' . substr($r['body'], 0, 200));
            $this->assertSame($allow, $r['headers']['allow'] ?? null, $method . ' ' . $path);
            $this->assertStringStartsWith('text/html', $r['headers']['content-type']);
            $this->assertArrayHasKey('content-security-policy', $r['headers']);
            $this->assertSame([], self::setCookies($r), 'no cookie on a 405');
        }
    }

    #[DataProvider('consent')]
    public function testUnderApiBothAreProblemJson(?string $consent): void
    {
        $c = $this->client($consent);
        $nf = $c->rest('GET', '/api/v1/no-such');
        $this->assertSame([404, 'not_found'], [$nf['status'], $nf['json']['code'] ?? null]);
        $this->assertSame('application/problem+json', $nf['headers']['content-type']);
        $put = $c->rest('PUT', '/api/v1/session', ['x' => 1]);
        $this->assertSame([405, 'method_not_allowed'], [$put['status'], $put['json']['code'] ?? null], $put['body']);
        $this->assertSame('application/problem+json', $put['headers']['content-type']);
        $this->assertSame('GET, HEAD, POST, DELETE', $put['headers']['allow'] ?? null);
        $this->assertSame(405, $put['json']['status']);
        $me = $c->rest('DELETE', '/api/v1/me');
        $this->assertSame([405, 'GET, HEAD'], [$me['status'], $me['headers']['allow'] ?? null]);
        $this->assertArrayHasKey('content-security-policy', $me['headers'], 'the API headers');
        $unknown = $c->rest('DELETE', '/api/v1/no-such');
        $this->assertSame([404, 'not_found'], [$unknown['status'], $unknown['json']['code'] ?? null]);
    }

    /** QA-1857: an unknown /api path, or one with a doubled slash, is the JSON 404 with the API headers. */
    #[DataProvider('consent')]
    public function testDoubledSlashApiPathsAreTheJson404(?string $consent): void
    {
        $c = $this->client($consent);
        foreach (['/api/v1/x', '/api/nope', '//api/v1/x', '/api//v1', '/api//v1/session', '//api//v1/me', '/api/v1//session'] as $path) {
            foreach (['GET', 'POST', 'DELETE'] as $method) {
                $r = $c->rest($method, $path, $method === 'POST' ? ['a' => 1] : null);
                $this->assertSame([404, 'not_found'], [$r['status'], $r['json']['code'] ?? null], $method . ' ' . $path . ': ' . substr($r['body'], 0, 200));
                $this->assertSame('application/problem+json', $r['headers']['content-type'], $method . ' ' . $path);
                $this->assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $r['headers']['content-security-policy'] ?? null, $method . ' ' . $path);
                $this->assertSame([], self::setCookies($r), $method . ' ' . $path);
            }
        }
    }

    /** Real routes still answer as before: / redirects a visitor, protected reads are 401 under /api. */
    public function testRealRoutesKeepTheirAnswers(): void
    {
        $c = $this->client('v1');
        $root = $c->get('/');
        $this->assertSame([303, '/login'], [$root['status'], $root['headers']['location'] ?? null]);
        $this->assertSame(401, $c->rest('GET', '/api/v1/me')['status']);
        $this->assertSame(401, $c->rest('GET', '/api/v1/users')['status']);
        $this->assertSame(200, $c->get('/login')['status']);
        $this->assertSame(200, $c->req('HEAD', '/login')['status']);
        $this->assertSame(303, $c->post('/logout')['status'], 'a real POST route still runs');
        $this->assertSame(403, $this->client(null)->post('/logout')['status'], 'and still needs consent');
    }

    /** A signed-in admin gets no page data on a 405: the page's own controller never runs. */
    public function testA405CarriesNoPageData(): void
    {
        $c = $this->client('v1');
        $this->assertSame(303, $c->post('/login', ['csrf' => $c->csrf(), 'username' => 'admin', 'password' => self::PASSWORD])['status']);
        $users = $c->rest('GET', '/api/v1/users');
        $this->assertSame(200, $users['status']);
        $r = $c->rest('PATCH', '/api/v1/users', ['x' => 1], ['X-CSRF-Token: ' . $c->csrf()]);
        $this->assertSame(405, $r['status'], $r['body']);
        $this->assertStringNotContainsString('admin', $r['body']);
        $page = $c->req('PATCH', '/');
        $this->assertSame(405, $page['status']);
        $this->assertStringNotContainsString('admin', strtolower(strip_tags(preg_replace('/<head>.*<\/head>/s', '', $page['body']) ?? '')));
    }

    private function client(?string $consent): Client
    {
        $this->assertNotNull(self::$server);
        $c = self::$server->client();
        $c->consent = $consent;

        return $c;
    }

    /** @param array{raw:list<string>} $r @return list<string> */
    private static function setCookies(array $r): array
    {
        return array_values(array_filter($r['raw'], static fn (string $l): bool => stripos($l, 'set-cookie:') === 0));
    }
}
