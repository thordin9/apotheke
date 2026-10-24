<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Migrations\BootMigration;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PDO;

/**
 * Services::apothekeDb across requests of one long-lived process: the
 * migration check runs once per database, and a failed first connect is not
 * remembered, so the next request still migrates.
 */
final class ApothekeDbServiceTest extends CIUnitTestCase
{
    private string $dir;

    private string|false $savedDataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedDataDir = getenv('DATA_DIR');
        $this->dir = sys_get_temp_dir() . '/apotheke-ci4-svc-' . bin2hex(random_bytes(4));
        BootMigration::forget();
    }

    protected function tearDown(): void
    {
        $this->useDataDir($this->savedDataDir === false ? '' : $this->savedDataDir);
        BootMigration::forget();
        if (is_file($this->dir)) {
            unlink($this->dir);
        }
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testAFailedFirstConnectStillMigratesOnTheNextRequest(): void
    {
        // Request 1: the database cannot be opened (DATA_DIR is a file).
        file_put_contents($this->dir, 'not a directory');
        $this->useDataDir($this->dir);
        try {
            $this->newRequest();
            Services::apothekeDb(false);
            $this->fail('the first connect should fail');
        } catch (\Throwable) {
            $this->assertFalse(BootMigration::done(Services::apothekeConfig()), 'a failed connect is not remembered');
        }

        // Request 2, same process: the database can be opened now and is migrated.
        unlink($this->dir);
        mkdir($this->dir);
        $this->newRequest();
        $this->assertIsObject(Services::apothekeDb(false));
        $this->assertTrue(BootMigration::done(Services::apothekeConfig()));
        $this->assertGreaterThan(0, $this->appliedMigrations());

        // Request 3: no second migration check for the same database.
        $this->newRequest();
        $this->assertFalse(BootMigration::ensure(Services::apothekeConfig()));
    }

    private function newRequest(): void
    {
        Services::resetSingle('apothekeConfig');
        Services::resetSingle('apothekeDb');
    }

    private function useDataDir(string $dir): void
    {
        putenv('DATA_DIR=' . $dir);
        $_SERVER['DATA_DIR'] = $dir;
        $_ENV['DATA_DIR'] = $dir;
        Services::resetSingle('apothekeConfig');
    }

    private function appliedMigrations(): int
    {
        $pdo = new PDO('sqlite:' . $this->dir . '/catalog.sqlite');

        return (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    }
}
