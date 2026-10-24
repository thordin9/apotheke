<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Filters\PublicBaseFilter;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * #76 L-2: CI4's base URL is legacy public_url() on main, resolved per request
 * through the request's SettingsService: the public_url setting (PUBLIC_URL
 * when the Control Panel leaves it empty), else the request's scheme with
 * trust_proxy and an allowed Host, else localhost.
 */
final class PublicBaseTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        Services::reset();
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function testTheControlPanelAddressWinsOverTheRequestHost(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->save(['public_url' => 'https://shelf.example']);
        foreach (['192.168.1.10:9091', 'localhost', 'rebind-check.example', ''] as $host) {
            $uri = $this->request(['HTTP_HOST' => $host]);
            $this->assertSame('https://shelf.example/labels', $uri->baseUrl('labels'), $host);
            $this->assertSame('https://shelf.example/settings/autofill', $uri->siteUrl('settings/autofill'), $host);
        }
        $this->save(['public_url' => 'https://inv.example.org:8443/apotheke']);
        $this->assertSame('https://inv.example.org:8443/apotheke/labels', $this->request(['HTTP_HOST' => '127.0.0.1:9091'])->baseUrl('labels'));
    }

    /** No stored public_url row: the setting's default is PUBLIC_URL, as legacy setting_default(). */
    #[DataProvider('drivers')]
    public function testPublicUrlFromTheEnvironmentWithoutAStoredSetting(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->assertNotNull($this->database);
        $this->database->db->table('settings')->where('name', 'public_url')->delete();
        $uri = $this->request(['HTTP_HOST' => '127.0.0.1:9091'], ['public_url' => 'https://env.example:8443']);
        $this->assertSame('https://env.example:8443/labels', $uri->baseUrl('labels'));
    }

    #[DataProvider('drivers')]
    public function testWithoutAnAddressTheSchemeFollowsTrustProxy(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $proxied = ['HTTP_HOST' => '192.168.1.10:9091', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertSame('http://192.168.1.10:9091/labels', $this->request($proxied)->baseUrl('labels'), 'trust_proxy off');
        $this->save(['trust_proxy' => true]);
        $this->assertSame('https://192.168.1.10:9091/labels', $this->request($proxied)->baseUrl('labels'), 'trust_proxy on');
        $this->assertSame('https://192.168.1.10:9091/labels', $this->request(['HTTP_HOST' => '192.168.1.10:9091', 'HTTPS' => 'on'])->baseUrl('labels'));
    }

    #[DataProvider('drivers')]
    public function testAHostThatFailsTheAllowListBecomesLocalhost(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->assertSame('http://localhost/labels', $this->request(['HTTP_HOST' => 'rebind-check.example'])->baseUrl('labels'));
        $this->assertSame('http://localhost/labels', $this->request(['HTTP_HOST' => ''])->baseUrl('labels'));
    }

    /** Worker mode: a public_url saved between two requests applies to the second. */
    #[DataProvider('drivers')]
    public function testEachRequestResolvesItsOwnBase(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->assertSame('http://127.0.0.1:9091/labels', $this->request(['HTTP_HOST' => '127.0.0.1:9091'])->baseUrl('labels'));
        $this->save(['public_url' => 'https://shelf.example']);
        $this->assertSame('https://shelf.example/labels', $this->request(['HTTP_HOST' => '127.0.0.1:9091'])->baseUrl('labels'));
        $this->save(['public_url' => '']);
        $this->assertSame('http://127.0.0.1:9091/labels', $this->request(['HTTP_HOST' => '127.0.0.1:9091'])->baseUrl('labels'));
    }

    #[DataProvider('drivers')]
    public function testHealthIsLeftAlone(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->save(['public_url' => 'https://shelf.example']);
        $before = config(App::class)->baseURL;
        $this->request(['HTTP_HOST' => '127.0.0.1:9091'], [], 'healthz');
        $this->assertSame($before, config(App::class)->baseURL);
    }

    /** @param array<string,mixed> $values */
    private function save(array $values): void
    {
        $this->assertSame([], $this->settings()->save($values));
    }

    /** @param array<string,mixed> $config */
    private function settings(array $config = []): SettingsService
    {
        $database = $this->database;
        $this->assertNotNull($database);

        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $config + $database->config->values(), static fn (string $m): null => null);
    }

    /**
     * One request through the filter, with a new SettingsService and App
     * config as CI4 gives each (worker) request. Returns the request's URI.
     *
     * @param array<string,string> $server
     * @param array<string,mixed> $config
     */
    private function request(array $server, array $config = [], string $path = 'items'): SiteURI
    {
        Services::reset();
        \CodeIgniter\Config\Factories::reset('config');
        Services::injectMock('settings', $this->settings($config));
        $app = config(App::class);
        $uri = new SiteURI($app, $path);
        $request = new IncomingRequest($app, $uri, null, new UserAgent());
        $request->setGlobal('server', $server);
        $this->assertNull((new PublicBaseFilter())->before($request));

        return $uri;
    }
}
