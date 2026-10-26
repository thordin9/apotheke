<?php

declare(strict_types=1);

namespace Tests\Support;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DatabaseFactory;
use Apotheke\Core\Config\DsnTranslator;
use Apotheke\Migrations\PdoMigrationRunner;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PDO;

/**
 * A fresh, migrated Apotheke database for one test: a temp SQLite file, or a
 * throwaway PostgreSQL schema. Connected through CI4 like Services::apothekeDb.
 */
final class TestDatabase
{
    /** @var BaseConnection<object|resource, object|resource> */
    public BaseConnection $db;

    public ApothekeConfig $config;

    private function __construct(public readonly string $driver, private readonly string $dir, private readonly string $schema)
    {
    }

    /** @return list<string> the drivers this run covers */
    public static function drivers(): array
    {
        return getenv('APOTHEKE_CI4_TEST_PGSQL') === '1' || (getenv('TEST_PG_HOST') !== false && getenv('TEST_PG_HOST') !== '')
            ? ['sqlite', 'pgsql'] : ['sqlite'];
    }

    public static function create(string $driver, bool $migrate = true): self
    {
        $dir = sys_get_temp_dir() . '/apotheke-ci4-db-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $schema = $driver === 'pgsql' ? 'ci4t_' . bin2hex(random_bytes(5)) : '';
        $env = ['DATA_DIR' => $dir, 'DB_DRIVER' => $driver, 'SEED_DEFAULTS' => '0'];
        if ($driver === 'pgsql') {
            $env += ['DB_HOST' => getenv('TEST_PG_HOST') ?: '127.0.0.1', 'DB_PORT' => getenv('TEST_PG_PORT') ?: '5432',
                'DB_NAME' => getenv('TEST_PG_DB') ?: 'apotheke', 'DB_USER' => getenv('TEST_PG_USER') ?: 'apotheke',
                'DB_PASSWORD' => getenv('TEST_PG_PASSWORD') !== false ? (string)getenv('TEST_PG_PASSWORD') : 'apotheke', 'DB_SCHEMA' => $schema];
        }
        $self = new self($driver, $dir, $schema);
        $self->config = ApothekeConfig::load(ApothekeConfig::repoRoot(), null, $env);
        if ($migrate) {
            $runner = new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations');
            $pdo = $runner->connect($self->config->database());
            $runner->migrate($pdo, $driver, ['data_dir' => $dir, 'seed' => false]);
            $runner->close($pdo);
        }
        $factory = new DatabaseFactory($self->config, new DsnTranslator());
        $group = array_merge(config(Database::class)->default, $factory->group(), ['schema' => '', 'DBPrefix' => '']);
        /** @var BaseConnection<object|resource, object|resource> $db */
        $db = Database::connect($group, false);
        $factory->apply($db);
        $self->db = $db;

        return $self;
    }

    /**
     * A second CI4 connection to the same database: another client, for lock tests.
     *
     * @return BaseConnection<object|resource, object|resource>
     */
    public function connectAgain(): BaseConnection
    {
        $factory = new DatabaseFactory($this->config, new DsnTranslator());
        $group = array_merge(config(Database::class)->default, $factory->group(), ['schema' => '', 'DBPrefix' => '']);
        // pg_connect() hands back the open link for an identical connection string: this one differs.
        if ($this->driver === 'pgsql') {
            $dsn = (string)($group['DSN'] ?? '');
            if ($dsn !== '') {
                $group['DSN'] = $dsn . ' application_name=apotheke_second';
            } else {
                $group['connect_timeout'] = '17';
            }
        }
        /** @var BaseConnection<object|resource, object|resource> $db */
        $db = Database::connect($group, false);
        $factory->apply($db);

        return $db;
    }

    /** A raw PDO on the same database, for test fixtures. */
    public function pdo(): PDO
    {
        $runner = new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations');

        return $runner->connect($this->config->database());
    }

    public function dataDir(): string
    {
        return $this->dir;
    }

    public function drop(): void
    {
        $this->db->close();
        if ($this->driver === 'pgsql') {
            $pdo = $this->pdo();
            $pdo->exec('DROP SCHEMA IF EXISTS "' . $this->schema . '" CASCADE');
        }
        self::rrmdir($this->dir);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
        }
        @rmdir($dir);
    }
}
