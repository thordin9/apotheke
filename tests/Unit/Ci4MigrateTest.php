<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * §3.4: the PDO runner matches legacy with src/ out of the include path,
 * from every stopped version, for a legacy SQLite adopt, with the PDO
 * closed before the next connection, and under a concurrent CI4 request.
 */
final class Ci4MigrateTest extends TestCase
{
    private const CLOCK = '2020-06-15 12:00:00';

    /** @var list<string> */
    private array $schemas = [];

    protected function tearDown(): void
    {
        if ($this->schemas === []) {
            return;
        }
        try {
            $admin = test_pg_admin();
            foreach ($this->schemas as $schema) {
                $admin->exec('DROP SCHEMA IF EXISTS ' . test_pg_ident($schema) . ' CASCADE');
            }
        } catch (Throwable) {
        }
    }

    public function testFreshMigrateMatchesLegacyWithoutLoadingSrc(): void
    {
        $left = tmpdir('mig-fresh-a');
        $right = tmpdir('mig-fresh-b');
        try {
            $legacy = $this->worker('run', 'legacy', $this->sqliteEnv($left, true));
            $ci4 = $this->worker('run', 'ci4', $this->sqliteEnv($right, true));
            $this->assertSame([], $ci4['src']);
            $this->assertNotSame([], $legacy['applied']);
            $this->assertSame($this->dumpSqlite($left), $this->dumpSqlite($right));
            $this->assertCount(26, $ci4['applied']);
            $this->assertCount(63, $this->dumpSqlite($right)['data']['settings'] ?? []);
        } finally {
            rrmdir($left);
            rrmdir($right);
        }
        if (!$this->postgres()) {
            return;
        }
        $schemaA = $this->schemaName('fa');
        $schemaB = $this->schemaName('fb');
        $dirA = tmpdir('mig-fresh-pg-a');
        $dirB = tmpdir('mig-fresh-pg-b');
        try {
            $legacy = $this->worker('run', 'legacy', $this->pgEnv($dirA, $schemaA, true));
            $ci4 = $this->worker('run', 'ci4', $this->pgEnv($dirB, $schemaB, true));
            $this->assertSame([], $ci4['src']);
            $this->assertSame($this->dumpPgsql($schemaA), $this->dumpPgsql($schemaB));
            $this->assertCount(26, $legacy['applied']);
            $this->assertCount(63, $this->dumpPgsql($schemaB)['data']['settings'] ?? []);
        } finally {
            rrmdir($dirA);
            rrmdir($dirB);
        }
    }

    public function testUpgradeFromEveryVersionMatchesLegacy(): void
    {
        $this->upgradeDriver('sqlite');
        if ($this->postgres()) {
            $this->upgradeDriver('pgsql');
        }
    }

    public function testLegacySqliteAdoptMatchesAndWritesABackup(): void
    {
        foreach ([1, 2, 3] as $version) {
            $left = tmpdir('mig-adopt-a' . $version);
            $right = tmpdir('mig-adopt-b' . $version);
            try {
                $this->writeLegacy($left, $version);
                $this->copySqlite($left, $right);
                $legacy = $this->worker('run', 'legacy', $this->sqliteEnv($left, false));
                $ci4 = $this->worker('run', 'ci4', $this->sqliteEnv($right, false));
                $this->assertSame([], $ci4['src'], 'v' . $version);
                $this->assertContains('legacy-v' . $version, $ci4['applied'], 'v' . $version);
                $this->assertSame($legacy['applied'], $ci4['applied'], 'v' . $version);
                $this->assertSame($this->dumpSqlite($left), $this->dumpSqlite($right), 'v' . $version);
                $this->assertCount(1, glob($right . '/catalog-v' . $version . '-backup-*.sqlite') ?: [], 'v' . $version);
                $kept = sqlite($right . '/catalog.sqlite');
                $this->assertSame(3, (int)$kept->query('PRAGMA user_version')->fetchColumn(), 'v' . $version);
            } finally {
                rrmdir($left);
                rrmdir($right);
            }
        }
    }

