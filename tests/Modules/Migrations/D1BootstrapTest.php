<?php

declare(strict_types=1);

namespace Tests\Migrations;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Migrations\BootMigration;
use Apotheke\Migrations\D1Bootstrap;
use Apotheke\Migrations\D1MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

require_once __DIR__ . '/D1SqliteStandIn.php';

/** P4-5: the hosted schema bootstrap on the Installation object, under its lease. */
final class D1BootstrapTest extends CIUnitTestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        BootMigration::forget();
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private static function migrations(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    /** A migrations dir with one step that runs $step against the D1Pdo adapter. */
    private function oneStep(string $body): string
    {
        $this->dir = sys_get_temp_dir() . '/coder2-d1boot-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/0001_step.php', "<?php\nreturn static function (\$pdo, string \$driver): void {\n$body\n};\n");

        return $this->dir;
    }

    private static function lease(D1SqliteStandIn $d1): array
    {
        return $d1->query('SELECT owner, expires FROM apotheke_migration_lease', []);
    }

    public function testEveryMigrationRunsUnderTheLeaseWhichIsReleased(): void
    {
        $d1 = new D1SqliteStandIn();
        $runner = new D1MigrationRunner(self::migrations());
        $applied = (new D1Bootstrap($d1, $runner))->migrate();
        $this->assertSame(array_keys($runner->available()), $applied);
        $this->assertSame([], self::lease($d1), 'no lease row outside a run');
        $this->assertSame([], (new D1Bootstrap($d1, $runner))->migrate(), 'a second run finds the schema current');
    }

    public function testALiveLeaseRefusesAndTouchesNothing(): void
    {
        foreach ([1000, 1001] as $expires) {
            $d1 = new D1SqliteStandIn();
            $d1->exec('CREATE TABLE apotheke_migration_lease (id INTEGER PRIMARY KEY CHECK (id = 1), owner TEXT NOT NULL, expires INTEGER NOT NULL)');
            $d1->exec("INSERT INTO apotheke_migration_lease VALUES (1, 'other', $expires)");
            try {
                (new D1Bootstrap($d1, new D1MigrationRunner(self::migrations())))->migrate(null, 1000);
                $this->fail('a live lease must refuse');
            } catch (RuntimeException $e) {
                $this->assertSame(D1Bootstrap::BUSY, $e->getMessage());
            }
            $this->assertSame([['owner' => 'other', 'expires' => $expires]], self::lease($d1), 'the other run keeps its lease');
            $this->assertSame([], $d1->query("SELECT name FROM sqlite_master WHERE name = 'schema_migrations'", []));
        }
    }

    public function testAnExpiredLeaseIsTakenOver(): void
    {
        $d1 = new D1SqliteStandIn();
        $d1->exec('CREATE TABLE apotheke_migration_lease (id INTEGER PRIMARY KEY CHECK (id = 1), owner TEXT NOT NULL, expires INTEGER NOT NULL)');
        $d1->exec("INSERT INTO apotheke_migration_lease VALUES (1, 'crashed', 999)");
        $this->assertSame(['0001'], (new D1Bootstrap($d1, new D1MigrationRunner($this->oneStep('$pdo->exec("CREATE TABLE t (x INTEGER)");'))))->migrate(null, 1000));
        $this->assertSame([], self::lease($d1));
    }

    public function testTheLeaseLastsItsTermAndAFailedRunReleasesIt(): void
    {
        $d1 = new D1SqliteStandIn();
        $seen = [];
        $dir = $this->oneStep('throw new \RuntimeException("step failed");');
        $d1->reject = static function (string $sql) use ($d1, &$seen): bool {
            if (str_starts_with($sql, 'INSERT INTO schema_migrations')) {
                $seen = $d1->query('SELECT expires FROM apotheke_migration_lease', []);
            }

            return false;
        };
        try {
            (new D1Bootstrap($d1, new D1MigrationRunner($dir)))->migrate(null, 1000);
            $this->fail('the failing step must surface');
        } catch (RuntimeException $e) {
            $this->assertSame('step failed', $e->getMessage());
        }
        $this->assertSame([], self::lease($d1), 'a failed run releases its lease, so the next request retries');
        // the term: claimed at 1000, live until 1000 + LEASE_SECONDS
        $d1->exec('CREATE TABLE IF NOT EXISTS apotheke_migration_lease (id INTEGER PRIMARY KEY CHECK (id = 1), owner TEXT NOT NULL, expires INTEGER NOT NULL)');
        $probe = new D1MigrationRunner($this->oneStep('$pdo->exec("CREATE TABLE t2 (x INTEGER)");'));
        $d1->reject = static function (string $sql) use ($d1, &$seen): bool {
            if (str_starts_with($sql, 'CREATE TABLE t2')) {
                $seen = $d1->query('SELECT expires FROM apotheke_migration_lease', []);
            }

            return false;
        };
        (new D1Bootstrap($d1, $probe))->migrate(null, 1000);
        $this->assertSame([['expires' => 1000 + D1Bootstrap::LEASE_SECONDS]], $seen);
        $this->assertSame(300, D1Bootstrap::LEASE_SECONDS);
    }

    public function testARunOnlyReleasesItsOwnLease(): void
    {
        $d1 = new D1SqliteStandIn();
        // While this run migrates, its lease is (wrongly) taken over; the release must leave the new owner's row.
        $d1->reject = static function (string $sql) use ($d1): bool {
            if (str_starts_with($sql, 'CREATE TABLE t3')) {
                $d1->run([['sql' => "UPDATE apotheke_migration_lease SET owner = 'other'", 'params' => []]]);
            }

            return false;
        };
        (new D1Bootstrap($d1, new D1MigrationRunner($this->oneStep('$pdo->exec("CREATE TABLE t3 (x INTEGER)");'))))->migrate(null, 1000);
        $this->assertSame('other', self::lease($d1)[0]['owner'] ?? null);
    }


    /** M-1846: a later write in a callable must not leave earlier writes or a lease behind. */
    public function testAFailedMultiCallCallableLeavesNoSchemaAndFreesTheLease(): void
    {
        $d1 = new D1SqliteStandIn();
        $dir = $this->oneStep('$pdo->exec("CREATE TABLE t (x INTEGER)"); $pdo->exec("CREATE TABLE u (x INTEGER)");');
        $d1->reject = static fn (string $sql): bool => str_contains($sql, 'CREATE TABLE u');
        try {
            (new D1Bootstrap($d1, new D1MigrationRunner($dir)))->migrate();
            $this->fail('the failing step must surface');
        } catch (\PDOException $e) {
            $this->assertSame('Database write failed: the batch', $e->getMessage());
        }
        $this->assertSame([], $d1->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('t', 'u')", []));
        $this->assertSame([], $d1->query('SELECT version FROM schema_migrations', []));
        $this->assertSame([], self::lease($d1), 'a failed run releases its lease, so the next request retries');
    }

    public function testRerunningAFailedCallableAppliesItOnce(): void
    {
        $d1 = new D1SqliteStandIn();
        $dir = $this->oneStep('$pdo->exec("CREATE TABLE t (x INTEGER)"); $pdo->exec("CREATE TABLE u (x INTEGER)");');
        $d1->reject = static fn (string $sql): bool => str_contains($sql, 'CREATE TABLE u');
        try {
            (new D1Bootstrap($d1, new D1MigrationRunner($dir)))->migrate();
            $this->fail('the failing step must surface');
        } catch (\PDOException) {
        }
        $d1->reject = null;
        $this->assertSame(['0001'], (new D1Bootstrap($d1, new D1MigrationRunner($dir)))->migrate());
        $this->assertSame(
            ['t', 'u'],
            array_column($d1->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('t', 'u') ORDER BY name", []), 'name'),
        );
        $this->assertSame(['0001'], array_keys((new D1MigrationRunner($dir))->applied($d1)));
        $this->assertSame([], (new D1Bootstrap($d1, new D1MigrationRunner($dir)))->migrate(), 'a second run finds the schema current');
        $this->assertCount(1, $d1->query('SELECT version FROM schema_migrations', []));
        $this->assertSame([], self::lease($d1));
    }

    /** Mutant: schema_migrations written outside the batch would leave the tables if the record write fails. */
    public function testAFailedRecordWriteRollsBackTheCallable(): void
    {
        $d1 = new D1SqliteStandIn();
        $dir = $this->oneStep('$pdo->exec("CREATE TABLE t (x INTEGER)"); $pdo->exec("CREATE TABLE u (x INTEGER)");');
        $d1->reject = static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO schema_migrations');
        try {
            (new D1Bootstrap($d1, new D1MigrationRunner($dir)))->migrate();
            $this->fail('the failed record must surface');
        } catch (\PDOException $e) {
            $this->assertSame('Database write failed: the batch', $e->getMessage());
        }
        $this->assertSame([], $d1->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('t', 'u')", []));
        $this->assertSame([], $d1->query('SELECT version FROM schema_migrations', []));
        $this->assertSame([], self::lease($d1));
        $together = false;
        foreach ($d1->batches as $batch) {
            $writes = $record = false;
            foreach ($batch as $sql) {
                $writes = $writes || str_contains($sql, 'CREATE TABLE t') || str_contains($sql, 'CREATE TABLE u');
                $record = $record || str_starts_with($sql, 'INSERT INTO schema_migrations');
            }
            $together = $together || ($writes && $record);
        }
        $this->assertTrue($together, 'the callable writes and the schema_migrations row must be one batch');
    }

    public function testAnArrayMigrationIsUnchanged(): void
    {
        $this->dir = sys_get_temp_dir() . '/coder2-d1boot-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/0001_step.php', "<?php\nreturn ['sqlite' => 'CREATE TABLE t (x INTEGER); CREATE TABLE u (x INTEGER);'];\n");
        $d1 = new D1SqliteStandIn();
        $this->assertSame(['0001'], (new D1Bootstrap($d1, new D1MigrationRunner($this->dir)))->migrate());
        $this->assertSame(
            ['t', 'u'],
            array_column($d1->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('t', 'u') ORDER BY name", []), 'name'),
        );
        $this->assertSame(['0001'], array_keys((new D1MigrationRunner($this->dir))->applied($d1)));
        $this->assertSame([], self::lease($d1));
    }

    public function testBootMigrationUsesTheBootstrapForD1AndNeverPdo(): void
    {
        $config = ApothekeConfig::load('/app', null, ['DB_DRIVER' => 'd1', 'STORAGE_BACKEND' => 'r2']);
        $notCurrent = new class () {
            public function query(string $sql): bool
            {
                return false;
            }
        };
        $d1 = new D1SqliteStandIn();
        $calls = 0;
        $boot = static function () use ($d1, &$calls): D1Bootstrap {
            $calls++;

            return new D1Bootstrap($d1, new D1MigrationRunner(dirname(__DIR__, 3) . '/migrations'));
        };
        $pdo = function (): never {
            $this->fail('d1 must not open a PDO runner');
        };
        $this->assertTrue(BootMigration::ensure($config, $notCurrent, $pdo, $boot));
        $this->assertSame(1, $calls);
        $this->assertNotSame([], $d1->query('SELECT version FROM schema_migrations', []));
        $this->assertTrue(BootMigration::done($config));
        $this->assertFalse(BootMigration::ensure($config, $notCurrent, $pdo, $boot), 'once per process');
        $this->assertSame(1, $calls);
    }

    public function testThePdoRunnerRefusesD1(): void
    {
        $this->expectException(\Apotheke\Migrations\MigrateConnectException::class);
        $this->expectExceptionMessage('db.driver d1 migrates over the bridge (D1Bootstrap), not PDO.');
        (new \Apotheke\Migrations\PdoMigrationRunner(self::migrations()))->connect(['driver' => 'd1']);
    }

    /** Concurrent first requests against the real Installation handler: one run migrates, nothing twice. */
    public function testConcurrentBootstrapsOnTheRealObjectMigrateOnce(): void
    {
        $root = dirname(__DIR__, 3);
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        $ok = $node !== '' && trim((string)shell_exec(escapeshellarg($node) . " -e \"try{require('node:sqlite');console.log('y')}catch{}\" 2>/dev/null")) === 'y';
        if (!$ok || !is_dir($root . '/edge/node_modules')) {
            $this->markTestSkipped('needs node with node:sqlite and edge/node_modules');
        }
        $server = proc_open([$node, '--experimental-strip-types', '--no-warnings', $root . '/edge/tests/do-bridge-server.mjs'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $sp, $root);
        try {
            $url = 'http://127.0.0.1:' . (int)trim((string)fgets($sp[1]));
            $racers = [];
            for ($i = 0; $i < 6; $i++) {
                $p = proc_open([PHP_BINARY, __DIR__ . '/_d1-bootstrap-race-client.php', $url], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                $this->assertSame("ready\n", fgets($pipes[1]));
                $racers[] = [$p, $pipes];
            }
            foreach ($racers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
            }
            $out = [];
            foreach ($racers as [$p, $pipes]) {
                $out[] = json_decode((string)fgets($pipes[1]), true);
                proc_close($p);
            }
            $available = count((new D1MigrationRunner(self::migrations()))->available());
            $winners = array_filter($out, static fn (?array $r): bool => ($r['applied'] ?? 0) > 0);
            $this->assertCount(1, $winners, json_encode($out));
            $this->assertSame($available, array_values($winners)[0]['applied']);
            foreach ($out as $r) {
                $this->assertTrue(($r['applied'] ?? null) !== null || ($r['error'] ?? null) === D1Bootstrap::BUSY, json_encode($r));
            }
            $d1 = new D1HttpExecutor($url);
            $this->assertSame([['n' => $available, 'd' => $available]], $d1->query('SELECT COUNT(*) AS n, COUNT(DISTINCT version) AS d FROM schema_migrations', []));
            $this->assertSame([], $d1->query('SELECT owner FROM apotheke_migration_lease', []));
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }
}
