<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DatabaseFactory;
use Apotheke\Core\Config\DsnTranslator;
use Apotheke\Migrations\BootMigration;
use Apotheke\Migrations\PdoMigrationRunner;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Services::apothekeDb migrates once per process and database, not per request,
 * and checks the schema on the CI4 connection: the runner (its own connection)
 * only runs when a migration is missing (QA #134 L-472).
 */
final class BootMigrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $dirs = [];

    /** @var list<BaseConnection<object|resource, object|resource>> */
    private array $connections = [];

    protected function tearDown(): void
    {
        BootMigration::forget();
        foreach ($this->connections as $db) {
            $db->close();
        }
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
        $this->assertTrue(BootMigration::ensure($a, $this->connect($a)));
        $this->assertTrue(BootMigration::done($a));
        $this->assertFileExists($a->dataDir() . '/catalog.sqlite');
        $this->assertFalse(BootMigration::ensure($a, $this->connect($a)), 'second request: no migration check');
        $this->assertFalse(BootMigration::done($b));
        $this->assertTrue(BootMigration::ensure($b, $this->connect($b)), 'another database is migrated on its own');
        BootMigration::forget();
        $this->assertFalse(BootMigration::done($a));
        $this->assertFalse(BootMigration::ensure($a, $this->connect($a)), 'checked again, already current');
        $this->assertTrue(BootMigration::done($a));
    }

    /** One DSN serving two schemas or two users: each is migrated on its own (QA #64 L-1). */
    public function testSchemaAndUserArePartOfTheKey(): void
    {
        $dir = $this->config()->dataDir();
        $base = ['DATA_DIR' => $dir, 'DB_DRIVER' => 'sqlite', 'SEED_DEFAULTS' => '0'];
        $a = ApothekeConfig::load(ApothekeConfig::repoRoot(), null, $base + ['DB_SCHEMA' => 'shop_a', 'DB_USER' => 'u1']);
        $schema = ApothekeConfig::load(ApothekeConfig::repoRoot(), null, $base + ['DB_SCHEMA' => 'shop_b', 'DB_USER' => 'u1']);
        $user = ApothekeConfig::load(ApothekeConfig::repoRoot(), null, $base + ['DB_SCHEMA' => 'shop_a', 'DB_USER' => 'u2']);
        $this->assertSame($a->database()['dsn'] ?? null, $schema->database()['dsn'] ?? null, 'same DSN');
        $this->assertTrue(BootMigration::ensure($a, $this->connect($a)));
        $this->assertFalse(BootMigration::done($schema), 'another schema is not marked migrated');
        BootMigration::ensure($schema, $this->connect($schema));
        $this->assertTrue(BootMigration::done($schema), 'another schema is checked on its own');
        $this->assertFalse(BootMigration::done($user), 'another user is not marked migrated');
        BootMigration::ensure($user, $this->connect($user));
        $this->assertTrue(BootMigration::done($user), 'another user is checked on its own');
        $this->assertFalse(BootMigration::ensure($a, $this->connect($a)), 'the first one is still remembered');
    }

    public function testAFailedMigrationIsRetried(): void
    {
        $config = $this->config();
        $db = $this->connect($config);
        try {
            BootMigration::ensure($config, $db, static function (): PdoMigrationRunner {
                throw new \RuntimeException('the runner cannot connect');
            });
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('the runner cannot connect', $e->getMessage());
            $this->assertFalse(BootMigration::done($config), 'not remembered after a failure');
        }
        $this->assertTrue(BootMigration::ensure($config, $db), 'retried on the next request');
    }

    /** A current schema is checked on the CI4 connection alone: the runner is not even built. */
    public function testACurrentSchemaNeedsNoSecondConnection(): void
    {
        $config = $this->config();
        $this->assertTrue(BootMigration::ensure($config, $this->connect($config)));
        BootMigration::forget();
        $built = 0;
        $runner = static function () use (&$built): PdoMigrationRunner {
            $built++;

            return new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations');
        };
        $this->assertFalse(BootMigration::ensure($config, $this->connect($config), $runner));
        $this->assertSame(0, $built, 'no runner, so no PDO connection');
        $this->assertTrue(BootMigration::done($config));
    }

    /** A fresh database, or a missing migration row (a deploy that adds a step), builds the runner. */
    public function testAMissingMigrationRunsTheRunner(): void
    {
        $config = $this->config();
        $db = $this->connect($config);
        $built = 0;
        $runner = static function () use (&$built): PdoMigrationRunner {
            $built++;

            return new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations');
        };
        $this->assertTrue(BootMigration::ensure($config, $db, $runner), 'fresh database');
        $this->assertSame(1, $built);

        $last = $db->query('SELECT MAX(version) AS v FROM schema_migrations')->getRowArray()['v'];
        $db->query('DELETE FROM schema_migrations WHERE version = ?', [$last]);
        BootMigration::forget();
        try {
            BootMigration::ensure($config, $db, static function (): PdoMigrationRunner {
                throw new \RuntimeException('runner built');
            });
            $this->fail('the runner was not built for a missing row');
        } catch (\RuntimeException $e) {
            $this->assertSame('runner built', $e->getMessage());
        }
        $this->assertFalse(BootMigration::done($config));
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function connect(ApothekeConfig $config): BaseConnection
    {
        $factory = new DatabaseFactory($config, new DsnTranslator());
        $db = Database::connect($factory->group(), false);
        $factory->apply($db);
        $this->connections[] = $db;

        return $db;
    }

    private function config(): ApothekeConfig
    {
        $dir = sys_get_temp_dir() . '/apotheke-boot-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->dirs[] = $dir;

        return ApothekeConfig::load(ApothekeConfig::repoRoot(), null, ['DATA_DIR' => $dir, 'DB_DRIVER' => 'sqlite', 'SEED_DEFAULTS' => '0']);
    }
}
