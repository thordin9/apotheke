<?php

declare(strict_types=1);

namespace Tests\Core;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * Filters keep no state: services are resolved inside before()/after() (the
 * PHPStan NoServiceRule enforces that), and no filter has a static or instance
 * property that could carry a value into the next worker request.
 */
final class FilterStateTest extends CIUnitTestCase
{
    public function testModuleFiltersDeclareNoProperties(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/modules/*/Filters/*.php') ?: [];
        $this->assertNotEmpty($files);
        $checked = 0;
        foreach ($files as $file) {
            $module = basename(dirname($file, 2));
            $class = 'Apotheke\\' . $module . '\\Filters\\' . basename($file, '.php');
            $this->assertTrue(class_exists($class), $class);
            $reflection = new ReflectionClass($class);
            $this->assertTrue($reflection->implementsInterface(FilterInterface::class), $class);
            $this->assertSame([], $reflection->getProperties(), $class);
            $checked++;
        }
        $this->assertGreaterThanOrEqual(7, $checked);
    }
}
