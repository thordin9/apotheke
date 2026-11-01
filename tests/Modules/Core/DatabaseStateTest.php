<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Http\RequestCleanup;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Config\WorkerMode;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionClass;
use RuntimeException;
use Tests\Support\TestDatabase;
use Tests\Support\UowSchema;

/**
 * Request state of the executor adapter (ADR-002, "Request state"): no class
 * of Core\Database keeps static state, the executor and the units of work
 * are per request (never persistent in worker mode), and the registry of
 * open units is empty after every unit, a failed one included. A unit a
 * fatal error left open is discarded by the request's cleanup, never run.
 */
final class DatabaseStateTest extends CIUnitTestCase
{
    public function testCoreDatabaseDeclaresNoStaticState(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/modules/Core/Database/*.php') ?: [];
        $this->assertGreaterThanOrEqual(10, count($files));
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

    public function testTheExecutorAndUnitsAreNeverPersistent(): void
    {
        $persistent = (new WorkerMode())->persistentServices;
        foreach (['sqlExecutor', 'unitsOfWork', 'apothekeDb', 'requestCleanup'] as $service) {
            $this->assertNotContains($service, $persistent);
        }
    }

    public function testTheRegistryIsEmptyAfterAFailedUnitAndCleanupDiscardsAnOpenOne(): void
    {
        [$database, $db] = UowSchema::create('sqlite');
        try {
            $cleanup = new RequestCleanup();
            $units = UnitsOfWork::on(new ConnectionExecutor($db), $cleanup);
            try {
                $units->run('uw_parent', static function (UnitOfWork $uow): void {
                    $uow->insert('uw_parent', ['name' => 'x'], 'x');
                    throw new RuntimeException('stop');
                });
            } catch (RuntimeException) {
            }
            $this->assertFalse($units->isOpen());
            $cleanup->run();
            $this->assertFalse($units->discard(), 'nothing was left to discard');

            // A fatal error skips run()'s finally: the request's cleanup is what discards the unit.
            try {
                $units->run('uw_parent', function (UnitOfWork $uow) use ($units, $cleanup): void {
                    $uow->insert('uw_parent', ['name' => 'never'], 'never');
                    $cleanup->run();
                    $this->assertFalse($units->isOpen(), 'the cleanup discarded the open unit');
                });
                $this->fail('a discarded unit must not run');
            } catch (\Apotheke\Core\Exceptions\WriteFailed $e) {
                $this->assertSame('Database write failed: the unit of work was discarded', $e->getMessage());
            }
            $this->assertSame([], $db->query('SELECT * FROM uw_parent')->getResultArray(), 'a discarded unit is not run');
        } finally {
            $database->drop();
        }
    }

    /** Two requests in one worker process: the second gets its own executor and an empty registry. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASecondWorkerRequestStartsWithFreshUnits(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            Services::injectMock('apothekeDb', $database->db);
            $first = Services::unitsOfWork();
            $executor = Services::sqlExecutor();
            $this->assertSame($first, Services::unitsOfWork(), 'one registry per request');
            $first->run('items', function (UnitOfWork $uow) use ($first): void {
                $this->assertTrue($first->isOpen());
                Services::resetForWorkerMode(new WorkerMode());
                Services::injectMock('apothekeDb', $this->databaseOf($first));
                $second = Services::unitsOfWork();
                $this->assertNotSame($first, $second);
                $this->assertFalse($second->isOpen(), 'the next request sees no unit of the first');
            });
            $this->assertNotSame($executor, Services::sqlExecutor());
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    /** @return \CodeIgniter\Database\BaseConnection<object|resource, object|resource> */
    private function databaseOf(UnitsOfWork $units): \CodeIgniter\Database\BaseConnection
    {
        $db = $units->executor()->connection();
        $this->assertNotNull($db);

        return $db;
    }
}
