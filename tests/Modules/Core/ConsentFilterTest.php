<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Filters\ConsentFilter;
use Apotheke\Core\Http\CookieConsent;
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
        foreach (['POST', 'put', 'PATCH', 'DELETE'] as $m) {
            $this->assertTrue($consent->needed($m), $m);
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
