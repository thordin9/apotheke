<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\CompiledRouteCollection;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Modules;
use Config\Routing;
use Config\Services;

/**
 * The compiled routes (PLAN step 16 part 3) are exactly what app/Config/Routes.php builds, the
 * committed file is up to date, and a stale file or a route added before loading falls back to
 * Routes.php.
 */
final class CompiledRoutesTest extends CIUnitTestCase
{
    public function testTheCommittedFileIsUpToDate(): void
    {
        $this->assertSame(
            $this->collection()->compile(),
            (string)file_get_contents(CompiledRouteCollection::compiledFile()),
            'app/Config/RoutesCompiled.php is out of date: run php spark apotheke:routes'
        );
    }

    public function testTheServiceIsTheCompiledCollection(): void
    {
        $this->assertInstanceOf(CompiledRouteCollection::class, Services::routes(false));
    }

    public function testCompiledRoutesEqualRoutesPhpInEveryProperty(): void
    {
        $plain = new RouteCollection(Services::locator(), new Modules(), config(Routing::class));
        $plain->loadRoutes();
        $compiled = $this->collection();
        $compiled->loadRoutes();
        $this->assertTrue($this->usedCompiledFile($compiled));
        $this->assertSame($this->state($plain), $this->state($compiled));
        $this->assertNotSame([], $compiled->getRoutes('GET'));
    }

    public function testLoadingTheRoutesSetsOnlyTheCompiledProperties(): void
    {
        $before = new RouteCollection(Services::locator(), new Modules(), config(Routing::class));
        $after = new RouteCollection(Services::locator(), new Modules(), config(Routing::class));
        $after->loadRoutes();
        $changed = [];
        foreach ($this->state($after) as $name => $value) {
            if ($this->state($before)[$name] !== $value) {
                $changed[] = $name;
            }
        }
        sort($changed);
        $want = [...CompiledRouteCollection::PROPERTIES, 'didDiscover'];
        sort($want);
        $this->assertSame($want, $changed, 'Routes.php sets a property the compiled routes do not carry');
    }

    public function testAStaleFileFallsBackToRoutesPhp(): void
    {
        $file = CompiledRouteCollection::compiledFile();
        $saved = (string)file_get_contents($file);
        try {
            file_put_contents($file, preg_replace("/'hash' => '[^']+'/", "'hash' => 'stale'", $saved, 1));
            $routes = $this->collection();
            $routes->loadRoutes();
            $this->assertFalse($this->usedCompiledFile($routes));
            $this->assertSame($this->state($this->plainLoaded()), $this->state($routes));
        } finally {
            file_put_contents($file, $saved);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        }
    }

    public function testARouteAddedBeforeLoadingKeepsCi4sOrder(): void
    {
        $routes = $this->collection();
        $routes->get('test-before', '\\Apotheke\\Core\\Controllers\\HealthController::healthz', ['permission' => 'none']);
        $routes->loadRoutes();
        $plain = new RouteCollection(Services::locator(), new Modules(), config(Routing::class));
        $plain->get('test-before', '\\Apotheke\\Core\\Controllers\\HealthController::healthz', ['permission' => 'none']);
        $plain->loadRoutes();
        $this->assertFalse($this->usedCompiledFile($routes));
        $this->assertSame($this->state($plain), $this->state($routes));
    }

    private function collection(): CompiledRouteCollection
    {
        return new CompiledRouteCollection(Services::locator(), new Modules(), config(Routing::class));
    }

    private function plainLoaded(): RouteCollection
    {
        $plain = new RouteCollection(Services::locator(), new Modules(), config(Routing::class));
        $plain->loadRoutes();

        return $plain;
    }

    /** Whether loadRoutes() took the compiled file: Routes.php was not included by this collection. */
    private function usedCompiledFile(CompiledRouteCollection $routes): bool
    {
        return (fn (): bool => $this->loadedFromCompiled)->call($routes);
    }

    /** @return array<string,mixed> every scalar or array property, objects left out */
    private function state(RouteCollection $routes): array
    {
        $out = [];
        foreach ((new \ReflectionClass(RouteCollection::class))->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $value = $property->getValue($routes);
            if (!is_object($value)) {
                $out[$property->getName()] = $value;
            }
        }
        ksort($out);

        return $out;
    }
}
