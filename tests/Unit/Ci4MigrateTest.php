<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * PdoMigrationRunner, the one migration path since the cutover (M-960):
 * an upgrade from every schema version, the legacy SQLite v1–v3 adopt with
 * its backup, legacy_migrate_to_v3, apply-once under concurrent runs (SQLite
 * pinned to BEGIN IMMEDIATE), the --status output, and the stop on a too
 * short APP_PASSWORD when migrate seeds the first admin.
 */
final class Ci4MigrateTest extends TestCase
{
    private const CLOCK = '2020-06-15 12:00:00';

    private const SHORT = "APP_PASSWORD is too short: use at least 8 characters (not only spaces), or leave it empty.\n";

    /** @var list<string> */
    private array $schemas = [];

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            rrmdir($dir);
        }
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

    public function testUpgradeFromEveryVersionMatchesAFreshInstall(): void
    {
        $this->upgradeDriver('sqlite');
        if ($this->postgres()) {
            $this->upgradeDriver('pgsql');
        }
    }

    public function testLegacySqliteAdoptKeepsTheDataAndWritesABackup(): void
    {
        $fresh = $this->dir('mig-adopt-fresh');
        $this->worker($this->sqliteEnv($fresh));
        foreach ([1, 2, 3] as $version) {
            $label = 'v' . $version;
            $dir = $this->dir('mig-adopt' . $version);
            $this->writeLegacy($dir, $version);
            $before = $this->dumpSqlite($dir);

            $applied = $this->worker($this->sqliteEnv($dir))['applied'];
            $this->assertSame('legacy-v' . $version, $applied[0] ?? null, $label);
            $this->assertCount(28, $applied, $label . ': legacy-v' . $version . ' stands for 0001');

            $backups = glob($dir . '/catalog-v' . $version . '-backup-*.sqlite') ?: [];
            $this->assertCount(1, $backups, $label . ': one backup');
            $this->assertMatchesRegularExpression('/catalog-v' . $version . '-backup-\d{8}-\d{6}\.sqlite$/', $backups[0], $label);
            $this->assertSame($before, $this->dumpSqlite(dirname($backups[0]), basename($backups[0])), $label . ': the backup is the database as it was');

            $after = $this->dumpSqlite($dir);
            $this->assertSame(3, $after['user_version'], $label);
            $this->assertSame(['0001', 'initial_schema (adopted from legacy v' . $version . ')'], [$after['data']['schema_migrations'][0]['version'] ?? null, $after['data']['schema_migrations'][0]['name'] ?? null], $label);
            $this->assertSame($this->columns(sqlite($fresh . '/catalog.sqlite')), $this->columns(sqlite($dir . '/catalog.sqlite')), $label . ': the same tables and columns as a fresh install');
            $items = array_column($after['data']['items'], 'name');
            $this->assertSame($version === 1 ? ['Drill', 'Lamp'] : ($version === 2 ? ['Thing 1'] : []), $items, $label . ': the items are kept');
            if ($version === 1) {
                // "box 1023" and "  BOX   1023 " fold to one location, which both items point at
                $this->assertSame(['box 1023'], array_column($after['data']['locations'], 'name'), $label);
                $this->assertSame([1, 1], array_map('intval', array_column($after['data']['items'], 'location_id')), $label);
            } else {
                $this->assertContains('attic', array_column($after['data']['locations'], 'name'), $label);
            }

            // a second run adopts nothing again and writes no second backup
            $this->assertSame([], $this->worker($this->sqliteEnv($dir))['applied'], $label);
            $this->assertCount(1, glob($dir . '/catalog-v' . $version . '-backup-*.sqlite') ?: [], $label);
        }
    }

    public function testLegacyMigrateToV3AddsCategories(): void
    {
        $dir = $this->dir('mig-v3');
        $this->writeLegacy($dir, 2);
        $pdo = sqlite($dir . '/catalog.sqlite');
        require_once ROOT . '/modules/Core/Config/config-functions.php';
        require_once ROOT . '/modules/Migrations/LegacyFunctions.php';
        legacy_migrate_to_v3($pdo);
        $this->assertSame(['created_at', 'id', 'is_default', 'name', 'name_key'], $this->columns($pdo)['categories'] ?? null);
        $this->assertContains('category_id', $this->columns($pdo)['items']);
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name IN ('idx_categories_one_default', 'idx_items_category') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['idx_categories_one_default', 'idx_items_category'], $indexes);
        $this->assertSame([['name' => 'Thing 1', 'category_id' => null]], $pdo->query('SELECT name, category_id FROM items')->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * SQLite takes the write lock at BEGIN IMMEDIATE, before it reads
     * schema_migrations: a probe migration finds the database locked for any
     * other writer while it runs. A plain (deferred) BEGIN would hold only a
     * read snapshot there, and a second runner could apply the same steps.
     */
    public function testSqliteRunnerHoldsTheWriteLockFromTheStart(): void
    {
        $dir = $this->dir('mig-immediate');
        $slice = $this->dir('mig-immediate-slice');
        file_put_contents($slice . '/0001_probe.php', <<<'PHP'
            <?php
            return static function (PDO $pdo, string $driver): void {
                $other = new PDO('sqlite:' . getenv('SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
                $other->exec('PRAGMA busy_timeout = 0');
                try {
                    $other->exec('BEGIN IMMEDIATE');
                    $other->exec('ROLLBACK');
                    $state = 'free';
                } catch (PDOException) {
                    $state = 'locked';
                }
                file_put_contents(getenv('PROBE_OUT'), $state);
            };
            PHP);
        // schema_migrations already exists, so CREATE TABLE IF NOT EXISTS writes nothing before the probe
        $pdo = sqlite($dir . '/catalog.sqlite');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('CREATE TABLE schema_migrations (version TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)');
        $pdo = null;
        $env = $this->sqliteEnv($dir) + ['MIGRATE_DIR' => $slice, 'PROBE_OUT' => $dir . '/probe'];
        $this->assertSame(['0001'], $this->worker($env)['applied']);
        $this->assertSame('locked', (string)@file_get_contents($dir . '/probe'), 'another writer got in while the runner was migrating: BEGIN IMMEDIATE is not in effect');
    }

    public function testConcurrentRunnersApplyEachMigrationOnce(): void
    {
        $this->contend('sqlite');
        if ($this->postgres()) {
            $this->contend('pgsql');
        }
    }

    public function testStatusOutput(): void
    {
        $dir = $this->dir('mig-status');
        $names = [];
        foreach (migration_versions() as $version) {
            $file = glob(ROOT . '/migrations/' . $version . '_*.php')[0];
            $names[$version] = substr(basename($file, '.php'), 5);
        }
        $this->assertCount(28, $names);
        $banner = 'Database: sqlite – ' . $dir . '/catalog.sqlite';

        $pending = $this->migrate($dir, ['--status']);
        $lines = [$banner];
        foreach ($names as $version => $name) {
            $lines[] = sprintf('  %s  %-40s %s', $version, $name, 'PENDING');
        }
        $this->assertSame([0, implode("\n", $lines) . "\n", ''], [$pending['code'], $pending['out'], $pending['err']]);

        $run = $this->migrate($dir, []);
        $this->assertSame(0, $run['code'], $run['out'] . $run['err']);
        $this->assertStringEndsWith("Done: 28 step(s).\n", $run['out']);
        $lines = [$banner];
        foreach ($names as $version => $name) {
            $lines[] = sprintf('  %s  %-40s %s', $version, $name, 'applied ' . self::CLOCK);
        }
        $applied = $this->migrate($dir, ['--status']);
        $this->assertSame([0, implode("\n", $lines) . "\n", ''], [$applied['code'], $applied['out'], $applied['err']]);

        $again = $this->migrate($dir, []);
        $this->assertSame([0, $banner . "\nAlready up to date.\n", ''], [$again['code'], $again['out'], $again['err']]);
    }

    /**
     * Hardening (4a): a too short or blank APP_PASSWORD stops the migration
     * that would seed it as the first admin's password (0013): exit 1, the
     * message (never the value), and nothing applied, since the run is one
     * transaction. A long enough one migrates and becomes the account "admin".
     */
    public function testShortAppPasswordStopsMigrate(): void
    {
        $stopped = static function (string $dir): string {
            $out = 'Database: sqlite – ' . $dir . "/catalog.sqlite\n";
            foreach (array_slice(migration_versions(), 0, 12) as $version) {
                $out .= '  applied ' . $version . ' ' . substr(basename(glob(ROOT . '/migrations/' . $version . '_*.php')[0], '.php'), 5) . "\n";
            }

            return $out;
        };
        foreach (['shortpw', '        ', "\t \t"] as $password) {
            $label = 'APP_PASSWORD of ' . strlen($password) . ' characters';
            $dir = $this->dir('mig-pw');
            $r = $this->migrate($dir, [], ['APP_PASSWORD' => $password]);
            $this->assertSame([1, self::SHORT], [$r['code'], $r['err']], $label . ': ' . $r['out']);
            $this->assertSame($stopped($dir), $r['out'], $label . ': it stops at 0013');
            $this->assertStringNotContainsString('shortpw', $r['out'] . $r['err'], $label);
            $pdo = sqlite($dir . '/catalog.sqlite');
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame([], $tables, $label . ': the transaction rolled back');
        }

        $dir = $this->dir('mig-pw-long');
        $r = $this->migrate($dir, [], ['APP_PASSWORD' => 'long-enough']);
        $this->assertSame([0, ''], [$r['code'], $r['err']], $r['out']);
        $this->assertStringNotContainsString('long-enough', $r['out']);
        $users = sqlite($dir . '/catalog.sqlite')->query('SELECT username, role, password_hash FROM users')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $users);
        $this->assertSame(['admin', 'admin'], [$users[0]['username'], $users[0]['role']]);
        $this->assertTrue(password_verify('long-enough', (string)$users[0]['password_hash']));
    }

    private function upgradeDriver(string $driver): void
    {
        $files = glob(ROOT . '/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files, SORT_STRING);
        $this->assertCount(28, $files);
        [$fresh, $freshSchema] = $this->target($driver, 'fresh');
        $this->assertCount(28, $this->worker($this->env($driver, $fresh, $freshSchema))['applied']);
        $expected = $this->dump($driver, $fresh, $freshSchema);
        for ($n = 1; $n < 28; $n++) {
            $slice = $this->slice($files, $n);
            foreach ([false, true] as $demo) {
                $label = $driver . ' from version ' . $n . ($demo ? ' with data' : '');
                [$dir, $schema] = $this->target($driver, 'up' . $n . ($demo ? 'd' : ''));
                $env = $this->env($driver, $dir, $schema);
                $this->assertCount($n, $this->worker($env + ['MIGRATE_DIR' => $slice])['applied'], $label);
                if ($demo) {
                    $this->insertDemo($driver, $dir, $schema);
                }
                $applied = $this->worker($env)['applied'];
                $this->assertSame(array_slice(migration_versions(), $n), $applied, $label);
                $dump = $this->dump($driver, $dir, $schema);
                if ($demo) {
                    $this->assertContains('Demo Shelf', array_column($dump['data']['locations'], 'name'), $label);
                    $this->assertSame($expected['schema'], $dump['schema'], $label);
                } else {
                    $this->assertSame($expected, $dump, $label);
                }
            }
        }
    }

    private function contend(string $driver): void
    {
        [$dir, $schema] = $this->target($driver, 'race');
        $env = $this->env($driver, $dir, $schema);
        if ($driver === 'sqlite') {
            // an existing (empty) WAL database: the first switch to WAL on a new file
            // can itself fail with "database is locked" under contention (see M-960 notes)
            sqlite($dir . '/catalog.sqlite')->exec('PRAGMA journal_mode = WAL');
        }
        $procs = [];
        for ($i = 0; $i < 4; $i++) {
            $proc = proc_open([PHP_BINARY, ROOT . '/tests/ci4-migrate-worker.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $applied = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = (string)stream_get_contents($pipes[1]);
            $err = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $driver . ': ' . $out . $err);
            $decoded = json_decode($out, true);
            $this->assertIsArray($decoded, $out . $err);
            array_push($applied, ...$decoded['applied']);
        }
        sort($applied, SORT_STRING);
        $this->assertSame(migration_versions(), $applied, $driver . ': each migration applied by exactly one runner');
        $pdo = $driver === 'sqlite' ? sqlite($dir . '/catalog.sqlite') : $this->pgPdo((string)$schema);
        $this->assertSame(migration_versions(), $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN), $driver);
    }

    /** @return array{0:string,1:?string} */
    private function target(string $driver, string $label): array
    {
        return [$this->dir('mig-' . $label), $driver === 'pgsql' ? $this->schemaName($label) : null];
    }

    /** @return array<string,string> */
    private function env(string $driver, string $dir, ?string $schema): array
    {
        return $driver === 'sqlite' ? $this->sqliteEnv($dir) : $this->pgEnv($dir, (string)$schema);
    }

    /** @return array<string,mixed> */
    private function dump(string $driver, string $dir, ?string $schema): array
    {
        if ($driver === 'sqlite') {
            $d = $this->dumpSqlite($dir);

            return ['schema' => [$d['master'], $d['user_version']], 'data' => $d['data']];
        }

        return $this->dumpPgsql((string)$schema);
    }

    /** @param list<string> $files */
    private function slice(array $files, int $count): string
    {
        $dir = $this->dir('mig-slice');
        foreach (array_slice($files, 0, $count) as $file) {
            copy($file, $dir . '/' . basename($file));
        }

        return $dir;
    }

    private function insertDemo(string $driver, string $dir, ?string $schema): void
    {
        $pdo = $driver === 'sqlite' ? sqlite($dir . '/catalog.sqlite') : $this->pgPdo((string)$schema);
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
        $pdo->exec((require ROOT . '/migrations/0001_initial_schema.php')['sqlite']);
        $pdo->exec("INSERT INTO locations (name, name_key, is_default, created_at) VALUES ('attic', 'attic', 1, '2020-01-01 00:00:00')");
        $pdo->exec('PRAGMA user_version = 3');
    }

    /** @return array<string,string> */
    private function sqliteEnv(string $dir): array
    {
        return $this->baseEnv($dir, ['DB_DRIVER' => 'sqlite', 'SQLITE_PATH' => $dir . '/catalog.sqlite']);
    }

    /** @return array<string,string> */
    private function pgEnv(string $dir, string $schema): array
    {
        $pg = test_pg_params();

        return $this->baseEnv($dir, [
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

    /**
     * @param array<string,string> $db
     * @return array<string,string>
     */
    private function baseEnv(string $dir, array $db): array
    {
        return array_merge([
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $dir,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'APOTHEKE_CLOCK' => self::CLOCK,
            'SEED_DEFAULTS' => '0',
            'APP_PASSWORD' => '',
            'TZ' => 'UTC',
        ], $db);
    }

    /**
     * @param array<string,string> $env
     * @return array{applied:list<string>}
     */
    private function worker(array $env): array
    {
        $proc = proc_open([PHP_BINARY, ROOT . '/tests/ci4-migrate-worker.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, $env);
        $this->assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $out . "\n" . $err);
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, $out . $err);

        return $decoded;
    }

    /**
     * php bin/migrate.php with $args on SQLite in $dir.
     *
     * @param list<string> $args
     * @param array<string,string> $extraEnv
     * @return array{out:string,err:string,code:int}
     */
    private function migrate(string $dir, array $args, array $extraEnv = []): array
    {
        $proc = proc_open([PHP_BINARY, ROOT . '/bin/migrate.php', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, array_merge($this->sqliteEnv($dir), $extraEnv));
        $this->assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['out' => $out, 'err' => $err, 'code' => proc_close($proc)];
    }

    /** @return array<string,list<string>> table => sorted column names */
    private function columns(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $names = array_column($pdo->query('PRAGMA table_info("' . str_replace('"', '""', (string)$table) . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
            sort($names, SORT_STRING);
            $out[(string)$table] = $names;
        }

        return $out;
    }

    /** @return array{master:list<array<string,mixed>>,data:array<string,list<array<string,mixed>>>,user_version:int} */
    private function dumpSqlite(string $dir, string $file = 'catalog.sqlite'): array
    {
        $pdo = sqlite($dir . '/' . $file);
        $master = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($master as &$row) {
            $row['sql'] = preg_replace('/\s+/', ' ', (string)$row['sql']);
        }
        unset($row);

        return ['master' => $master, 'data' => $this->rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'user_version' => (int)$pdo->query('PRAGMA user_version')->fetchColumn()];
    }

    /** @return array{schema:array<string,mixed>,data:array<string,list<array<string,mixed>>>} */
    private function dumpPgsql(string $schema): array
    {
        $pdo = $this->pgPdo($schema);
        $columns = $pdo->query('SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() ORDER BY table_name, column_name')->fetchAll(PDO::FETCH_ASSOC);
        $indexes = $pdo->query('SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() ORDER BY indexname')->fetchAll(PDO::FETCH_ASSOC);
        // pg_trgm lands in the first schema that migrates; another schema may not see gin_trgm_ops.
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

        return ['schema' => ['columns' => $columns, 'indexes' => $indexes], 'data' => $this->rows($pdo, "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name")];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function rows(PDO $pdo, string $tablesSql): array
    {
        $data = [];
        foreach ($pdo->query($tablesSql)->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $name = (string)$table;
            $data[$name] = $pdo->query('SELECT * FROM "' . str_replace('"', '""', $name) . '" ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }

        return $data;
    }

    private function pgPdo(string $schema): PDO
    {
        $pg = test_pg_params();
        $pdo = new PDO('pgsql:host=' . $pg['host'] . ';port=' . $pg['port'] . ';dbname=' . $pg['name'], $pg['user'], $pg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('SET search_path TO "' . str_replace('"', '""', $schema) . '"');

        return $pdo;
    }

    private function dir(string $prefix): string
    {
        $dir = tmpdir($prefix);
        $this->dirs[] = $dir;

        return $dir;
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
