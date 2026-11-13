<?php

declare(strict_types=1);

namespace Tests\Modules\Backup;

use Apotheke\Backup\Restorer;
use Apotheke\Backup\RestoreStage;
use Apotheke\Backup\StoredBackups;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WorkerMode;
use ReflectionClass;

/**
 * Worker mode keeps only WorkerMode::$persistentServices between requests: the restorer (with its failure log and
 * step) and the kept backups are built per request and hold no static state.
 */
final class RestorerWorkerModeTest extends CIUnitTestCase
{
    public function testTheRestorerAndItsLogAreBuiltPerRequest(): void
    {
        $persistent = (new WorkerMode())->persistentServices;
        foreach (['restorer', 'storedBackups', 'backups'] as $service) {
            $this->assertNotContains($service, $persistent);
        }
        foreach ([Restorer::class, RestoreStage::class, StoredBackups::class] as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                $this->assertFalse($property->isStatic(), $class . '::$' . $property->getName());
            }
            foreach ($reflection->getMethods() as $method) {
                $this->assertSame([], $method->getStaticVariables(), $class . '::' . $method->getName());
            }
        }
    }
}
