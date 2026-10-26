<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\LegacyPathRouter;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The router matches legacy's exact path (#101). A path CI4 would trim,
 * decode again or resolve into a route is a 404, as on legacy; so is a `+`,
 * which CI4 decoded to a space (#101 L-72).
 */
final class LegacyPathRouterTest extends CIUnitTestCase
{
    /** @return iterable<string,array{0:string,1:string}> REQUEST_URI => the routed path, or '' for a 404 */
    public static function paths(): iterable
    {
        foreach (['/stats' => 'stats', '/' => '/', '/stats?x=1' => 'stats', '/%73tats' => 'stats', 'http://host.test/stats' => 'stats'] as $uri => $routed) {
            yield $uri => [$uri, $routed];
        }
        foreach (['/stats+', '/stats%2B', '/+stats', '/stats%20%2B', '/sta+ts', '/stats/', '/./stats', '/%2e/stats', '/a/../stats', '/stats%20', '/%2573tats', '//stats/x', '/stats//'] as $uri) {
            yield $uri => [$uri, ''];
        }
    }

    #[DataProvider('paths')]
    public function testOnlyTheExactPathRoutes(string $uri, string $routed): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, ltrim((string)parse_url($uri, PHP_URL_PATH), '/')), null, new UserAgent());
        $request->setGlobal('server', ['REQUEST_URI' => $uri] + $_SERVER);
        // GET whatever an earlier test left in $_SERVER.
        $router = new LegacyPathRouter($routes, $request->withMethod('GET'));
        if ($routed === '') {
            $this->expectException(PageNotFoundException::class);
        }
        $router->handle();
        $this->assertSame('\\Apotheke\\Catalog\\Controllers\\ListController', $router->controllerName(), $uri);
        $this->assertSame($routed === '/' ? 'index' : 'stats', $router->methodName(), $uri);
    }

    /** Legacy answers every path under /barcode/ with the lookup: a code may hold any character. */
    public function testEveryPathUnderBarcodeIsTheLookup(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        $config = config(App::class);
        foreach (['/barcode/123', '/barcode/', '/barcode/a+b', '/barcode/a%2Fb/', '/barcode/a/../b', '/barcode//x', '/barcode/%2e'] as $uri) {
            $request = new IncomingRequest($config, new SiteURI($config, ltrim((string)parse_url($uri, PHP_URL_PATH), '/')), null, new UserAgent());
            $request->setGlobal('server', ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET'] + $_SERVER);
            $router = new LegacyPathRouter($routes, $request->withMethod('GET'));
            $router->handle();
            $this->assertSame(['\\Apotheke\\Catalog\\Controllers\\BarcodeController', 'lookup'], [$router->controllerName(), $router->methodName()], $uri);
        }
        foreach (['/barcode' => 'index', '/barcodes/1' => ''] as $uri => $method) {
            $request = new IncomingRequest($config, new SiteURI($config, ltrim($uri, '/')), null, new UserAgent());
            $request->setGlobal('server', ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET'] + $_SERVER);
            $router = new LegacyPathRouter($routes, $request->withMethod('GET'));
            if ($method === '') {
                $this->expectException(PageNotFoundException::class);
            }
            $router->handle();
            $this->assertSame($method, $router->methodName(), $uri);
        }
    }

    public function testThePathIsDecodedOnceAndNotTrimmed(): void
    {
        $this->assertSame('/stats%2B', LegacyPathRouter::legacyPath('/stats%252B'));
        $this->assertSame('/stats+', LegacyPathRouter::legacyPath('/stats%2B?q=a+b'));
        $this->assertSame('/healthz', LegacyPathRouter::legacyPath('http://host.test/healthz'));
        $this->assertFalse(LegacyPathRouter::exact('/stats+'));
        $this->assertFalse(LegacyPathRouter::exact('/stats +'));
        $this->assertTrue(LegacyPathRouter::exact('/item/1/edit'));
    }
}
