<?php

declare(strict_types=1);

namespace Tests\Migrations;

use Apotheke\Core\Database\D1Executor;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Migrations\D1MigrationRunner;
use Apotheke\Migrations\FreshInstallRoles;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/D1SqliteStandIn.php';

/**
 * A first install on D1 sends each migration as its own batch, so it can stop part way. The next boot finishes it
 * as the same fresh install: the built-in roles get the new defaults, whichever batch it stopped before.
 */
final class D1FreshInstallRolesTest extends CIUnitTestCase
{
    /** @return array<string,array{string}> */
    public static function stops(): array
    {
        return [
            'before the roles table' => ['0015'],
            'the roles table' => ['0022'],
            'after the roles table' => ['0023'],
            'a late migration' => ['0033'],
            'the role defaults' => ['roles'],
        ];
    }

    #[DataProvider('stops')]
    public function testAnInterruptedFirstInstallFinishesWithTheNewDefaults(string $stop): void
    {
        $standIn = new D1SqliteStandIn();
        $stopping = self::stoppingAt($standIn, $stop);
        try {
            (new D1MigrationRunner(self::migrations()))->migrate($stopping);
            $this->fail('the first install stops');
        } catch (\PDOException) {
        }
        $this->assertTrue($stopping->stopped, 'the install stopped at ' . $stop);

        $runner = new D1MigrationRunner(self::migrations());
        $runner->migrate($standIn);

        $this->assertSame(array_keys($runner->available()), array_keys($runner->applied($standIn)));
        foreach (FreshInstallRoles::PERMS as $key => $perms) {
            $this->assertSame((string)json_encode($perms), self::perms($standIn, $key), $key . ' after a stop at ' . $stop);
        }
        $this->assertSame((string)json_encode(\ROLE_DEFAULTS['admin'][1]), self::perms($standIn, 'admin'));
        $this->assertSame((string)json_encode(\ROLE_DEFAULTS['guest'][1]), self::perms($standIn, 'guest'));
        $this->assertSame(self::tables(self::installed()), self::tables($standIn), 'the same tables as an install that did not stop');
    }

    public function testAnInstallThatGetsTheRolesTableLaterKeepsTheOldDefaults(): void
    {
        $standIn = new D1SqliteStandIn();
        $dir = sys_get_temp_dir() . '/apotheke-d1mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach ((new D1MigrationRunner(self::migrations()))->available() as $ver => $file) {
                if ($ver < FreshInstallRoles::ROLES_MIGRATION) {
                    copy($file, $dir . '/' . basename($file));
                }
            }
            (new D1MigrationRunner($dir))->migrate($standIn);
        } finally {
            foreach ((array)glob($dir . '/*.php') as $f) {
                unlink((string)$f);
            }
            rmdir($dir);
        }
        (new D1MigrationRunner(self::migrations()))->migrate($standIn);

        foreach (['editor', 'viewer', 'admin', 'guest'] as $key) {
            $this->assertSame((string)json_encode(\ROLE_DEFAULTS[$key][1]), self::perms($standIn, $key), $key . ' keeps 2.6.x\'s defaults');
        }
        $this->assertSame(self::tables(self::installed()), self::tables($standIn));
    }

    public function testTheRoleDefaultsGoInTheBatchThatCreatesTheRoles(): void
    {
        $standIn = new D1SqliteStandIn();
        (new D1MigrationRunner(self::migrations()))->migrate($standIn);

        $with = array_values(array_filter($standIn->batches, static fn (array $sql): bool => in_array(
            'UPDATE roles SET perms = ? WHERE role_key = ? AND builtin = 1',
            $sql,
            true,
        )));
        $this->assertCount(1, $with, 'one batch sends the role defaults');
        $this->assertContains('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?,?,?)', $with[0], 'with the roles migration\'s row');
        $this->assertNotEmpty(array_filter($with[0], static fn (string $sql): bool => str_starts_with(ltrim($sql), 'CREATE TABLE roles')), 'and its CREATE TABLE');
    }

    private static function migrations(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    private static function installed(): D1SqliteStandIn
    {
        $standIn = new D1SqliteStandIn();
        (new D1MigrationRunner(self::migrations()))->migrate($standIn);

        return $standIn;
    }

    /** @return list<string> */
    private static function tables(D1SqliteStandIn $d1): array
    {
        return array_column($d1->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"), 'name');
    }

    private static function perms(D1SqliteStandIn $d1, string $key): string
    {
        return (string)($d1->query('SELECT perms FROM roles WHERE role_key = ?', [$key])[0]['perms'] ?? '');
    }

    /** Sends everything until the batch that records version $stop (or carries the role defaults), then stops. */
    private static function stoppingAt(D1SqliteStandIn $inner, string $stop): object
    {
        return new class ($inner, $stop) implements D1Executor {
            public bool $stopped = false;

            public function __construct(private readonly D1SqliteStandIn $inner, private readonly string $stop)
            {
            }

            public function query(string $sql, array $params = []): array
            {
                return $this->inner->query($sql, $params);
            }

            public function run(array $statements): array
            {
                foreach ($statements as $s) {
                    $hit = $this->stop === 'roles'
                        ? str_starts_with($s['sql'], 'UPDATE roles SET perms')
                        : str_starts_with($s['sql'], 'INSERT INTO schema_migrations') && ($s['params'][0] ?? null) === $this->stop;
                    if ($hit && !$this->stopped) {
                        $this->stopped = true;
                        throw new WriteFailed('Database write failed: the batch');
                    }
                }

                return $this->inner->run($statements);
            }

            public function exec(string $sql): array
            {
                return $this->inner->exec($sql);
            }
        };
    }
}
