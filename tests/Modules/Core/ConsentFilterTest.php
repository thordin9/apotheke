<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Filters\ConsentFilter;
use Apotheke\Core\Http\CookieConsent;
use Apotheke\Core\Http\SameOrigin;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Consent;
use Config\Filters;

/** Cookie consent (Config\Consent): the contract and the filter's backstop. */
final class ConsentFilterTest extends CIUnitTestCase
{
    public function testTheContractIsTheExactNameAndAnAcceptedValue(): void
    {
        $this->assertSame(['apotheke_consent', ['v1']], [Consent::COOKIE, Consent::ACCEPTED]);
        $consent = new CookieConsent();
        $this->assertTrue($consent->given(['apotheke_consent' => 'v1']));
        foreach (['v0', '', '1', 'V1', ' v1', 'v1 ', 'v2'] as $value) {
            $this->assertFalse($consent->given(['apotheke_consent' => $value]), var_export($value, true));
        }
        $this->assertFalse($consent->given(['apotheke_consent' => ['v1']]));
        $this->assertFalse($consent->given(['Apotheke_Consent' => 'v1']));
        $this->assertFalse($consent->given([]));
        $this->assertTrue((new CookieConsent('apotheke_consent', ['v1', 'v2']))->given(['apotheke_consent' => 'v2']), 'a later version once listed');
    }

    public function testOnlyStateChangingMethodsNeedIt(): void
    {
        $consent = new CookieConsent();
        foreach (['GET', 'head', 'OPTIONS'] as $m) {
            $this->assertFalse($consent->needed($m), $m);
        }
        foreach (['POST', 'put', 'PATCH', 'DELETE', 'TRACE'] as $m) {
            $this->assertTrue($consent->needed($m), $m);
        }
    }

    /** COOKIE_CONSENT: only `off` turns it off; any other value, or none, is required. */
    public function testTheSwitchIsOffOnlyForOff(): void
    {
        foreach (['off', 'OFF', ' off '] as $v) {
            $this->assertFalse(Consent::requiredFrom($v), $v);
        }
        foreach ([false, '', 'required', 'of', 'no', '0', 'false'] as $v) {
            $this->assertTrue(Consent::requiredFrom($v), var_export($v, true));
        }
        $this->assertTrue((new CookieConsent('apotheke_consent', ['v1'], false))->given([]), 'off: every request counts as consent');
    }

