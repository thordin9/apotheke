<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Migrations\BootMigration;
use CodeIgniter\Test\CIUnitTestCase;

/** Services::apothekeDb migrates once per process and database, not per request. */
final class BootMigrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        BootMigration::forget();
        foreach ($this->dirs as $d) {
            array_map('unlink', glob($d . '/*') ?: []);
            @rmdir($d);
        }
        parent::tearDown();
    }

    public function testMigratesOncePerDatabase(): void
    {
        $a = $this->config();
        $b = $this->config();
        $this->assertFalse(BootMigration::done($a));
        $this->assertTrue(BootMigration::ensure($a));
        $this->assertTrue(BootMigration::done($a));
        $this->assertFileExists($a->dataDir() . '/catalog.sqlite');
        $this->assertFalse(BootMigration::ensure($a), 'second request: no migration check');
        $this->assertFalse(BootMigration::done($b));
        $this->assertTrue(BootMigration::ensure($b), 'another database is migrated on its own');
        BootMigration::forget();
        $this->assertFalse(BootMigration::done($a));
        $this->assertTrue(BootMigration::ensure($a));
    }

    public function testAFailedMigrationIsRetried(): void
    {
        $config = $this->config();
        $dir = $config->dataDir();
        rmdir($dir);
        file_put_contents($dir, 'not a directory');   // the database cannot be opened
        try {
            BootMigration::ensure($config);
            $this->fail('no exception');
        } catch (\Throwable) {
            $this->assertFalse(BootMigration::done($config), 'not remembered after a failure');
        }
        unlink($dir);
        mkdir($dir);
        $this->assertTrue(BootMigration::ensure($config), 'retried on the next request');
    }

    private function config(): ApothekeConfig
    {
        $dir = sys_get_temp_dir() . '/apotheke-boot-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->dirs[] = $dir;

        return ApothekeConfig::load(ApothekeConfig::repoRoot(), null, ['DATA_DIR' => $dir, 'DB_DRIVER' => 'sqlite', 'SEED_DEFAULTS' => '0']);
    }
}
