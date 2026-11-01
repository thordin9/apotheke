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

    /**
     * The migrations of 2.6.26 (branch `old`) by version, with their sha256: they stay byte-identical, so a fresh run
     * of them is the schema 2.6.x reads. Every later file is CI4-only (AGENTS.md "No shared schema change"). Keyed by
     * version, not file name: gitleaks reads a hash next to a name like "sort_keys..." as an API key.
     */
    private const LEGACY_MIGRATIONS = [
        '0001' => '60258bd8315094d2fabb34e97cceedd6627b9ecce0be24afc3bd875949ea7888',
        '0002' => 'f8066324b818295d8c616b37a9598691c7ac568b00867a59ced15c5357ac2d75',
        '0003' => '0799a265942cc9d4f15066d6be0b928d5f252bcd5204132a087465b3f8274bee',
        '0004' => '87f7a73e4e079b45cfa70028ea00ad6448cbe88660c5409d16eb89697be6197c',
        '0005' => '389cf5e85fd13c908988fa19b99cca2f6151d105d24e0e5def047f93cb1d7ce9',
        '0006' => '54483b840a5bc5059b5e727c6a1f8af6cf85610d10c5ae8bb3b7ceb2bb7fb683',
        '0007' => '7124dc0f42b59fef762523a05f0eb7e022d1129960c397785c5a9ac7f7988c72',
        '0008' => 'faa998bcd4d8ac9161767a14aa0307a50da7eda4fff207b1537a3562d1aee6d9',
        '0009' => '1ee40243768334b01cdcc682d226787247c47696f3dd38d856bc834ffd929470',
        '0010' => '2e59156f0793a87e7e2a66f66550bd443c330ceaa27c867c13c9969bd593d57d',
        '0011' => '34d9cc21605c4f22e3b823068f1964e439813e108119a89516333b97376d03b4',
        '0012' => '50f29bbfc1496099f15a262fef9b8863097d52e4d159e73ca21f0ccd8e779a51',
        '0013' => 'b5a402c862718654b3cb9a4a40242232902115205f58713ea2a27f44ea3c5192',
        '0014' => '06f2263670895e6efd5876ed9fe85d8eebc100fd1349509a68b737c77784ec53',
        '0015' => 'ec3a22775e4612354b023a90d3d728f8e1030f096d834b935ded108e2b2d61c6',
        '0016' => '8e25283ab06407eb12df40585662a61851b27d8cbb62cf8df898bf52f01d7351',
        '0017' => 'a5c10273702913c761d0115f2f7d0e2472955ccff978f706c03b42961b0ed670',
        '0018' => '70fdcd20a62f019d06a62cf457334d07f99d0ae3a4d210e2f229d94dc4578f1f',
        '0019' => 'c0e5d85f866542d1a39ca158fc924739816c2146e9d0da90f026cbd9ec8914dd',
        '0020' => '5d97cbb32add8ee0a58139d59eb3ebbbb87cc2295fe60d11ea4eee88fec52a6b',
        '0021' => 'a8fde87827cf6f7ca5fa5dbbaa91042776940cb1787430cb943104cf0fb62768',
        '0022' => '1764b22debd54a03fa334bbe9b6b7f950e28f6e49ac4a25f4ded94c8d7eaaa7c',
        '0023' => 'c2679b6ecb490410bf40d6f1ea40cfb3a2efc70d120927004418ade9b39a8f16',
        '0024' => '57e45925d2e708a0b0eee3a6ca7e183df706939ee28cacab810d4a455b318f9a',
        '0025' => '6be213a6c6a0df12769c6a52c123231b59942ba89cb0229aaded4d54a148e2af',
        '0026' => 'b88c6afe133ddf8b8d8ff7fdebf1b79d8b1698fc60bda18d4e6845950f86b535',
        '0027' => '6e833da10654064471742df81a309f13eac5614e559e6b507f3c45b4a782932e',
        '0028' => '5f167997ddf0633c3ecbaf86c0433b8e6e741074fe4823913a13a8da0b0b908b',
    ];

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
            $this->assertCount(32, $applied, $label . ': legacy-v' . $version . ' stands for 0001');

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
        $this->assertCount(32, $names);
        $banner = 'Database: sqlite – ' . $dir . '/catalog.sqlite';

        $pending = $this->migrate($dir, ['--status']);
        $lines = [$banner];
        foreach ($names as $version => $name) {
            $lines[] = sprintf('  %s  %-40s %s', $version, $name, 'PENDING');
        }
        $this->assertSame([0, implode("\n", $lines) . "\n", ''], [$pending['code'], $pending['out'], $pending['err']]);

        $run = $this->migrate($dir, []);
        $this->assertSame(0, $run['code'], $run['out'] . $run['err']);
        $this->assertStringEndsWith("Done: 32 step(s).\n", $run['out']);
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

    /**
     * AGENTS.md "No shared schema change": the 2.6.26 migrations are unchanged, and every later (CI4-only) migration
     * is registered in Backups::UNCARRIED_MIGRATIONS and in tests/upgrade/lib.php upg_ci4_only() with exactly the
     * tables it creates, on each driver.
     */
    public function testEveryCi4OnlyMigrationIsRegistered(): void
    {
        require_once ROOT . '/tests/upgrade/lib.php';
        $files = $this->migrationFiles();
        $names = array_map('basename', $files);
        $legacy = array_slice($names, 0, count(self::LEGACY_MIGRATIONS));
        $versions = array_map(static fn (string $n): string => substr($n, 0, 4), $legacy);
        $this->assertSame(array_map('strval', array_keys(self::LEGACY_MIGRATIONS)), $versions, 'the 2.6.26 migrations come first, none removed');
        foreach (array_values(self::LEGACY_MIGRATIONS) as $i => $sha) {
            $this->assertSame($sha, hash_file('sha256', ROOT . '/migrations/' . $legacy[$i]), $legacy[$i] . ' must stay byte-identical with 2.6.26');
        }
        $ci4Only = array_map(static fn (string $n): string => substr($n, 0, 4), array_slice($names, count(self::LEGACY_MIGRATIONS)));
        $this->assertSame($ci4Only, \Apotheke\Backup\Backups::UNCARRIED_MIGRATIONS, 'every CI4-only migration is in Backups::UNCARRIED_MIGRATIONS');
        $this->assertSame($ci4Only, upg_ci4_only()['versions'], 'every CI4-only migration is in tests/upgrade/lib.php upg_ci4_only()');
        foreach ($this->drivers() as $driver) {
            [$legacyShape, $shape] = $this->legacyAndCurrentShape($driver);
            $created = array_values(array_diff(array_keys($shape), array_keys($legacyShape)));
            sort($created);
            $registered = upg_ci4_only()['tables'];
            sort($registered);
            $this->assertSame($registered, $created, $driver . ': upg_ci4_only() lists exactly the tables the CI4-only migrations create');
        }
    }

    /**
     * AGENTS.md "No shared schema change": after every migration, each table 2.6.26 creates is still there with the
     * same columns (name, type, null, default, order), indexes, UNIQUE/CHECK/FK constraints and triggers (L-1300): no
     * CI4-only migration alters, adds to or drops a table, column, index, constraint or trigger 2.6.x reads.
     */
    public function testNoCi4OnlyMigrationChangesATable26Reads(): void
    {
        foreach ($this->drivers() as $driver) {
            [$legacyShape, $shape] = $this->legacyAndCurrentShape($driver);
            $this->assertNotSame([], $legacyShape);
            foreach ($legacyShape as $table => $columns) {
                $this->assertSame($columns, $shape[$table] ?? null, $driver . ': table ' . $table . ' as 2.6.26 creates it');
            }
        }
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(ROOT . '/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private function drivers(): array
    {
        return $this->postgres() ? ['sqlite', 'pgsql'] : ['sqlite'];
    }

    /**
     * The tables and columns after a fresh run of the 2.6.26 migrations, and after every migration.
     *
     * @return array{0:array<string,list<string>>,1:array<string,list<string>>}
     */
    private function legacyAndCurrentShape(string $driver): array
    {
        $out = [];
        foreach (['legacy' => $this->slice($this->migrationFiles(), count(self::LEGACY_MIGRATIONS)), 'current' => null] as $label => $slice) {
            [$dir, $schema] = $this->target($driver, 'shape' . $label);
            $env = $this->env($driver, $dir, $schema);
            $this->worker($slice === null ? $env : $env + ['MIGRATE_DIR' => $slice]);
            $out[] = $this->shape($driver === 'sqlite' ? sqlite($dir . '/catalog.sqlite') : $this->pgPdo((string)$schema), $driver);
        }

        return [$out[0], $out[1]];
    }

    /**
     * Each table's shape as 2.x reads it: columns ("column name type null default", in order), then its indexes (name,
     * columns, unique, partial WHERE), constraints (UNIQUE, CHECK, FK) and triggers, sorted. Index and trigger names
     * and SQL are compared as written, with the pgsql schema name taken out (each run has its own schema).
     *
     * @return array<string,list<string>>
     */
    private function shape(PDO $pdo, string $driver): array
    {
        return $driver === 'sqlite' ? $this->sqliteShape($pdo) : $this->pgShape($pdo);
    }

    /** @return array<string,list<string>> */
    private function sqliteShape(PDO $pdo): array
    {
        $shape = [];
        $tables = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($tables as $t => $sql) {
            $q = '"' . str_replace('"', '""', (string)$t) . '"';
            $columns = array_map(static fn (array $c): string => implode(' ', ['column', $c['name'], $c['type'], $c['notnull'], (string)$c['dflt_value']]),
                $pdo->query('PRAGMA table_info(' . $q . ')')->fetchAll(PDO::FETCH_ASSOC));
            // UNIQUE and CHECK constraints live only in the CREATE TABLE text on SQLite
            $rest = ['table ' . preg_replace('/\s+/', ' ', (string)$sql)];
            foreach ($pdo->query('PRAGMA index_list(' . $q . ')')->fetchAll(PDO::FETCH_ASSOC) as $i) {
                $cols = array_column($pdo->query('PRAGMA index_info("' . str_replace('"', '""', (string)$i['name']) . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
                $isql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = " . $pdo->quote((string)$i['name']))->fetchColumn();
                $where = is_string($isql) && preg_match('/\bWHERE\b(.*)$/is', $isql, $m) ? trim(preg_replace('/\s+/', ' ', $m[1])) : '';
                $rest[] = implode(' ', ['index', $i['name'], 'unique=' . $i['unique'], 'origin=' . $i['origin'], 'cols=' . implode(',', $cols), 'where=' . $where]);
            }
            foreach ($pdo->query('PRAGMA foreign_key_list(' . $q . ')')->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $rest[] = implode(' ', ['fk', $f['id'], $f['seq'], $f['from'], '->', $f['table'] . '.' . $f['to'], 'update=' . $f['on_update'], 'delete=' . $f['on_delete']]);
            }
            $triggers = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = " . $pdo->quote((string)$t))->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($triggers as $name => $tsql) {
                $rest[] = 'trigger ' . $name . ' ' . preg_replace('/\s+/', ' ', (string)$tsql);
            }
            sort($rest, SORT_STRING);
            $shape[(string)$t] = [...$columns, ...$rest];
        }

        return $shape;
    }

    /** @return array<string,list<string>> */
    private function pgShape(PDO $pdo): array
    {
        $schema = (string)$pdo->query('SELECT current_schema()')->fetchColumn();
        $unqualify = static fn (string $sql): string => str_replace(['"' . $schema . '".', $schema . '.'], '', $sql);
        $shape = [];
        foreach ($pdo->query('SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns
            WHERE table_schema = current_schema() ORDER BY table_name, ordinal_position')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $shape[(string)$c['table_name']][] = implode(' ', ['column', $c['column_name'], $c['data_type'], $c['is_nullable'], $unqualify((string)$c['column_default'])]);
        }
        $rest = [];
        // search_docs_trgm on search_docs is optional (0019 skips it when this schema cannot see pg_trgm), as in
        // dumpPgsql(); an index of that name on another table still counts (L-1321)
        foreach ($pdo->query("SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema()
            AND NOT (tablename = 'search_docs' AND indexname = 'search_docs_trgm')")->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $rest[(string)$i['tablename']][] = 'index ' . $i['indexname'] . ' ' . $unqualify((string)$i['indexdef']);
        }
        foreach ($pdo->query("SELECT c.relname, k.conname, k.contype, pg_get_constraintdef(k.oid) AS def FROM pg_constraint k
            JOIN pg_class c ON c.oid = k.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema()")->fetchAll(PDO::FETCH_ASSOC) as $k) {
            $rest[(string)$k['relname']][] = implode(' ', ['constraint', $k['conname'], $k['contype'], $unqualify((string)$k['def'])]);
        }
        foreach ($pdo->query('SELECT c.relname, t.tgname, pg_get_triggerdef(t.oid) AS def FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND NOT t.tgisinternal')->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $rest[(string)$t['relname']][] = 'trigger ' . $t['tgname'] . ' ' . $unqualify((string)$t['def']);
        }
        foreach ($rest as $table => $lines) {
            sort($lines, SORT_STRING);
            $shape[$table] = [...($shape[$table] ?? []), ...$lines];
        }

        return $shape;
    }

    private function upgradeDriver(string $driver): void
    {
        $files = glob(ROOT . '/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files, SORT_STRING);
        $this->assertCount(32, $files);
        [$fresh, $freshSchema] = $this->target($driver, 'fresh');
        $this->assertCount(32, $this->worker($this->env($driver, $fresh, $freshSchema))['applied']);
        $expected = $this->dump($driver, $fresh, $freshSchema);
        for ($n = 1; $n < 32; $n++) {
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