    /** @return iterable<string,array{0:mixed}> */
    public static function badReturnPaths(): iterable
    {
        foreach (['', 'login', '//evil.test', '//evil.test/x', '/\\evil.test', '\\\\evil.test', '\\/evil.test', 'https://evil.test/',
            'javascript:alert(1)', '%2F%2Fevil.test', '/%2F%2Fevil.test', '/%2fevil.test', '/%5Cevil.test', '/%5cevil.test', '/%252F%252Fevil.test',
            "/x\r\nSet-Cookie: a=b", '/x%0d%0aSet-Cookie:%20a=b', '/x%0aLocation:%20//evil', "/x\0", '/a b', '/a//b', ' /x',
            '/' . str_repeat('a', 2048)] as $p) {
            yield var_export($p, true) => [$p];
        }
        yield 'array' => [['/x']];
        yield 'null' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badReturnPaths')]
    public function testABadReturnPathIsTheStartPage(mixed $path): void
    {
        $this->assertSame('/', CookieConsent::returnPath($path));
    }

    public function testAPathOnThisSiteIsKept(): void
    {
        foreach (['/', '/login', '/item/12?tab=notes&x=%20y', '/a%2Fb', '/search?q=caf%C3%A9#top'] as $p) {
            $this->assertSame($p, CookieConsent::returnPath($p));
        }
        $this->assertSame('/search?q=caf%C3%A9&x=%E2%9C%93', CookieConsent::returnPath("/search?q=caf\u{e9}&x=\u{2713}"), 'N-1424: non-ASCII percent-encoded');
    }

    public function testTheCookieMatchesTheNoticeScript(): void
    {
        $this->assertSame('apotheke_consent=v1; Path=/; Max-Age=15552000; SameSite=Lax', CookieConsent::cookie('apotheke.local:8080', false));
        $this->assertSame('apotheke_consent=v1; Path=/; Max-Age=15552000; Domain=getapotheke.com; Secure; SameSite=Lax', CookieConsent::cookie('App.GetApotheke.com.', true));
        foreach (['getapotheke.com', 'a.b.getapotheke.com:443'] as $h) {
            $this->assertSame('getapotheke.com', CookieConsent::domain($h), $h);
        }
        foreach (['getapotheke.com.evil.test', 'evilgetapotheke.com', 'localhost', ''] as $h) {
            $this->assertSame('', CookieConsent::domain($h), $h);
        }
    }

    /** POST /consent: strictly same-origin; same-site, cross-site, none, a foreign or null Origin, or neither header is refused. */
    public function testConsentNeedsAStrictlySameOriginRequest(): void
    {
        $rule = new SameOrigin();
        $host = ['HTTP_HOST' => 'app.test'];
        $this->assertTrue($rule->strict($host + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], false));
        $this->assertTrue($rule->strict($host + ['HTTP_ORIGIN' => 'http://app.test'], false));
        $this->assertTrue($rule->strict($host + ['HTTP_ORIGIN' => 'https://pub.test'], false, 'https://pub.test/'));
        foreach ([[], ['HTTP_SEC_FETCH_SITE' => 'same-site'], ['HTTP_SEC_FETCH_SITE' => 'cross-site'], ['HTTP_SEC_FETCH_SITE' => 'none'],
            ['HTTP_ORIGIN' => 'http://evil.test'], ['HTTP_ORIGIN' => 'null'], ['HTTP_ORIGIN' => 'https://app.test'],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => 'http://evil.test'], ['HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_ORIGIN' => 'http://app.test'], ['HTTP_SEC_FETCH_SITE' => 'none', 'HTTP_ORIGIN' => 'http://app.test']] as $h) {
            $this->assertFalse($rule->strict($host + $h, false), json_encode($h));
        }
    }

    public function testItRunsBeforeTheSessionAndLastAfter(): void
    {
        $required = (new Filters())->required;
        $before = $required['before'];
        $this->assertLessThan(array_search('session', $before, true), array_search('consent', $before, true));
        $this->assertLessThan(array_search('consent', $before, true), array_search('api', $before, true), 'a CORS preflight still answers');
        $this->assertSame('consent', $required['after'][array_key_last($required['after'])]);
    }

    public function testWithoutConsentTheAnswerLosesEveryCookie(): void
    {
        $response = $this->answerWithCookies();
        (new ConsentFilter())->after($this->request([]), $response);
        $this->assertSame([], $response->getCookies());
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    /** POST /consent's cookie stays; the answer gets no-store when it has no Cache-Control (no session sends one). */
    public function testWithoutConsentOnlyTheConsentCookieStays(): void
    {
        $response = $this->answerWithCookies();
        $response->removeHeader('Cache-Control');
        $response->setHeader('Set-Cookie', 'apotheke_consent=v1; Path=/; Max-Age=15552000; SameSite=Lax');
        (new ConsentFilter())->after($this->request([]), $response);
        $this->assertSame([], $response->getCookies());
        $this->assertSame('apotheke_consent=v1; Path=/; Max-Age=15552000; SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $kept = new Response(config(App::class));
        $kept->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-cache');
        (new ConsentFilter())->after($this->request([]), $kept);
        $this->assertSame('no-cache', $kept->getHeaderLine('Cache-Control'), 'an answer\'s own caching stays');
    }

    public function testWithConsentTheCookiesStay(): void
    {
        $response = $this->answerWithCookies();
        $this->assertNull((new ConsentFilter())->after($this->request(['apotheke_consent' => 'v1']), $response));
        $this->assertCount(1, $response->getCookies());
        $this->assertTrue($response->hasHeader('Set-Cookie'));
    }

    private function answerWithCookies(): Response
    {
        $response = new Response(config(App::class));
        $response->setCookie(new Cookie('recent', 'abc'));
        $response->setHeader('Set-Cookie', 'theme=dark; path=/');

        return $response;
    }

    /** @param array<string,string> $cookies */
    private function request(array $cookies): IncomingRequest
    {
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'login'), null, new UserAgent());
        $request->setGlobal('cookie', $cookies);

        return $request;
    }
}
