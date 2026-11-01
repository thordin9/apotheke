<?php

declare(strict_types=1);

namespace Tests\Core;

use CodeIgniter\Test\CIUnitTestCase;
use Config\WorkerMode;
use ReflectionClass;

/**
 * Request state of the executor adapter (ADR-002, "Request state"): no class
 * of Core\Database keeps static state, and the executor is per request
 * (never persistent in worker mode).
 */
final class DatabaseStateTest extends CIUnitTestCase
{
    public function testCoreDatabaseDeclaresNoStaticState(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/modules/Core/Database/*.php') ?: [];
        $this->assertGreaterThanOrEqual(5, count($files));
        foreach ($files as $file) {
            $class = 'Apotheke\\Core\\Database\\' . basename($file, '.php');
            $this->assertTrue(class_exists($class) || interface_exists($class), $class);
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                $this->assertFalse($property->isStatic(), $class . '::$' . $property->getName());
            }
            foreach ($reflection->getMethods() as $method) {
                $this->assertSame([], $method->getStaticVariables(), $class . '::' . $method->getName());
            }
        }
    }

    public function testTheExecutorIsNeverPersistent(): void
    {
        $persistent = (new WorkerMode())->persistentServices;
        foreach (['sqlExecutor', 'apothekeDb'] as $service) {
            $this->assertNotContains($service, $persistent);
        }
    }
}
