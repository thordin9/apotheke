<?php

declare(strict_types=1);

namespace Tests\Support;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Items\PhotoRepository;
use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DatabaseFactory;
use Apotheke\Core\Config\DsnTranslator;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Media\ImageStore;
use Apotheke\Media\Storage\ImagickDecoder;
use Apotheke\Media\Storage\OriginalStore;
use Apotheke\Media\Storage\ServedImageWriter;
use Apotheke\Migrations\PdoMigrationRunner;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use Apotheke\Undo\UndoTokens;
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

    /** @var array<string,string> the environment the config was loaded from */
    private array $env = [];

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
        $self->env = $env;
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

    /**
     * Another client in its own process, for lock tests: it runs $statements
     * ([sql, binds] pairs) in one transaction (BEGIN IMMEDIATE on SQLite),
     * keeps it open for $holdMs and then commits. Returns once the
     * statements ran; the returned closure waits for the commit and gives
     * the process output.
     *
     * @param list<array{0:string,1:list<int|string|null>}> $statements
     * @return \Closure():string
     */
    public function holdInAnotherClient(array $statements, int $holdMs): \Closure
    {
        $env = $this->env + ['PATH' => (string)getenv('PATH')];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/hold-transaction.php', (string)json_encode($statements), (string)$holdMs],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot start the other client');
        }
        $out = (string)fgets($pipes[1]);
        $wait = static function () use ($proc, $pipes, &$out): string {
            $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            return $out;
        };
        if ($out !== "held\n") {
            throw new \RuntimeException('the other client failed: ' . $wait());
        }

        return $wait;
    }

    /** The item repository (and its collaborators) on this database, with no actor in the history. */
    public function items(): ItemRepository
    {
        $db = $this->db;
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $settings = new SettingsService(new SettingsRepository($db), new SettingsSchema(), new LocalBrandStore($this->dir . '/brand'), $clock,
            $this->config->values(), static fn (string $m): null => null, new CommandLine());
        $images = new ImageStore($settings, new OriginalStore(new LocalStorage($this->config->originalsDir(), 0640)),
            new ServedImageWriter(new LocalStorage($this->dir)), new ImagickDecoder(), new RequestCleanup(static function (): void {
            }));
        $undo = new UndoTokens($db, $clock, 600, static function (string $f): void {
        }, static function (): void {
        });

        return new ItemRepository($db, $clock, $history, new TaxonomyRepository($db, $clock, $history), new TagRepository($db, $clock),
            $images, new LocalStorage($this->dir), new PhotoRepository($db, $clock, $history, $images, $undo));
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
