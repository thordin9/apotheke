<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * Cookie consent (Config\Consent, docs/deployment-and-operations.md): no Set-Cookie on any answer until the request carries
 * apotheke_consent=v1, and every write refused with 403 consent_required; with it, sessions and CSRF as before.
 */
final class Ci4CookieConsentTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('consent');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4');
        self::$server->client()->rest('GET', '/api/v1/session');   // migrates and creates admin
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
    }

    /** @return iterable<string,array{0:string,1:string,2:int,3?:array<string,string>}> method, path, status, fields */
    public static function routes(): iterable
    {
        yield 'list (redirect to sign-in)' => ['GET', '/', 303];
        yield 'unknown page (redirect to sign-in)' => ['GET', '/no-such-page', 303];
        yield 'sign-in page' => ['GET', '/login', 200];
        yield 'sign-in page (HEAD)' => ['HEAD', '/login', 200];
        yield 'branding: theme.css (HEAD)' => ['HEAD', '/theme.css', 200];
        yield 'branding: manifest' => ['GET', '/manifest.webmanifest', 200];
        yield 'branding: theme.css' => ['GET', '/theme.css', 200];
        yield 'api session' => ['GET', '/api/v1/session', 200];
        yield 'api me' => ['GET', '/api/v1/me', 401];
        yield 'api settings' => ['GET', '/api/v1/settings', 401];
        yield 'api branding (public)' => ['GET', '/api/v1/branding', 200];
        yield 'api users' => ['GET', '/api/v1/users', 401];
        yield 'api roles' => ['GET', '/api/v1/roles', 401];
        yield 'api security' => ['GET', '/api/v1/security', 401];
        yield 'openapi.json' => ['GET', '/api/v1/openapi.json', 200];
        yield '404' => ['GET', '/brand/no-such.png', 404];
        yield 'api 404' => ['GET', '/api/v1/no-such', 404];
        yield 'sign-in POST' => ['POST', '/login', 403, ['username' => 'admin', 'password' => self::PASSWORD]];
        yield 'CSRF failure (bad token)' => ['POST', '/theme', 403, ['csrf' => 'bad', 'theme' => 'dark']];
        yield 'register POST' => ['POST', '/register', 403, ['username' => 'x', 'password' => 'y']];
        yield 'logout POST' => ['POST', '/logout', 403];
    }

    /** @param array<string,string> $fields */
    #[DataProvider('routes')]
    public function testNoCookieWithoutConsent(string $method, string $path, int $status, array $fields = []): void
    {
        $c = $this->client(null);
        $r = $method === 'POST' ? $c->post($path, $fields) : $c->req($method, $path);
        $this->assertSame($status, $r['status'], $path . ': ' . substr($r['body'], 0, 300));
        $this->assertSame([], self::setCookies($r), $path);
        $this->assertSame([], $c->cookies, $path);
    }

    /** L-1342: without a session nothing sends no-cache, so a page without its own Cache-Control gets no-store. */
    public function testCookielessPagesAreNotStored(): void
    {
        foreach (['/login', '/brand/no-such.png'] as $path) {
            $this->assertSame('no-store', $this->client(null)->get($path)['headers']['cache-control'] ?? null, $path);
        }
        $this->assertSame('no-cache', $this->client(null)->get('/theme.css')['headers']['cache-control'] ?? null, 'its own caching stays');
    }

    /** POST /consent (the notice's form without JavaScript): only the consent cookie, then back to the page. */
    public function testTheFormAcceptSetsOnlyTheConsentCookie(): void
    {
        $page = $this->client(null)->get('/login?next=1');
        $this->assertStringContainsString('<form method="post" action="/consent"><input type="hidden" name="return" value="/login?next=1">'
            . '<button type="submit" class="btn btn-primary" id="consent-accept" data-consent="v1" data-domain="" name="consent" value="accept">', $page['body']);
        foreach (['Origin: ' . self::$server->base, 'Sec-Fetch-Site: same-origin'] as $header) {
            $c = $this->client(null);
            $r = $c->post('/consent', ['consent' => 'accept', 'return' => '/login?next=1'], [], [$header]);
            $this->assertSame(303, $r['status'], $header . ': ' . substr($r['body'], 0, 200));
            $this->assertSame('/login?next=1', $r['headers']['location'], $header);
            $this->assertSame(['apotheke_consent=v1; Path=/; Max-Age=15552000; SameSite=Lax'], self::setCookies($r), $header);
            $this->assertSame('no-store', $r['headers']['cache-control'], $header);
            $after = $c->get('/login');
            $this->assertStringNotContainsString('id="consent"', $after['body'], 'the cookie works');
            $this->assertMatchesRegularExpression('/^apothekesid=/', self::setCookies($after)[0] ?? '');
        }
    }

    /** Strictly same-origin: same-site, cross-site, none, another or a null Origin, or neither header: 403, no cookie. */
    public function testTheFormAcceptIsStrictlySameOrigin(): void
    {
        foreach ([[], ['Sec-Fetch-Site: same-site'], ['Sec-Fetch-Site: cross-site'], ['Sec-Fetch-Site: none'], ['Origin: http://evil.test'],
            ['Origin: null'], ['Sec-Fetch-Site: same-site', 'Origin: ' . self::$server->base], ['Sec-Fetch-Site: none', 'Origin: ' . self::$server->base]] as $headers) {
            $r = $this->client(null)->post('/consent', ['consent' => 'accept', 'return' => '/'], [], $headers);
            $this->assertSame(403, $r['status'], implode(', ', $headers));
            $this->assertSame([], self::setCookies($r), implode(', ', $headers));
        }
    }

    public function testTheFormAcceptNeedsConsentAccept(): void
    {
        foreach ([['return' => '/'], ['consent' => 'yes'], ['consent' => 'Accept'], ['consent' => '']] as $fields) {
            $r = $this->client(null)->post('/consent', $fields, [], ['Origin: ' . self::$server->base]);
            $this->assertSame(400, $r['status'], json_encode($fields));
            $this->assertSame([], self::setCookies($r), json_encode($fields));
        }
    }

    /** Only a path on this site comes back as Location (all variants: ConsentFilterTest). */
    public function testTheFormAcceptReturnsOnlyToThisSite(): void
    {
        foreach (['//evil.test/', '/\\evil.test', '\\\\evil.test', 'https://evil.test/', '/%2F%2Fevil.test', '/%5Cevil.test', "/x\r\nX-Evil: 1", 'login'] as $back) {
            $r = $this->client(null)->post('/consent', ['consent' => 'accept', 'return' => $back], [], ['Origin: ' . self::$server->base]);
            $this->assertSame([303, '/'], [$r['status'], $r['headers']['location'] ?? null], var_export($back, true));
            $this->assertArrayNotHasKey('x-evil', $r['headers']);
        }
        $r = $this->client(null)->post('/consent', ['consent' => 'accept', 'return' => "/search?q=caf\u{e9}"], [], ['Origin: ' . self::$server->base]);
        $this->assertSame('/search?q=caf%C3%A9', $r['headers']['location'] ?? null, 'N-1424: non-ASCII percent-encoded');
        $r = $this->client(null)->post('/consent', ['consent' => 'accept', 'return' => '/' . str_repeat("\u{e9}", 400)], [], ['Origin: ' . self::$server->base]);
        $this->assertSame('/', $r['headers']['location'] ?? null, 'N-1460: too long once percent-encoded');
    }

    /** COOKIE_CONSENT=off: as before 3.4 (cookies, sign-in, no notice); any value but off is required. */
    public function testTheSwitch(): void
    {
        foreach (['off' => true, 'required' => false, 'nope' => false] as $value => $off) {
            $dir = tmpdir('consent-switch');
            $server = new Server($dir, ['APP_PASSWORD' => self::PASSWORD, 'COOKIE_CONSENT' => $value], 'ci4');
            try {
                $c = $server->client();
                $c->consent = null;
                $page = $c->get('/login');
                $this->assertSame($off, self::setCookies($page) !== [], $value);
                $this->assertSame(!$off, str_contains($page['body'], 'id="consent"'), $value);
                $this->assertSame(!$off, str_contains($page['body'], '<html lang="en" data-theme="auto" data-no-cookies>'), $value);
                $in = $c->post('/login', ['csrf' => $c->csrf(), 'username' => 'admin', 'password' => self::PASSWORD]);
                $this->assertSame($off ? 303 : 403, $in['status'], $value);
            } finally {
                $server->stop();
                testdb_drop($dir);
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
    }

    /**
     * COOKIE_DOMAIN: the consent cookie gets Domain on a host under it (form and the notice's data-domain); the
     * __Host- session cookie never does.
     */
    public function testTheCookieDomainIsForTheConsentCookieOnly(): void
    {
        $dir = tmpdir('consent-domain');
        $server = new Server($dir, ['APP_PASSWORD' => self::PASSWORD, 'COOKIE_DOMAIN' => '.Example.test', 'PUBLIC_URL' => 'https://app.example.test'], 'ci4');
        try {
            $host = ['Host: app.example.test'];
            $none = $server->client();
            $none->consent = null;
            $this->assertStringContainsString('data-domain="example.test"', $none->get('/login', $host)['body']);
            $r = $none->post('/consent', ['consent' => 'accept'], [], [...$host, 'Sec-Fetch-Site: same-origin']);
            $this->assertSame(['apotheke_consent=v1; Path=/; Max-Age=15552000; Domain=example.test; Secure; SameSite=Lax'], self::setCookies($r));
            $other = $none->post('/consent', ['consent' => 'accept'], [], ['Sec-Fetch-Site: same-origin']);
            $this->assertSame(['apotheke_consent=v1; Path=/; Max-Age=15552000; SameSite=Lax'], self::setCookies($other), 'another host: host-only');
            $session = self::setCookies($server->client()->get('/login', $host));
            $this->assertCount(1, $session);
            $this->assertMatchesRegularExpression('/^__Host-apothekesid=[^;]+; .*secure/i', $session[0]);
            $this->assertStringNotContainsStringIgnoringCase('domain=', $session[0], 'the __Host- cookie stays host-only');
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /**
     * L-1341: an error answer skips the after filters, so only ConsentFilter's header callback keeps a cookie PHP put
     * in its header list out of it. session.auto_start puts one there on every request.
     */
    public function testTheHeaderCallbackStripsCookiesFromErrorAnswers(): void
    {
        $dir = tmpdir('consent-backstop');
        mkdir($dir . '/php-sessions', 0700, true);
        $server = new Server($dir, ['APP_PASSWORD' => null], 'ci4', null, ['session.auto_start' => '1', 'session.save_path' => $dir . '/php-sessions']);
        try {
            $server->client()->get('/');   // migrates
            testdb($dir)->exec('ALTER TABLE items RENAME TO items_gone');
            $c = $server->client();
            $c->consent = null;
            $r = $c->get('/');
            $this->assertSame(500, $r['status'], substr($r['body'], 0, 300));
            $this->assertSame([], self::setCookies($r));
            $this->assertStringStartsWith('no-store', $r['headers']['cache-control'] ?? '');
            $with = $server->client()->get('/');
            $this->assertSame(500, $with['status']);
            $this->assertNotSame([], self::setCookies($with), 'the probe: with consent auto_start\'s cookie goes out');
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    public function testNoSessionFileWithoutConsent(): void
    {
        $before = glob(self::$dir . '/sessions/sess_*') ?: [];
        $c = $this->client(null);
        $c->get('/login');
        $c->rest('GET', '/api/v1/session');
        $c->post('/login', ['username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertSame($before, glob(self::$dir . '/sessions/sess_*') ?: [], 'nothing on the server either');
    }

    public function testTheSignInPageShowsTheNoticeAndNoToken(): void
    {
        $r = $this->client(null)->get('/login');
        $this->assertStringContainsString('id="consent-accept" data-consent="v1"', $r['body']);
        $this->assertStringContainsString('<meta name="csrf" content="">', $r['body']);
        $with = $this->client()->get('/login');
        $this->assertStringNotContainsString('id="consent"', $with['body']);
        $this->assertMatchesRegularExpression('/name="csrf" value="[a-f0-9]{64}"/', $with['body']);
    }

    public function testHtmlWritesGetTheFriendlyPage(): void
    {
        $r = $this->client(null)->post('/login', ['username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertSame(403, $r['status']);
        $this->assertStringStartsWith('text/html', $r['headers']['content-type']);
        $this->assertStringContainsString('Cookies are needed to sign in or save changes.', $r['body']);
        $this->assertStringContainsString('id="consent-accept"', $r['body']);
        $json = $this->client(null)->api('/theme', ['theme' => 'dark']);
        $this->assertSame([403, false, 'consent_required'], [$json['status'], $json['json']['ok'] ?? null, $json['json']['code'] ?? null]);
    }

    public function testApiWritesGetConsentRequired(): void
    {
        $c = $this->client(null);
        $s = $c->rest('GET', '/api/v1/session');
        $this->assertSame('', $s['json']['csrf_token'], 'no session, so no token');
        ApiContract::assertMatches('GET', '/api/v1/session', $s);
        foreach ([['POST', ['username' => 'admin', 'password' => self::PASSWORD]], ['DELETE', null]] as [$method, $data]) {
            $r = $c->rest($method, '/api/v1/session', $data, ['X-CSRF-Token: x']);
            $this->assertSame(403, $r['status'], $method);
            $this->assertSame('application/problem+json', $r['headers']['content-type'], $method);
            $this->assertSame('consent_required', $r['json']['error'], $method);
            $this->assertSame('consent_required', $r['json']['code'], $method);
            $this->assertSame(403, $r['json']['status'], $method);
            $this->assertSame([], self::setCookies($r), $method);
            ApiContract::assertMatches($method, '/api/v1/session', $r);
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function wrongValues(): iterable
    {
        foreach (['v0', '', '1', 'V1', 'v1x', 'true', 'v2'] as $v) {
            yield var_export($v, true) => [$v];
        }
    }

    /** Any other value answers exactly as no cookie: status, headers apart from Date, body. */
    #[DataProvider('wrongValues')]
    public function testAWrongValueAnswersLikeNoCookie(string $value): void
    {
        foreach ([['GET', '/login'], ['GET', '/api/v1/session'], ['POST', '/login'], ['POST', '/api/v1/session'], ['GET', '/brand/no-such.png'], ['GET', '/']] as [$method, $path]) {
            $none = self::comparable($this->send($this->client(null), $method, $path));
            $wrong = self::comparable($this->send($this->client($value), $method, $path));
            $this->assertSame($none, $wrong, $method . ' ' . $path);
        }
    }

    /**
     * A host-only cookie (self-hosted) and one with a Domain (hosted, COOKIE_DOMAIN) arrive as the same Cookie
     * header, often among other cookies; both count. The name is exact.
     */
    public function testHostOnlyAndParentDomainCookiesBothCount(): void
    {
        foreach (['apotheke_consent=v1', 'theme=dark; apotheke_consent=v1; _ga=GA1.2.3'] as $header) {
            $c = $this->client(null);
            $r = $c->get('/login', ['Cookie: ' . $header]);
            $this->assertNotSame([], self::setCookies($r), $header);
            $this->assertStringNotContainsString('id="consent"', $r['body'], $header);
        }
        foreach (['Apotheke_Consent=v1', 'apotheke_consent_x=v1', 'xapotheke_consent=v1'] as $header) {
            $r = $this->client(null)->get('/login', ['Cookie: ' . $header]);
            $this->assertSame([], self::setCookies($r), $header);
        }
    }

    /** With consent nothing changes: the session cookie, CSRF and a sign-in end to end. */
    public function testWithConsentSignInWorksEndToEnd(): void
    {
        $c = $this->client();
        $page = $c->get('/login');
        $this->assertMatchesRegularExpression('/^apothekesid=[A-Za-z0-9,-]+;/', self::setCookies($page)[0] ?? '');
        $this->assertSame(1, preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m));
        $in = $c->post('/login', ['csrf' => $m[1], 'username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertSame(303, $in['status'], $in['body']);
        $bad = $c->post('/theme', ['csrf' => 'bad', 'theme' => 'dark']);
        $this->assertSame(419, $bad['status'], 'the CSRF check still runs');
        $this->assertNotSame([], self::setCookies($in), 'a new session id');
        $this->assertSame(200, $c->rest('GET', '/api/v1/me')['status']);
        $this->assertSame(200, $c->get('/')['status']);
        $theme = $c->post('/theme', ['csrf' => $c->csrf(), 'theme' => 'dark']);
        $this->assertSame(303, $theme['status']);
        $this->assertContains('theme=dark', array_map(static fn (string $l): string => explode(';', $l)[0], self::setCookies($theme)));
        $api = $this->client();
        $token = $api->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(200, $api->rest('POST', '/api/v1/session', ['username' => 'admin', 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token])['status']);
    }

    /** A signed-in session cookie without consent (from before 3.4, or consent cleared) is not used or renewed. */
    public function testAnOldSessionCookieIsIgnoredWithoutConsent(): void
    {
        $c = $this->client();
        $c->post('/login', ['csrf' => $c->csrf(), 'username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertSame(200, $c->rest('GET', '/api/v1/me')['status']);
        $c->consent = null;
        $me = $c->rest('GET', '/api/v1/me');
        $this->assertSame(401, $me['status']);
        $this->assertSame([], self::setCookies($me));
        foreach (['/api/v1/users', '/api/v1/roles', '/api/v1/security'] as $path) {
            $r = $c->rest('GET', $path);
            $this->assertSame([401, []], [$r['status'], self::setCookies($r)], $path . ': the admin session is not used');
        }
        $c->consent = 'v1';
        $this->assertSame(200, $c->rest('GET', '/api/v1/me')['status'], 'the session is still there once consent is back');
        $this->assertSame(200, $c->rest('GET', '/api/v1/users')['status']);
    }

    /**
     * Sign-in off: an item page sets no recently-viewed cookie and records nothing without consent, and a 500
     * answer (the exception handler, after filters skipped) sets no cookie either.
     */
    public function testSignInOffItemPageAndServerErrorSetNoCookie(): void
    {
        $dir = tmpdir('consent-500');
        $server = new Server($dir, ['APP_PASSWORD' => null], 'ci4');
        try {
            $web = $server->client();
            $saved = $web->post('/save', ['csrf' => $web->csrf(), 'name' => 'Drill', 'quantity' => '1']);
            $this->assertSame(1, preg_match('/saved=(\d+)/', $saved['headers']['location'] ?? '', $m), $saved['body']);
            $anon = $server->client();
            $anon->consent = null;
            $item = $anon->get('/item/' . $m[1]);
            $this->assertSame(200, $item['status']);
            $this->assertSame([], self::setCookies($item));
            $this->assertSame(0, (int)testdb($dir)->query('SELECT COUNT(*) FROM recent_views')->fetchColumn());
            $this->assertSame(403, $anon->post('/save', ['name' => 'X', 'quantity' => '1'])['status']);
            $with = $server->client();
            $this->assertNotSame([], self::setCookies($with->get('/item/' . $m[1])), 'with consent the device cookie is set');
            testdb($dir)->exec('ALTER TABLE items RENAME TO items_gone');   // both drivers (pgsql refuses a DROP with foreign keys)
            $c = $server->client();
            $c->consent = null;
            $r = $c->get('/');
            $this->assertSame(500, $r['status'], substr($r['body'], 0, 300));
            $this->assertSame([], self::setCookies($r));
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    private function client(?string $consent = 'v1'): Client
    {
        $this->assertNotNull(self::$server);
        $c = self::$server->client();
        $c->consent = $consent;

        return $c;
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:mixed,raw:list<string>} */
    private function send(Client $c, string $method, string $path): array
    {
        if (str_starts_with($path, '/api/')) {
            return $c->rest($method, $path, $method === 'POST' ? ['username' => 'admin', 'password' => self::PASSWORD] : null);
        }

        return $method === 'GET' ? $c->get($path) : $c->post($path, ['username' => 'admin', 'password' => self::PASSWORD]);
    }

    /** @param array{status:int,body:string,raw:list<string>} $r @return array{status:int,headers:list<string>,body:string} */
    private static function comparable(array $r): array
    {
        $headers = array_values(array_filter($r['raw'], static fn (string $l): bool => stripos($l, 'date:') !== 0));

        return ['status' => $r['status'], 'headers' => $headers, 'body' => $r['body']];
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
