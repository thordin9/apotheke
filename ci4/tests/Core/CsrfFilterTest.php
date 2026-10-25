<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Filters\CsrfFilter;
use Apotheke\Core\Http\CsrfCheck;
use Apotheke\Core\Http\SecurityPolicy;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Filters;

/** SEC-N10: legacy check_csrf() as a global before-filter. */
final class CsrfFilterTest extends CIUnitTestCase
{
    private const TOKEN = 'a3f1c0de5e55a3f1c0de5e55a3f1c0de5e55a3f1c0de5e55a3f1c0de5e55a3f1';

    /** @var array<string,mixed>|null */
    private ?array $savedSession = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : null;
        $_SESSION = ['csrf' => self::TOKEN];
    }

    protected function tearDown(): void
    {
        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }
        parent::tearDown();
    }

    public function testPostWithoutATokenIsRefused(): void
    {
        $response = $this->send('POST');
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(419, $response->getStatusCode());
        // Legacy view_error(): the app's error page (Layout).
        $this->assertStringContainsString('<p>' . CsrfCheck::MESSAGE . '</p>', (string)$response->getBody());
        $this->assertStringStartsWith('<!doctype html>', (string)$response->getBody());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $this->assertSame($value, $response->getHeaderLine($name), $name);
        }
    }

    public function testAValidFormFieldPasses(): void
    {
        $this->assertNull($this->send('POST', ['csrf' => self::TOKEN, 'name' => 'x']));
    }

    public function testAValidHeaderPasses(): void
    {
        $this->assertNull($this->send('POST', [], self::TOKEN));
        $this->assertNull($this->send('DELETE', [], self::TOKEN));
        $this->assertNull($this->send('PUT', [], self::TOKEN));
    }

    public function testAWrongOrEmptyTokenIsRefused(): void
    {
        $this->assertSame(419, $this->send('POST', ['csrf' => 'nope'])?->getStatusCode());
        $this->assertSame(419, $this->send('POST', [], 'nope')?->getStatusCode());
        $this->assertSame(419, $this->send('POST', ['csrf' => ''])?->getStatusCode());
        $this->assertSame(419, $this->send('POST', ['csrf' => [self::TOKEN]])?->getStatusCode());
        $this->assertSame(419, $this->send('PATCH')?->getStatusCode());
        $this->assertSame(419, $this->send('DELETE')?->getStatusCode());
    }

    public function testTheFieldWinsOverTheHeaderAsInLegacy(): void
    {
        $this->assertSame(419, $this->send('POST', ['csrf' => 'stale'], self::TOKEN)?->getStatusCode());
        $this->assertNull($this->send('POST', ['csrf' => self::TOKEN], 'stale'));
    }

    public function testNoSessionTokenRefusesEvenAnEmptyMatch(): void
    {
        $_SESSION = [];
        $this->assertSame(419, $this->send('POST', ['csrf' => ''])?->getStatusCode());
        unset($_SESSION);
        $this->assertSame(419, $this->send('POST', [], '')?->getStatusCode());
        $this->assertSame(419, $this->send('POST', ['csrf' => self::TOKEN])?->getStatusCode());
    }

    public function testJsonClientsGetTheLegacyJsonBody(): void
    {
        $response = $this->send('POST', [], '', ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        $this->assertSame(419, $response?->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['ok' => false, 'error' => CsrfCheck::MESSAGE], json_decode($response->getBody(), true));
        $accept = $this->send('POST', [], '', ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame('application/json; charset=utf-8', $accept?->getHeaderLine('Content-Type'));
    }

    public function testSafeMethodsPassWithoutATokenAndStartNoSession(): void
    {
        unset($_SESSION);
        $this->assertSame(PHP_SESSION_NONE, session_status());
        foreach (['GET', 'HEAD', 'OPTIONS', 'get'] as $method) {
            $this->assertNull($this->send($method), $method);
        }
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertFalse(isset($_SESSION));
    }

    public function testRegisteredAsAGlobalBeforeFilterWithoutCi4Csrf(): void
    {
        $filters = config(Filters::class);
        $this->assertSame(CsrfFilter::class, $filters->aliases['sessioncsrf']);
        $this->assertContains('sessioncsrf', $filters->globals['before']);
        $this->assertNotContains('csrf', $filters->globals['before']);
        $this->assertNotContains('csrf', $filters->required['before']);
        $this->assertArrayNotHasKey('csrf', $filters->globals['before']);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,string> $server
     */
    private function send(string $method, array $post = [], string $header = '', array $server = []): ?ResponseInterface
    {
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'items/1'), null, new UserAgent());
        $request = $request->withMethod($method);
        $request->setGlobal('post', $post);
        $request->setGlobal('server', $server);
        if ($header !== '') {
            $request->setHeader('X-CSRF-Token', $header);
        }

        return (new CsrfFilter())->before($request);
    }
}
