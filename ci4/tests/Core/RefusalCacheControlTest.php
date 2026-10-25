<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Exceptions\PlainHandler;
use Apotheke\Core\Filters\CsrfFilter;
use Apotheke\Core\Filters\PostTooLargeFilter;
use Apotheke\Core\Filters\SameOriginFilter;
use Apotheke\Core\Http\ErrorPage;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use Tests\Support\TestDatabase;

/**
 * N-19: the refusals the Core filters answer themselves (CSRF 419, the
 * same-origin 403, the dropped-body 413) are never cached. JSON answers send
 * exactly legacy's `no-store`; the HTML error pages keep CI4's no-store
 * header (a PM decision: legacy sends none there).
 */
final class RefusalCacheControlTest extends CIUnitTestCase
{
    private const JSON = ['HTTP_X_REQUESTED_WITH' => 'fetch'];

    private ?TestDatabase $database = null;

    /** @var array<string,mixed>|null */
    private ?array $savedSession = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : null;
        $_SESSION = ['csrf' => str_repeat('a', 64)];
        Services::injectMock('errorPage', new class implements ErrorPage {
            public function html(int $status, string $message): string
            {
                return "<page $status>" . $message;
            }
        });
    }

    protected function tearDown(): void
    {
        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }
        $this->database?->drop();
        $this->database = null;
        Services::reset(true);
        parent::tearDown();
    }

    public function testCsrf419(): void
    {
        $this->assertRefusal(419, (new CsrfFilter())->before($this->request()), (new CsrfFilter())->before($this->request(self::JSON)));
    }

    public function testSameOrigin403(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $database = $this->database;
        Services::injectMock('settings', new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'),
            new Clock(), $database->config->values(), static fn (string $m): null => null, new CommandLine()));
        $cross = ['HTTP_ORIGIN' => 'http://other.example'];
        $this->assertRefusal(403, (new SameOriginFilter())->before($this->request($cross)), (new SameOriginFilter())->before($this->request($cross + self::JSON)));
    }

    public function testPostTooLarge413(): void
    {
        $big = ['CONTENT_LENGTH' => '99999999'];
        $this->assertRefusal(413, (new PostTooLargeFilter())->before($this->request($big)), (new PostTooLargeFilter())->before($this->request($big + self::JSON)));
    }

    /** The exception handler's HTML error pages keep no-store too; its JSON is legacy's. */
    public function testErrorPages(): void
    {
        $handler = new PlainHandler(static fn (): ErrorPage => Services::errorPage());
        $html = $handler->render(PageNotFoundException::forPageNotFound(), new Response(config(App::class)));
        $this->assertSame([404, '<page 404>Page not found.'], [$html->getStatusCode(), (string)$html->getBody()]);
        $this->assertStringContainsString('no-store', $html->getHeaderLine('Cache-Control'));
        $json = $handler->render(new \RuntimeException('x'), new Response(config(App::class)), self::JSON);
        $this->assertSame([500, 'no-store'], [$json->getStatusCode(), $json->getHeaderLine('Cache-Control')]);
    }

    private function assertRefusal(int $status, ?ResponseInterface $html, ?ResponseInterface $json): void
    {
        $this->assertNotNull($html);
        $this->assertNotNull($json);
        $this->assertSame([$status, 'text/html; charset=UTF-8'], [$html->getStatusCode(), $html->getHeaderLine('Content-Type')]);
        $this->assertStringStartsWith("<page $status>", (string)$html->getBody());
        $this->assertStringContainsString('no-store', $html->getHeaderLine('Cache-Control'), 'the HTML page is never cached (PM: keep CI4 no-store)');
        $this->assertSame([$status, 'application/json; charset=utf-8', 'no-store'], [$json->getStatusCode(), $json->getHeaderLine('Content-Type'), $json->getHeaderLine('Cache-Control')]);
    }

    /** @param array<string,string> $server */
    private function request(array $server = []): IncomingRequest
    {
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'items/1'), null, new UserAgent());
        $request = $request->withMethod('POST');
        $request->setGlobal('post', []);
        $request->setGlobal('server', $server + ['REQUEST_METHOD' => 'POST']);

        return $request;
    }
}
