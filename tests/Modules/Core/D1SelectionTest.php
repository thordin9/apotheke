<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DsnException;
use Apotheke\Core\Config\DsnTranslator;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/** P4-5: db.driver d1 is storage mode only (R2 files), and its host is fixed. */
final class D1SelectionTest extends CIUnitTestCase
{
    /** @return array<string,array{callable(array<string,string>):array<string,mixed>}> */
    public static function loaders(): array
    {
        require_once dirname(__DIR__, 3) . '/modules/Core/Config/config-functions.php';

        return [
            'ApothekeConfig' => [static fn (array $env): array => ApothekeConfig::load('/app', null, $env)->values()],
            'config_load' => [static fn (array $env): array => config_load('/app', null, $env)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('loaders')]
    public function testD1NeedsR2Storage(callable $load): void
    {
        foreach ([['DB_DRIVER' => 'd1'], ['DB_DRIVER' => 'D1', 'STORAGE_BACKEND' => 'local']] as $env) {
            try {
                $load($env);
                $this->fail('d1 without R2 storage must be refused: ' . json_encode($env));
            } catch (RuntimeException $e) {
                $this->assertSame('db.driver d1 needs STORAGE_BACKEND=r2.', $e->getMessage());
            }
        }
        $cfg = $load(['DB_DRIVER' => 'D1', 'STORAGE_BACKEND' => 'R2']);
        $this->assertSame('d1', $cfg['db']['driver']);
        $this->assertSame('r2', $cfg['storage']['backend']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('loaders')]
    public function testR2StorageNeedsD1(callable $load): void
    {
        foreach ([['STORAGE_BACKEND' => 'r2'], ['STORAGE_BACKEND' => 'R2', 'DB_DRIVER' => 'sqlite'], ['STORAGE_BACKEND' => 'r2', 'DB_DRIVER' => 'pgsql']] as $env) {
            try {
                $load($env);
                $this->fail('R2 storage without d1 must be refused: ' . json_encode($env));
            } catch (RuntimeException $e) {
                $this->assertSame('STORAGE_BACKEND=r2 needs DB_DRIVER=d1.', $e->getMessage());
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('loaders')]
    public function testDefaultsStayLocal(callable $load): void
    {
        $cfg = $load([]);
        $this->assertSame('sqlite', $cfg['db']['driver']);
        $this->assertSame(['backend' => 'local', 'max_write_bytes' => 104857600, 'r2_base_url' => 'http://r2.internal'], $cfg['storage']);
        $this->assertSame('r2', $load(['STORAGE_BACKEND' => 'r2', 'DB_DRIVER' => 'd1'])['storage']['backend'], 'the CI4 config reads STORAGE_BACKEND');
        $this->assertSame(5, $load(['R2_MAX_WRITE_BYTES' => '5'])['storage']['max_write_bytes']);
    }

    public function testD1TranslatesToTheFixedBridge(): void
    {
        $t = new DsnTranslator();
        $group = $t->translate(['driver' => 'd1', 'dsn' => 'd1:http://elsewhere.example', 'host' => 'elsewhere.example']);
        $this->assertSame(['DSN' => '', 'DBDriver' => 'Apotheke\\Core\\Database\\D1', 'hostname' => 'http://d1.internal', 'database' => '', 'DBDebug' => false], $group);
        $this->assertSame('Database d1', DsnTranslator::logLine('d1', $t->pdoDsn(['driver' => 'd1'])));
        $this->assertSame(DsnTranslator::D1_DSN, config_dsn(['driver' => 'd1']));
        $this->assertSame('d1.internal', config_migrate_where(['driver' => 'd1']));
    }

    public function testAD1DsnNeverSelectsTheBridgeForAnotherDriver(): void
    {
        $this->expectException(DsnException::class);
        (new DsnTranslator())->translate(['driver' => 'pgsql', 'dsn' => DsnTranslator::D1_DSN]);
    }

    public function testStorageModeServesTheBatchOnlyBridgeExecutor(): void
    {
        $config = ApothekeConfig::load('/app', null, ['DB_DRIVER' => 'd1', 'STORAGE_BACKEND' => 'r2']);
        \Config\Services::injectMock('apothekeConfig', $config);
        \Config\Services::resetSingle('apothekeDb');
        // Mark the object migrated for this process, so the test needs no bridge.
        $key = (new \ReflectionMethod(\Apotheke\Migrations\BootMigration::class, 'key'))->invoke(null, $config->database());
        $done = new \ReflectionProperty(\Apotheke\Migrations\BootMigration::class, 'done');
        $done->setValue(null, [$key => true]);
        try {
            $executor = \Config\Services::sqlExecutor(false);
            $this->assertInstanceOf(\Apotheke\Core\Database\D1HttpExecutor::class, $executor);
            $this->assertNull($executor->connection(), 'batch-only: units of work take the batch path');
            $group = config(\Config\Database::class)->default;
            $this->assertSame(['Apotheke\\Core\\Database\\D1', 'http://d1.internal'], [$group['DBDriver'], $group['hostname']], 'the CI4 group is the bridge driver');
        } finally {
            \Apotheke\Migrations\BootMigration::forget();
            \Config\Services::reset(true);
            \CodeIgniter\Config\Factories::reset('config');
        }
    }
}
