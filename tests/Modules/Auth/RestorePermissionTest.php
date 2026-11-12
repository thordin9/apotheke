<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Permissions;
use Apotheke\Auth\RoleAdmin;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\UserRepository;
use Apotheke\Backup\Jobs\RestoreConfirmJob;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Migrations\FreshInstallRoles;
use Apotheke\Migrations\PdoMigrationRunner;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Backup and restore are two permissions: backup downloads (the whole catalog, and the backups kept from before a
 * restore), restore replaces the whole catalog. The roles table keeps "backup" for a role with both, as 2.6.x
 * does, so every role that had backup before the split may restore too.
 */
final class RestorePermissionTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    public function testRestoreIsItsOwnPermission(): void
    {
        $keys = array_keys(Permissions::ALL);
        $this->assertSame(array_search('backup', $keys, true) + 1, array_search('restore', $keys, true));
        $this->assertStringContainsString('whole catalog', Permissions::ALL['backup'][1]);
        $this->assertStringContainsString('whole catalog', Permissions::ALL['restore'][1]);
        $this->assertStringContainsString('for everyone', Permissions::ALL['restore'][1]);
        $this->assertContains('restore', RoleAdmin::NOT_FOR_GUESTS);
        $this->assertSame('restore', Services::jobPermissions()[RestoreConfirmJob::TYPE]);
    }

    /** @return iterable<string,array{list<string>,list<string>}> what a role may => what the roles table keeps */
    public static function storedForms(): iterable
    {
        yield 'both' => [['view', 'backup', 'restore'], ['view', 'backup']];
        yield 'download only' => [['view', 'backup'], ['view', Permissions::STORED_BACKUP_ONLY]];
        yield 'restore only' => [['view', 'restore'], ['view', Permissions::STORED_RESTORE_ONLY]];
        yield 'neither' => [['view', 'export'], ['view', 'export']];
    }

    /** @param list<string> $perms @param list<string> $stored */
    #[DataProvider('storedForms')]
    public function testTheRolesTableKeepsBothAsBackup(array $perms, array $stored): void
    {
        $this->assertSame($stored, Permissions::stored($perms));
        $this->assertSame($perms, Permissions::clean('desk', Permissions::fromStored($stored)));
        // 2.6.x knows only "backup" (download and restore): a role with one of the two has neither there.
        $this->assertSame(in_array('backup', $perms, true) && in_array('restore', $perms, true), in_array('backup', $stored, true));
    }

    /**
     * 2.6.x reads a role's stored list against its own permission names and drops the rest; its "backup" is
     * download and restore. The names here are 2.6.x's (every permission but restore).
     */
    private const LEGACY_PERMS = ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels', 'settings', 'users', 'backup'];

    /**
     * @param list<string> $stored
     * @return list<string> what 2.6.x lets the role do: its "backup" counts as download and restore
     */
    private static function legacyMay(array $stored): array
    {
        $perms = array_values(array_intersect(self::LEGACY_PERMS, $stored));

        return in_array('backup', $perms, true) ? [...$perms, 'restore'] : $perms;
    }

    public function testARollbackNeverGrantsMoreThanBefore(): void
    {
        $this->assertSame(array_values(array_diff(array_keys(Permissions::ALL), ['restore'])), self::LEGACY_PERMS);
        $this->assertNotContains(Permissions::STORED_BACKUP_ONLY, self::LEGACY_PERMS);
        $this->assertNotContains(Permissions::STORED_RESTORE_ONLY, self::LEGACY_PERMS);
        foreach ([[], ['backup'], ['restore'], ['backup', 'restore']] as $extra) {
            $perms = ['view', 'export', ...$extra];
            $stored = Permissions::stored($perms);
            $here = Permissions::clean('desk', Permissions::fromStored($stored));
            $there = self::legacyMay($stored);
            sort($here);
            sort($there);
            $this->assertSame([], array_values(array_diff($there, $here)), implode(',', $extra) . ': 2.6.x grants nothing more');
            $both = $extra === ['backup', 'restore'];
            $this->assertSame($both, in_array('backup', $there, true), implode(',', $extra) . ': 2.6.x backup only for both');
            if ($both) {
                $this->assertSame($here, $there, 'a role with both keeps both after a rollback');
            }
        }
        // A role stored as "backup" (2.6.x, or both here) downloads and restores on either version.
        $this->assertSame(['view', 'backup', 'restore'], Permissions::clean('desk', Permissions::fromStored(['view', 'backup'])));
        $this->assertSame(['view', 'backup', 'restore'], self::legacyMay(['view', 'backup']));
    }

    public function testEveryRoleThatHadBackupMayRestore(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $db = $this->database->db;
        $db->table('roles')->insert(['role_key' => 'keeper', 'name' => 'Keeper', 'builtin' => 0, 'perms' => '["view","backup"]', 'created_at' => '2026-01-01 00:00:00']);
        // An installation from before the split: 2.6.x's built-in roles.
        foreach (['editor', 'viewer'] as $key) {
            $db->table('roles')->where('role_key', $key)->update(['perms' => (string)json_encode(\ROLE_DEFAULTS[$key][1])]);
        }
        $roles = new RoleRepository($db);
        $this->assertTrue($roles->has('keeper', 'backup'));
        $this->assertTrue($roles->has('keeper', 'restore'));
        $this->assertTrue($roles->has('admin', 'restore'));
        foreach (['editor', 'viewer', 'guest'] as $role) {
            $this->assertFalse($roles->has($role, 'restore'), $role . ': as before the split');
        }
        $this->assertSame('["view","backup"]', self::perms($db, 'keeper'), 'the row is not rewritten');
    }

    public function testARoleKeepsDownloadWithoutRestore(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $db = $this->database->db;
        $roles = new RoleRepository($db);
        $roles->create('desk', 'Desk', ['view', 'backup'], '2026-01-01 00:00:00');
        $this->assertTrue((new RoleRepository($db))->has('desk', 'backup'));
        $this->assertFalse((new RoleRepository($db))->has('desk', 'restore'));
        $this->assertSame('["view","' . Permissions::STORED_BACKUP_ONLY . '"]', self::perms($db, 'desk'));
        $roles->update('desk', 'Desk', ['view', 'restore']);
        $this->assertSame(['view', 'restore'], (new RoleRepository($db))->all()['desk']['perms']);
        $roles->update('desk', 'Desk', ['view', 'backup', 'restore']);
        $this->assertSame('["view","backup"]', self::perms($db, 'desk'));
    }

    public function testTheBuiltInDefaults(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $roles = new RoleRepository($this->database->db);
        $roles->resetBuiltin();
        $roles = new RoleRepository($this->database->db);
        $may = static fn (string $r): array => [$roles->has($r, 'backup'), $roles->has($r, 'restore')];
        $this->assertSame([true, true], $may('admin'));
        $this->assertSame([true, true], $may('editor'));
        $this->assertSame([true, false], $may('viewer'));
        $this->assertSame([false, false], $may('guest'));
    }

    public function testAFreshInstallStartsWithTheNewDefaults(): void
    {
        foreach (FreshInstallRoles::PERMS as $key => $stored) {
            $this->assertSame(Permissions::stored(Permissions::ROLE_DEFAULTS[$key][1]), $stored, $key . ': the stored form of ROLE_DEFAULTS');
        }
        $this->database = TestDatabase::create('sqlite');
        $roles = new RoleRepository($this->database->db);
        $may = static fn (string $r): array => [$roles->has($r, 'backup'), $roles->has($r, 'restore')];
        $this->assertSame([true, true], $may('editor'));
        $this->assertSame([true, false], $may('viewer'));
        $this->assertSame([false, false], $may('guest'));
        $this->assertSame([true, true], $may('admin'));
        $this->assertSame((string)json_encode(['view']), self::perms($this->database->db, 'guest'));
        $this->assertSame((string)json_encode(\ROLE_DEFAULTS['admin'][1]), self::perms($this->database->db, 'admin'), 'admin keeps 2.6.x\'s row');
    }

    public function testAnUpgradeKeepsTheRolesItHas(): void
    {
        $this->assertFalse(FreshInstallRoles::applies(false, ['0022', '0035']), 'an install with migrations already');
        $this->assertFalse(FreshInstallRoles::applies(true, ['0029']), 'the roles table was not made in this run');
        $this->assertTrue(FreshInstallRoles::applies(true, ['0001', '0022']));

        // A database migrated up to 0021 gets 0022 later: 2.6.x's defaults, as on 2.6.x.
        $this->database = TestDatabase::create('sqlite', false);
        $pdo = $this->database->pdo();
        $dir = sys_get_temp_dir() . '/apotheke-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $all = (new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->available();
        foreach ($all as $ver => $file) {
            if ($ver < '0022') {
                copy($file, $dir . '/' . basename($file));
            }
        }
        try {
            (new PdoMigrationRunner($dir))->migrate($pdo, 'sqlite');
            $this->assertNotSame([], (new PdoMigrationRunner($dir))->applied($pdo, 'sqlite'));
            (new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->migrate($pdo, 'sqlite');
        } finally {
            foreach ((array)glob($dir . '/*.php') as $f) {
                unlink((string)$f);
            }
            rmdir($dir);
        }
        $roles = new RoleRepository($this->database->db);
        $this->assertFalse($roles->has('editor', 'backup'));
        $this->assertFalse($roles->has('viewer', 'backup'));
        $this->assertSame((string)json_encode(\ROLE_DEFAULTS['viewer'][1]), self::perms($this->database->db, 'viewer'));
    }

    /** The role editor's save keeps download and restore apart. */
    public function testTheRoleEditorSavesBackupAndRestoreApart(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $db = $this->database->db;
        $settings = new SettingsService(new SettingsRepository($db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($this->database->dataDir())), new Clock(),
            $this->database->config->values(), static fn (string $m): null => null, new CommandLine());
        $admin = new RoleAdmin(new RoleRepository($db), new UserRepository($db), $settings, new Clock());
        $key = $admin->create('Desk', ['view']);
        $cases = [
            'neither' => [['view'], '["view"]'],
            'backup' => [['view', 'backup'], '["view","backup-only"]'],
            'restore' => [['view', 'restore'], '["view","restore-only"]'],
            'both' => [['view', 'backup', 'restore'], '["view","backup"]'],
        ];
        foreach (['editor', $key] as $role) {
            foreach ($cases as $label => [$perms, $stored]) {
                $admin->update($role, null, array_reverse($perms));
                $this->assertSame($stored, self::perms($db, $role), $role . ' ' . $label);
                $this->assertSame($perms, (new RoleRepository($db))->all()[$role]['perms'], $role . ' ' . $label);
            }
        }
        // The guest never gets either.
        $admin->update('guest', null, ['view', 'backup', 'restore']);
        $this->assertSame('["view"]', self::perms($db, 'guest'));
    }

    private static function perms(\CodeIgniter\Database\BaseConnection $db, string $key): string
    {
        return (string)($db->table('roles')->select('perms')->where('role_key', $key)->get()->getRowArray()['perms'] ?? '');
    }
}