    public function testPdoIsClosedBeforeTheNextConnection(): void
    {
        $dir = tmpdir('mig-lock');
        try {
            $ci4 = $this->worker('lock', 'ci4', $this->sqliteEnv($dir, false));
            $this->assertSame([], $ci4['src']);
            $this->assertTrue($ci4['lock']['exclusive'] ?? false, 'EXCLUSIVE failed; the PDO handle is still open');
            $this->assertSame(0, (int)($ci4['lock']['checkpoint'] ?? -1));
        } finally {
            rrmdir($dir);
        }
    }

    public function testConcurrentRunnerAndCi4RequestApplyOnce(): void
    {
        if (PHP_VERSION_ID < 80400 || !is_file(ROOT . '/ci4/vendor/autoload.php')) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $this->contend('sqlite');
        if ($this->postgres()) {
            $this->contend('pgsql');
        }
    }

    public function testLegacyFunctionsMatchTheLegacyGlobals(): void
    {
        $dir = tmpdir('mig-golden');
        try {
            $env = $this->sqliteEnv($dir, true);
            $legacy = $this->worker('golden', 'legacy', $env);
            $ci4 = $this->worker('golden', 'ci4', $env);
            $this->assertSame($legacy, $ci4);
            $this->assertCount(63, $ci4['schema']);
            $this->assertSame('', $ci4['defaults']['password_hash']);
            $this->assertSame('2020-06-15 12:00:00', $ci4['now']);
        } finally {
            rrmdir($dir);
        }
    }

    public function testStatusOutputMatchesLegacy(): void
    {
        if (PHP_VERSION_ID < 80400 || !is_file(ROOT . '/ci4/vendor/autoload.php')) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $left = tmpdir('mig-status-a');
        $right = tmpdir('mig-status-b');
        try {
            $this->worker('run', 'legacy', $this->sqliteEnv($left, false));
            $this->worker('run', 'ci4', $this->sqliteEnv($right, false));
            $legacy = $this->migrateStatus($left, false);
            $ci4 = $this->migrateStatus($right, true);
            $this->assertSame(0, $legacy['code'], $legacy['err']);
            $this->assertSame(0, $ci4['code'], $ci4['out'] . $ci4['err']);
            $this->assertSame($this->statusLines($legacy['out']), $this->statusLines($ci4['out']), $ci4['out'] . $ci4['err']);
            $this->assertStringContainsString('Database: sqlite – ', $ci4['out']);
        } finally {
            rrmdir($left);
            rrmdir($right);
        }
    }

