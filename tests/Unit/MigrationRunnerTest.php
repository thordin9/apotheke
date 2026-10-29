<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

final class MigrationRunnerTest extends DatabaseTestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir) { array_map('unlink', glob($this->dir . '/*') ?: []); @rmdir($this->dir); }
        parent::tearDown();
    }

    /** A temp migrations dir: the real 0001 plus custom files. */
    private function dirWith(array $files): string
    {
        $this->dir = sys_get_temp_dir() . '/apotheke-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        copy(migrations_dir() . '/0001_initial_schema.php', $this->dir . '/0001_initial_schema.php');
        foreach ($files as $name => $php) file_put_contents($this->dir . '/' . $name, $php);
        return $this->dir;
    }

    public function testFreshDatabaseGetsAllMigrations(): void
    {
        $this->assertSame([], migrations_applied($this->pdo, $this->driver));
        $this->assertSame(array_keys(migrations_available()), array_keys(migrations_pending($this->pdo, $this->driver)));
        $done = $this->migrateFresh();
        $this->assertSame(array_keys(migrations_available()), $done);
        $this->assertSame(array_keys(migrations_available()), array_keys(migrations_applied($this->pdo, $this->driver)));
        foreach (['locations', 'categories', 'items', 'app_settings', 'schema_migrations'] as $t) $this->assertTrue($this->tableExists($t), $t);
        $this->assertSame([], migrations_pending($this->pdo, $this->driver));
    }

    public function testSecondRunIsNoop(): void
    {
        $this->migrateFresh();
        $this->assertSame([], $this->migrateFresh());
    }

    public function testMigrationsAreOrderedAndOnlyPendingRun(): void
    {
        $dir = $this->dirWith([]);
        $this->assertSame(['0001'], migrate($this->pdo, $this->driver, ['dir' => $dir]));
        file_put_contents("$dir/0003_c.php", "<?php return ['sqlite' => 'CREATE TABLE t3 (id INTEGER)', 'pgsql' => 'CREATE TABLE t3 (id INTEGER)'];");
        file_put_contents("$dir/0002_b.php", "<?php return static function (PDO \$p, string \$d): void { \$p->exec('CREATE TABLE t2 (id INTEGER)'); };");
        $this->assertSame(['0002', '0003'], array_keys(migrations_pending($this->pdo, $this->driver, $dir)));
        $this->assertSame(['0002', '0003'], migrate($this->pdo, $this->driver, ['dir' => $dir]));
        $this->assertTrue($this->tableExists('t2'));
        $this->assertSame('b', $this->pdo->query("SELECT name FROM schema_migrations WHERE version = '0002'")->fetchColumn());
    }

    public function testFailingMigrationRollsBackEverything(): void
    {
        $dir = $this->dirWith([
            '0002_ok.php'     => "<?php return ['sqlite' => 'CREATE TABLE ok2 (id INTEGER)', 'pgsql' => 'CREATE TABLE ok2 (id INTEGER)'];",
            '0003_broken.php' => "<?php return ['sqlite' => 'CREATE TABLE nope (', 'pgsql' => 'CREATE TABLE nope ('];",
        ]);
        try {
            migrate($this->pdo, $this->driver, ['dir' => $dir]);
            $this->fail('expected exception');
        } catch (PDOException) {
        }
        $this->assertFalse($this->tableExists('ok2'), 'earlier step in the same run rolled back');
        $this->assertFalse($this->tableExists('items'), '0001 rolled back too');
        $this->assertSame([], migrations_applied($this->pdo, $this->driver));
    }

    public function testMigrationWithoutStepForDriverFails(): void
    {
        $dir = $this->dirWith(['0002_x.php' => "<?php return ['oracle' => 'SELECT 1'];"]);
        $this->expectException(RuntimeException::class);
        migrate($this->pdo, $this->driver, ['dir' => $dir]);
    }

    public function testSeedsStarterDataOnlyOnFreshInstallWhenEnabled(): void
    {
        $this->migrateFresh(['seed' => true]);
        $this->assertSame(6, (int)$this->pdo->query('SELECT COUNT(*) FROM locations')->fetchColumn());
        $this->assertSame('Box 1', $this->pdo->query('SELECT name FROM locations WHERE is_default = 1')->fetchColumn());
        $this->assertSame('General', $this->pdo->query('SELECT name FROM categories WHERE is_default = 1')->fetchColumn());
        $this->assertNotNull(setting_get($this->pdo, 'seeded_defaults'));
    }

    public function testNewInstallGetsTheStarterCategories(): void
    {
        $this->migrateFresh(['seed' => true]);
        $this->assertSame(['Books', 'Clothing', 'Documents', 'Electronics', 'General', 'Hobby', 'Kitchen', 'Lighting', 'Shoes', 'Tools'],
            $this->pdo->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame('General', $this->pdo->query('SELECT name FROM categories WHERE is_default = 1')->fetchColumn());
    }

    /** A 2.2 database (migrations up to 0023, its own categories) upgraded to this version keeps its categories exactly. */
    public function testUpgradeLeavesExistingCategoriesAlone(): void
    {
        $this->dir = sys_get_temp_dir() . '/apotheke-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        foreach (glob(migrations_dir() . '/*.php') as $f) if (substr(basename($f), 0, 4) <= '0023') copy($f, $this->dir . '/' . basename($f));
        migrate($this->pdo, $this->driver, ['dir' => $this->dir, 'seed' => false, 'data_dir' => sys_get_temp_dir()]);
        // as 2.2 left it: the old starter list (one renamed, one deleted), a custom category, the seeded flag
        foreach (['General', 'Tools', 'Gadgets', 'Kitchen', 'Documents', 'Garden'] as $n) tax_create('category', $n);
        setting_set($this->pdo, 'seeded_defaults', now_utc());
        $before = $this->pdo->query('SELECT id, name, name_key, is_default FROM categories ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $done = migrate($this->pdo, $this->driver, ['seed' => true, 'data_dir' => sys_get_temp_dir()]);
        $this->assertContains('0024', $done, 'the upgrade ran the new migrations');
        $this->assertSame($before, $this->pdo->query('SELECT id, name, name_key, is_default FROM categories ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(0, seed_defaults($this->pdo), 'seeded before: never again');
        $this->assertSame($before, $this->pdo->query('SELECT id, name, name_key, is_default FROM categories ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testUpgradeOfAnUnseededDatabaseAddsNoCategories(): void
    {
        $this->dir = sys_get_temp_dir() . '/apotheke-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        foreach (glob(migrations_dir() . '/*.php') as $f) if (substr(basename($f), 0, 4) <= '0023') copy($f, $this->dir . '/' . basename($f));
        migrate($this->pdo, $this->driver, ['dir' => $this->dir, 'seed' => false, 'data_dir' => sys_get_temp_dir()]);
        migrate($this->pdo, $this->driver, ['seed' => true, 'data_dir' => sys_get_temp_dir()]);
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(), 'SEED_DEFAULTS on at upgrade time: still nothing added');
    }

    public function testNoSeedWhenDisabled(): void
    {
        $this->migrateFresh(['seed' => false]);
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM locations')->fetchColumn());
    }

    public function testSeedDefaultsIsIdempotentAndRespectsExistingDefault(): void
    {
        $this->migrateFresh();
        tax_create('location', 'GARAGE');
        tax_set_default('location', tax_create('location', 'Shed')['id'], true);
        $first = seed_defaults($this->pdo);
        $this->assertSame(15, $first, '"Garage" already existed (case-insensitive)');
        $this->assertSame(0, seed_defaults($this->pdo), 'flagged: no second run');
        $this->assertSame(0, seed_defaults($this->pdo, true), 'forced run creates nothing new');
        $this->assertSame('Shed', $this->pdo->query('SELECT name FROM locations WHERE is_default = 1')->fetchColumn(), 'existing default kept');
    }

    public function testSortKeysBackfilledByMigration(): void
    {
        $dir = $this->dirWith([]);
        migrate($this->pdo, $this->driver, ['dir' => $dir]);
        $this->pdo->exec("INSERT INTO locations (name, name_key, created_at) VALUES ('box 10', 'box 10', '2026-01-01 00:00:00'), ('box 2', 'box 2', '2026-01-01 00:00:00')");
        copy(migrations_dir() . '/0002_sort_keys_and_settings.php', "$dir/0002_sort_keys_and_settings.php");
        migrate($this->pdo, $this->driver, ['dir' => $dir]);
        $this->assertSame(['box 2', 'box 10'], $this->pdo->query('SELECT name FROM locations ORDER BY sort_key')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testExistingPhotoBecomesFirstGalleryPhoto(): void
    {
        $dir = $this->dirWith([]);
        copy(migrations_dir() . '/0002_sort_keys_and_settings.php', "$dir/0002_sort_keys_and_settings.php");
        migrate($this->pdo, $this->driver, ['dir' => $dir]);
        $img = str_repeat('a', 32) . '.jpg';
        $this->pdo->exec("INSERT INTO items (name, quantity, notes, image, search, sort_key, created_at, updated_at) VALUES
            ('with', 1, '', '$img', 'with', 'with', '2026-01-01 00:00:00', '2026-01-02 00:00:00'),
            ('without', 1, '', NULL, 'without', 'without', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        copy(migrations_dir() . '/0003_item_photos.php', "$dir/0003_item_photos.php");
        $this->assertSame(['0003'], migrate($this->pdo, $this->driver, ['dir' => $dir]));
        $rows = $this->pdo->query('SELECT i.name, p.file, p.position FROM item_photos p JOIN items i ON i.id = p.item_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([['name' => 'with', 'file' => $img, 'position' => 0]], array_map(fn($r) => ['name' => $r['name'], 'file' => $r['file'], 'position' => (int)$r['position']], $rows));
    }

    public function testTagsMigrationAddsTablesAndFields(): void
    {
        $this->migrateFresh();
        $cols = $this->driver === 'pgsql'
            ? $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'items' AND table_schema = current_schema()")->fetchAll(PDO::FETCH_COLUMN)
            : array_column($this->pdo->query('PRAGMA table_info(items)')->fetchAll(), 'name');
        foreach (['value_cents', 'purchase_date', 'serial'] as $c) $this->assertContains($c, $cols);
        $this->assertTrue($this->tableExists('tags') && $this->tableExists('item_tags'));
    }
}
