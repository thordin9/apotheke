<?php

declare(strict_types=1);

namespace Tests\Core;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use ReflectionClass;

/**
 * Filters keep no state: services are resolved inside before()/after() (the
 * PHPStan NoServiceRule enforces that), and no filter has a static or instance
 * property that could carry a value into the next worker request. Nor does
 * any of their methods, or a Config\Services factory, keep a function-level
 * `static` variable, which would outlive the request the same way.
 */
final class FilterStateTest extends CIUnitTestCase
{
    public function testModuleFiltersDeclareNoProperties(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/modules/*/Filters/*.php') ?: [];
        $this->assertNotEmpty($files);
        $checked = 0;
        foreach ($files as $file) {
            $module = basename(dirname($file, 2));
            $class = 'Apotheke\\' . $module . '\\Filters\\' . basename($file, '.php');
            $this->assertTrue(class_exists($class), $class);
            $reflection = new ReflectionClass($class);
            $this->assertTrue($reflection->implementsInterface(FilterInterface::class), $class);
            $this->assertSame([], $reflection->getProperties(), $class);
            foreach ($reflection->getMethods() as $method) {
                $this->assertSame([], $method->getStaticVariables(), $class . '::' . $method->getName());
            }
            $checked++;
        }
        $this->assertGreaterThanOrEqual(7, $checked);
    }

    public function testServiceFactoriesKeepNoStaticVariables(): void
    {
        $factories = 0;
        foreach ((new ReflectionClass(Services::class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== Services::class) {
                continue;
            }
            $this->assertSame([], $method->getStaticVariables(), 'Services::' . $method->getName());
            $factories++;
        }
        $this->assertGreaterThanOrEqual(5, $factories);
    }
}
