<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\SessionEpoch;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Database\D1\Connection;
use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\ReadCache;
use Apotheke\Core\Database\RequestReads;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\WriteClock;
use Apotheke\Core\Database\Writes;
use Apotheke\Migrations\D1Bootstrap;
use Apotheke\Migrations\D1MigrationRunner;
use Apotheke\Migrations\PdoMigrationRunner;
use Apotheke\Settings\SettingsRepository;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use Config\WorkerMode;
use Tests\Support\TestDatabase;

require_once __DIR__ . '/D1DriverTest.php';

/**
 * The request's ReadCache on the D1 driver: a read is remembered until a write to one of its tables (the first
 * account, saved settings, a role, a password, role or session epoch change), never inside a transaction and never
 * from one request to the next; on SQLite and PostgreSQL nothing is remembered.
 */
final class ReadCacheTest extends CIUnitTestCase
{
    private FakeD1Bridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new FakeD1Bridge();
        (new D1Bootstrap(new D1HttpExecutor('http://d1.internal', $this->bridge),
            new D1MigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations')))->migrate();
        $this->bridge->calls = [];
    }

    private function db(): Connection
    {
        $db = Database::connect(['DBDriver' => 'Apotheke\\Core\\Database\\D1', 'hostname' => 'http://d1.internal', 'database' => '',
            'DBDebug' => false, 'transport' => $this->bridge], false);
        $this->assertInstanceOf(Connection::class, $db);

        return $db;
    }

    private function trips(): int
    {
        return count($this->bridge->calls);
    }

    public function testAccountsAreReadAgainAfterTheFirstAccountAndEachChange(): void
    {
        $db = $this->db();
        $cache = new ReadCache();
        $units = UnitsOfWork::forSite($db, null);
        $settings = new SettingsRepository($db, $units, $cache);
        $users = new UserRepository($db, new SessionEpoch(), $units, $cache);

        $this->assertSame(0, $settings->userCount());
        $trips = $this->trips();
        $this->assertSame(0, $users->count(), 'the same count, remembered');
        $this->assertSame(0, $settings->userCount());
        $this->assertSame($trips, $this->trips());

        $id = $users->create('first', password_hash('a password here', PASSWORD_DEFAULT), 'admin', 'active', '2026-01-01 00:00:00');
        $this->assertSame(1, $settings->userCount(), 'the first account is counted in the same request');
        $this->assertSame(1, $users->count());

        $row = $users->find($id);
        $this->assertSame('admin', $row['role'] ?? null);
        $trips = $this->trips();
        $this->assertSame($row, $users->find($id));
        $this->assertSame($trips, $this->trips(), 'remembered');

        $users->create('second', password_hash('a password here', PASSWORD_DEFAULT), 'admin', 'active', '2026-01-01 00:00:00');
        $users->setRole($id, 'viewer');
        $this->assertSame('viewer', $users->find($id)['role'] ?? null, 'a role change is read back');
        $epoch = $users->renewSessionEpoch($id);
        $this->assertSame($epoch, $users->find($id)['session_epoch'] ?? null, 'a new session epoch is read back');
        $hash = password_hash('another password', PASSWORD_DEFAULT);
        $epoch = $users->setPasswordHash($id, $hash);
        $this->assertSame([$hash, $epoch], [$users->find($id)['password_hash'] ?? null, $users->find($id)['session_epoch'] ?? null], 'a new password');
        $this->assertSame(2, $settings->userCount());
    }

    public function testSettingsAndRolesAreReadAgainAfterAWrite(): void
    {
        $db = $this->db();
        $cache = new ReadCache();
        $units = UnitsOfWork::forSite($db, null);
        $settings = new SettingsRepository($db, $units, $cache);
        $roles = new RoleRepository($db, $units, $cache);

        $before = $settings->rows();
        $trips = $this->trips();
        $this->assertSame($before, $settings->rows());
        $this->assertSame($trips, $this->trips(), 'remembered');
        $settings->replace(['app_name' => (string)json_encode('Renamed')], '2026-01-01 00:00:00');
        $this->assertSame('"Renamed"', $settings->rows()['app_name'] ?? null, 'saved settings are read back');

        $all = $roles->all();
        $this->assertArrayHasKey('viewer', $all);
        $trips = $this->trips();
        $this->assertNotNull($settings->accountRoles());
        $this->assertSame($trips, $this->trips(), 'one read of the roles for both');
        $roles->create('helper', 'Helper', ['view'], '2026-01-01 00:00:00');
        $this->assertArrayHasKey('helper', $roles->all(), 'a new role is read back');
        $roles->update('helper', 'Helper', ['view', 'edit']);
        $this->assertTrue($roles->has('helper', 'edit'), 'a role change is read back');
    }

    public function testTheRequestsFirstBatchFillsTheCacheAndAWriteStillWins(): void
    {
        $db = $this->db();
        $cache = new ReadCache();
        $versions = RequestReads::prefetch($db, $cache, true);
        $this->assertNotEmpty($versions);
        $this->assertSame(1, $this->bridge->count('/batch'));
        $this->assertSame(1, $this->trips());
        $settings = new SettingsRepository($db, UnitsOfWork::forSite($db, null), $cache);
        $settings->rows();
        $settings->accountRoles();
        $settings->userCount();
        $this->assertSame(1, $this->trips(), 'all three from the batch');

        $this->assertArrayHasKey('app_name', $settings->rows());
        $this->assertTrue((bool)$db->query("UPDATE settings SET value = '\"Other\"' WHERE name = 'app_name'"));
        $this->assertSame('"Other"', $settings->rows()['app_name'] ?? null, 'a direct write is read back');
    }

    public function testNothingIsRememberedInsideATransactionOrWhenOff(): void
    {
        $db = $this->db();
        $cache = new ReadCache();
        $settings = new SettingsRepository($db, UnitsOfWork::forSite($db, null), $cache);
        $db->transBegin();
        try {
            $settings->rows();
            $settings->rows();
            $this->assertSame(2, $this->bridge->count('/query'));
        } finally {
            $db->transRollback();
        }
        $this->assertFalse($cache->has($db, RequestReads::SETTINGS, ['settings']));

        $off = new SettingsRepository($db, UnitsOfWork::forSite($db, null), ReadCache::off());
        $off->rows();
        $off->rows();
        $this->assertSame(4, $this->bridge->count('/query'));
    }

    public function testAFailedReadIsNotRemembered(): void
    {
        $db = $this->db();
        $cache = new ReadCache();
        $settings = new SettingsRepository($db, UnitsOfWork::forSite($db, null), $cache);
        $this->bridge->fault = static fn (string $path): ?string => '{"ok":false,"code":"write_failed","error":"the batch"}';
        $this->assertSame([], $settings->rows());
        $this->assertSame(0, $settings->userCount());
        $this->bridge->fault = null;
        $this->assertNotSame([], $settings->rows(), 'read again once the database answers');
    }

    public function testEachRequestGetsAnEmptyCache(): void
    {
        $this->assertNotContains('readCache', (new WorkerMode())->persistentServices);
        $first = Services::readCache();
        $this->assertSame($first, Services::readCache());
        Services::resetForWorkerMode(new WorkerMode());
        $this->assertNotSame($first, Services::readCache());
        Services::reset();
    }

    public function testSqliteAndPostgresqlReadEveryTime(): void
    {
        foreach (TestDatabase::drivers() as $driver) {
            if ($driver === 'd1') {
                continue;
            }
            $t = TestDatabase::create($driver);
            try {
                $n = 0;
                $count = static function () use (&$n): void {
                    $n++;
                };
                Events::on('DBQuery', $count);
                try {
                    $cache = new ReadCache();
                    $settings = new SettingsRepository($t->db, null, $cache);
                    $settings->rows();
                    $settings->rows();
                    $this->assertSame(2, $n, $driver);
                    $this->assertFalse($cache->has($t->db, RequestReads::SETTINGS, ['settings']), $driver);
                } finally {
                    Events::removeListener('DBQuery', $count);
                }
            } finally {
                $t->drop();
            }
        }
    }

    public function testTheClockCountsWritesPerTable(): void
    {
        $this->assertSame('users', WriteClock::table('UPDATE "users" SET role = ?'));
        $this->assertSame('items', WriteClock::table('INSERT OR REPLACE INTO main.`items` (id) VALUES (1)'));
        $this->assertSame('settings', WriteClock::table(' delete from settings where 1'));
        $this->assertSame('roles', WriteClock::table('REPLACE INTO roles VALUES (1)'));
        $this->assertNull(WriteClock::table('WITH x AS (SELECT 1) DELETE FROM items'));
        $clock = new WriteClock();
        $start = $clock->stamp(['users', 'items']);
        $clock->wrote('SELECT * FROM users');
        $this->assertSame($start, $clock->stamp(['users', 'items']), 'a read is no write');
        $clock->wrote('UPDATE users SET role = 1');
        $this->assertNotSame($start, $clock->stamp(['users']));
        $this->assertSame($start['items'], $clock->stamp(['items'])['items'], 'another table keeps its count');
        $before = $clock->stamp(['items']);
        $clock->wrote('CREATE TABLE z (a)');
        $this->assertNotSame($before, $clock->stamp(['items']), 'anything else counts against every table');
        $before = $clock->stamp(['items']);
        $clock->wroteEverything();
        $this->assertNotSame($before, $clock->stamp(['items']));
    }

    public function testRowsManyFallsBackOnTheD1DriverWhenABatchCannotHelp(): void
    {
        $db = $this->db();
        $this->assertSame([[['n' => 1]]], Writes::rowsMany($db, [['SELECT 1 AS n']]));
        $this->assertSame([1, 0], [$this->bridge->count('/query'), $this->bridge->count('/batch')], 'one read: no batch');
    }
}