    private function upgradeDriver(string $driver): void
    {
        $files = glob(ROOT . '/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files, SORT_STRING);
        $this->assertCount(26, $files);
        for ($n = 1; $n <= 26; $n++) {
            $slice = $this->slice($files, $n);
            try {
                if ($driver === 'sqlite') {
                    $this->upgradeSqlite($n, $slice);
                } else {
                    $this->upgradePgsql($n, $slice);
                }
            } finally {
                rrmdir($slice);
            }
        }
    }

    private function upgradeSqlite(int $n, string $slice): void
    {
        $left = tmpdir('mig-up-a');
        $right = tmpdir('mig-up-b');
        try {
            $env = $this->sqliteEnv($left, false);
            $env['MIGRATE_DIR'] = $slice;
            $this->worker('run', 'legacy', $env);
            $this->insertDemo($left, null);
            $this->copySqlite($left, $right);
            unset($env['MIGRATE_DIR']);
            $legacy = $this->worker('run', 'legacy', $env);
            $ci4Env = $this->sqliteEnv($right, false);
            $ci4 = $this->worker('run', 'ci4', $ci4Env);
            $this->assertSame([], $ci4['src'], 'version ' . $n);
            $this->assertSame($this->dumpSqlite($left), $this->dumpSqlite($right), 'version ' . $n . ' ' . json_encode($legacy['applied']));
        } finally {
            rrmdir($left);
            rrmdir($right);
        }
    }

    private function upgradePgsql(int $n, string $slice): void
    {
        $schemaA = $this->schemaName('a' . $n);
        $schemaB = $this->schemaName('b' . $n);
        $dirA = tmpdir('mig-up-pg-a');
        $dirB = tmpdir('mig-up-pg-b');
        try {
            $envA = $this->pgEnv($dirA, $schemaA, false);
            $envA['MIGRATE_DIR'] = $slice;
            $envB = $this->pgEnv($dirB, $schemaB, false);
            $envB['MIGRATE_DIR'] = $slice;
            $this->worker('run', 'legacy', $envA);
            $this->worker('run', 'legacy', $envB);
            $this->insertDemo($dirA, $schemaA);
            $this->insertDemo($dirB, $schemaB);
            unset($envA['MIGRATE_DIR'], $envB['MIGRATE_DIR']);
            $this->worker('run', 'legacy', $envA);
            $ci4 = $this->worker('run', 'ci4', $envB);
            $this->assertSame([], $ci4['src'], 'version ' . $n);
            $this->assertSame($this->dumpPgsql($schemaA), $this->dumpPgsql($schemaB), 'version ' . $n);
        } finally {
            rrmdir($dirA);
            rrmdir($dirB);
        }
    }

    private function contend(string $driver): void
    {
        $dir = tmpdir('mig-race');
        $server = null;
        try {
            $extra = ['DB_DRIVER' => 'sqlite', 'DB_DSN' => '', 'APOTHEKE_TEST_DB' => 'sqlite'];
            if ($driver === 'pgsql') {
                $pg = test_pg_params();
                $extra = [
                    'DB_DRIVER' => 'pgsql',
                    'DB_HOST' => (string)$pg['host'],
                    'DB_PORT' => (string)$pg['port'],
                    'DB_NAME' => (string)$pg['name'],
                    'DB_USER' => (string)$pg['user'],
                    'DB_PASSWORD' => (string)$pg['password'],
                    'DB_SCHEMA' => $this->schemaName('race'),
                    'DB_DSN' => '',
                    'APOTHEKE_TEST_DB' => 'pgsql',
                ];
            }
            $server = new Server($dir, $extra, 'ci4');
            $env = array_merge(test_server_env($dir), $extra, [
                'PATH' => (string)getenv('PATH'),
                'APOTHEKE_CLOCK' => self::CLOCK,
                'SEED_DEFAULTS' => '0',
            ]);
            $proc = proc_open(
                [PHP_BINARY, ROOT . '/tests/ci4-migrate-worker.php', 'run', 'ci4'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                ROOT,
                $env
            );
            $this->assertIsResource($proc);
            $response = $server->client()->get('/healthz');
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $log = is_file($server->log) ? (string)file_get_contents($server->log) : '';
            $this->assertSame(0, $code, $driver . $out . $err);
            $this->assertSame(200, $response['status'], $driver . $response['body'] . $log . $err);
            $this->assertSame('ok', $response['body']);
            $decoded = json_decode((string)$out, true);
            $this->assertIsArray($decoded, (string)$out . $err);
            $this->assertSame([], $decoded['src'] ?? ['missing'], $driver);
            $versions = $driver === 'sqlite'
                ? sqlite($dir . '/catalog.sqlite')->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)
                : $this->pgPdo((string)$env['DB_SCHEMA'])->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
            $this->assertCount(26, $versions, $driver);
        } finally {
            if ($server instanceof Server) {
                $server->stop();
            }
            rrmdir($dir);
        }
    }

    /** @param list<string> $files */
    private function slice(array $files, int $count): string
    {
        $dir = tmpdir('mig-slice');
        foreach (array_slice($files, 0, $count) as $file) {
            copy($file, $dir . '/' . basename($file));
        }

        return $dir;
    }

    private function insertDemo(string $dir, ?string $schema): void
    {
        $pdo = $schema === null ? sqlite($dir . '/catalog.sqlite') : $this->pgPdo($schema);
        $exists = $schema === null
            ? (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'locations'")->fetchColumn()
            : (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'locations'")->fetchColumn();
        if ($exists !== 1) {
            return;
        }
        $pdo->exec("INSERT INTO locations (name, name_key, is_default, created_at) VALUES ('Demo Shelf', 'demo shelf', 0, '2020-01-01 00:00:00')");
    }

    private function writeLegacy(string $dir, int $version): void
    {
        $pdo = sqlite($dir . '/catalog.sqlite');
        if ($version === 1) {
            $pdo->exec(<<<SQL
                CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, location TEXT NOT NULL DEFAULT '',
                    quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0), notes TEXT NOT NULL DEFAULT '', image TEXT,
                    search TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
                INSERT INTO items (name, location, quantity, notes, search, created_at, updated_at)
                    VALUES ('Drill', 'box 1023', 1, 'n', 'drill', '2020-01-01 00:00:00', '2020-01-01 00:00:00'),
                           ('Lamp', '  BOX   1023 ', 1, 'n', 'lamp', '2020-01-01 00:00:00', '2020-01-01 00:00:00');
                PRAGMA user_version = 1
            SQL);

            return;
        }
        if ($version === 2) {
            $pdo->exec(<<<SQL
                CREATE TABLE locations (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL UNIQUE,
                    is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)), created_at TEXT NOT NULL);
                CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
                    location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL, quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0),
                    notes TEXT NOT NULL DEFAULT '', image TEXT, search TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
                INSERT INTO locations (name, name_key, is_default, created_at) VALUES ('attic', 'attic', 1, '2020-01-01 00:00:00');
                INSERT INTO items (name, location_id, quantity, search, created_at, updated_at)
                    VALUES ('Thing 1', 1, 1, 'thing 1', '2020-01-01 00:00:00', '2020-01-01 00:00:00');
                PRAGMA user_version = 2
            SQL);

            return;
        }
        $file = ROOT . '/migrations/0001_initial_schema.php';
        $sql = (require $file)['sqlite'];
        $pdo->exec($sql);
        $pdo->exec("INSERT INTO locations (name, name_key, is_default, created_at) VALUES ('attic', 'attic', 1, '2020-01-01 00:00:00')");
        $pdo->exec('PRAGMA user_version = 3');
    }

    private function copySqlite(string $from, string $to): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            $file = $from . '/catalog.sqlite' . $suffix;
            if (is_file($file)) {
                copy($file, $to . '/catalog.sqlite' . $suffix);
            }
        }
    }

    /** @return array<string,mixed> */
    private function sqliteEnv(string $dir, bool $seed): array
    {
        return $this->env($dir, $seed, [
            'DB_DRIVER' => 'sqlite',
            'SQLITE_PATH' => $dir . '/catalog.sqlite',
        ]);
    }

    /** @return array<string,mixed> */
    private function pgEnv(string $dir, string $schema, bool $seed): array
    {
        $pg = test_pg_params();

        return $this->env($dir, $seed, [
            'DB_DRIVER' => 'pgsql',
            'DB_HOST' => (string)$pg['host'],
            'DB_PORT' => (string)$pg['port'],
            'DB_NAME' => (string)$pg['name'],
            'DB_USER' => (string)$pg['user'],
            'DB_PASSWORD' => (string)$pg['password'],
            'DB_SCHEMA' => $schema,
            'DB_DSN' => '',
        ]);
    }

    /** @param array<string,string> $db @return array<string,string> */
    private function env(string $dir, bool $seed, array $db): array
    {
        return array_merge([
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $dir,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'APOTHEKE_CLOCK' => self::CLOCK,
            'SEED_DEFAULTS' => $seed ? '1' : '0',
            'APP_PASSWORD' => '',
            'TZ' => 'UTC',
        ], $db);
    }

    /** @param array<string,string> $env @return array<string,mixed> */
    private function worker(string $mode, string $which, array $env): array
    {
        $proc = proc_open(
            [PHP_BINARY, ROOT . '/tests/ci4-migrate-worker.php', $mode, $which],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT,
            $env
        );
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, $mode . ' ' . $which . "\n" . $out . "\n" . $err);
        $decoded = json_decode((string)$out, true);
        $this->assertIsArray($decoded, (string)$out . $err);

        return $decoded;
    }

    /** @return array{out:string,err:string,code:int} */
    private function migrateStatus(string $dir, bool $ci4): array
    {
        $env = $this->sqliteEnv($dir, false);
        if ($ci4) {
            $env['APOTHEKE_APP'] = 'ci4';
        }
        $proc = proc_open(
            [PHP_BINARY, ROOT . '/bin/migrate.php', '--status'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT,
            $env
        );
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['out' => (string)$out, 'err' => (string)$err, 'code' => proc_close($proc)];
    }

    /** @return list<string> */
    private function statusLines(string $out): array
    {
        $lines = preg_split('/\n/', trim($out)) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '  ')));
    }

    /** @return array<string,mixed> */
    private function dumpSqlite(string $dir): array
    {
        $pdo = sqlite($dir . '/catalog.sqlite');
        $master = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($master as &$row) {
            $row['sql'] = preg_replace('/\s+/', ' ', (string)$row['sql']);
        }
        unset($row);

        return ['master' => $master, 'data' => $this->rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'user_version' => (int)$pdo->query('PRAGMA user_version')->fetchColumn()];
    }

    /** @return array<string,mixed> */
    private function dumpPgsql(string $schema): array
    {
        $pdo = $this->pgPdo($schema);
        $columns = $pdo->query("SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() ORDER BY table_name, ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
        $indexes = $pdo->query('SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() ORDER BY indexname')->fetchAll(PDO::FETCH_ASSOC);
        // pg_trgm lands in the first schema that migrates. A second schema may not see gin_trgm_ops.
        // The migration treats that index as optional.
        $indexes = array_values(array_filter($indexes, static fn (array $row): bool => $row['indexname'] !== 'search_docs_trgm'));
        foreach ($columns as &$column) {
            $column['column_default'] = str_replace($schema, 'schema', (string)$column['column_default']);
        }
        unset($column);
        foreach ($indexes as &$index) {
            $index['indexdef'] = str_replace($schema, 'schema', (string)$index['indexdef']);
        }
        unset($index);

        return ['columns' => $columns, 'indexes' => $indexes, 'data' => $this->rows($pdo, "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name")];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function rows(PDO $pdo, string $tablesSql): array
    {
        $tables = $pdo->query($tablesSql)->fetchAll(PDO::FETCH_COLUMN);
        $data = [];
        foreach ($tables as $table) {
            $name = (string)$table;
            $quoted = '"' . str_replace('"', '""', $name) . '"';
            $rows = $pdo->query('SELECT * FROM ' . $quoted . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
            if ($name === 'schema_migrations') {
                foreach ($rows as &$row) {
                    unset($row['applied_at']);
                }
                unset($row);
            }
            $data[$name] = $rows;
        }

        return $data;
    }

    private function pgPdo(string $schema): PDO
    {
        $pg = test_pg_params();
        $pdo = new PDO(
            'pgsql:host=' . $pg['host'] . ';port=' . $pg['port'] . ';dbname=' . $pg['name'],
            $pg['user'],
            $pg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec('SET search_path TO ' . '"' . str_replace('"', '""', $schema) . '"');

        return $pdo;
    }

    private function schemaName(string $suffix): string
    {
        $name = 'm' . bin2hex(random_bytes(3)) . preg_replace('/[^a-z0-9]/', '', strtolower($suffix));
        $this->schemas[] = $name;

        return $name;
    }

    private function postgres(): bool
    {
        try {
            test_pg_admin();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
