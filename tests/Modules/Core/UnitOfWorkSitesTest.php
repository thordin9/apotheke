<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Auth\RoleRepository;
use Apotheke\Catalog\Barcode;
use Apotheke\Catalog\ItemList;
use Apotheke\Catalog\SavedFilters;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\SqlExecutor;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Favourites\Favourites;
use Apotheke\Stocktake\Stocktakes;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Support\TestDatabase;

/**
 * The parity gate of P4-2b (ADR-002) for the sites converted in part 1:
 * saved filters (Ref, with guards), recently viewed (batch: the sequence and
 * the prune are subqueries), the role move and reset (batch) and a
 * stocktake's start (Ref). Each runs in both modes, a real transaction and
 * one batch the way D1 runs it (LocalBatchExecutor), and both leave the same
 * rows and ids on SQLite and PostgreSQL. A save that lands between the reads
 * and the batch trips the saved filters' guards.
 */
final class UnitOfWorkSitesTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ' transaction' => [$driver, false];
            yield $driver . ' batch' => [$driver, true];
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        putenv('APOTHEKE_CLOCK');
        parent::tearDown();
    }

    #[DataProvider('modes')]
    public function testTheConvertedSitesInEachMode(string $driver, bool $batch): void
    {
        $out = $this->scenario($driver, $batch);
        $this->assertSame([], $out['uow_refs'], 'no Ref is left behind');
        $this->assertSame([], $out['uow_guard']);
    }

    #[DataProvider('drivers')]
    public function testBothModesLeaveTheSameRowsAndIds(string $driver): void
    {
        $this->assertSame($this->scenario($driver, false), $this->scenario($driver, true));
    }

    /** A filter saved by someone else between the reads and the batch: the guards keep the limit and the name. */
    #[DataProvider('drivers')]
    public function testTheSavedFilterGuardsCatchAConcurrentSave(string $driver): void
    {
        foreach ([[2, 'Mine', 422, 'You can keep up to 2 saved filters. Delete one first.'], [5, 'Theirs', 409, 'A saved filter called “Theirs” already exists.'], [5, 'THEIRS', 409, 'A saved filter called “Theirs” already exists.']] as [$max, $name, $code, $message]) {
            $db = $this->fresh($driver);
            $db->query("INSERT INTO saved_filters (name, name_key, query, sort_key, created_at) VALUES ('First', 'first', 'q=1', 'first', '2026-06-15 09:00:00')");
            $racing = new RacingExecutor(new LocalBatchExecutor($db), "INSERT INTO saved_filters (name, name_key, query, sort_key, created_at) VALUES ('Theirs', 'theirs', 'q=2', 'theirs', '2026-06-15 09:00:00')");
            try {
                (new SavedFilters($db, new Clock(), $max, UnitsOfWork::on($racing)))->create($name, 'q=3');
                $this->fail('the guard must refuse the save');
            } catch (UserError $e) {
                $this->assertSame([$code, $message], [$e->getCode(), $e->getMessage()]);
            }
            $this->assertSame(['First', 'Theirs'], array_column(Writes::rows($db, 'SELECT name FROM saved_filters ORDER BY id'), 'name'));
        }
    }

    /**
     * L-1660: two clients saving at once on a connection. The other client
     * holds the unit's lock (BEGIN IMMEDIATE on SQLite, the unit's advisory
     * lock on PostgreSQL) while it saves; this save waits, then reads its
     * row: the same 409 and 422 as the batch path, never a 500 or limit + 1.
     */
    #[DataProvider('drivers')]
    public function testConcurrentSavesOnAConnectionGetTheSameAnswers(string $driver): void
    {
        foreach ([[5, 'theirs ', 409, 'A saved filter called “Theirs” already exists.'], [2, 'Mine', 422, 'You can keep up to 2 saved filters. Delete one first.']] as [$max, $name, $code, $message]) {
            $db = $this->fresh($driver);
            $db->query("INSERT INTO saved_filters (name, name_key, query, sort_key, created_at) VALUES ('First', 'first', 'q=1', 'first', '2026-06-15 09:00:00')");
            $this->assertNotNull($this->database);
            $lock = $driver === 'pgsql' ? [['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('saved_filters')]] : [];
            $wait = $this->database->holdInAnotherClient([...$lock,
                ["INSERT INTO saved_filters (name, name_key, query, sort_key, created_at) VALUES ('Theirs', 'theirs', 'q=2', 'theirs', '2026-06-15 09:00:00')", []]], 400);
            try {
                (new SavedFilters($db, new Clock(), $max))->create($name, 'q=3');
                $this->fail('the second save must be refused');
            } catch (UserError $e) {
                $this->assertSame([$code, $message], [$e->getCode(), $e->getMessage()]);
            } finally {
                $this->assertStringContainsString('committed', $wait());
            }
            $this->assertSame(['First', 'Theirs'], array_column(Writes::rows($db, 'SELECT name FROM saved_filters ORDER BY id'), 'name'));
        }
    }

    /** N-1663: a first view of an item while another client records the same one waits and moves it up, never a 500 or a shared seq. */
    #[DataProvider('drivers')]
    public function testConcurrentViewsOfOneListOnAConnection(string $driver): void
    {
        $db = $this->fresh($driver);
        $db->query("INSERT INTO items (name, created_at, updated_at) VALUES ('Lamp', '2026-06-15 09:00:00', '2026-06-15 09:00:00')");
        $this->assertNotNull($this->database);
        $lock = $driver === 'pgsql' ? [['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('recent_views', '*')]] : [];
        $wait = $this->database->holdInAnotherClient([...$lock,
            ["INSERT INTO recent_views (scope, item_id, seq, viewed_at) VALUES ('*', 1, 1, '2026-06-15 09:00:00')", []]], 400);
        /** @var ItemList $list */
        $list = (new ReflectionClass(ItemList::class))->newInstanceWithoutConstructor();
        try {
            (new Favourites($db, $list, 'install', 8, null))->record(1);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame([['1', '2']], array_map(static fn (array $r): array => [(string)$r['item_id'], (string)$r['seq']], Writes::rows($db, 'SELECT item_id, seq FROM recent_views')));
    }

    /** N-1663: on PostgreSQL the views of one list are locked per scope, so another device's list does not wait for it. */
    public function testTheRecentViewsLockIsPerScope(): void
    {
        $this->assertNotSame(UnitsOfWork::lockKeys('recent_views', 'd:a'), UnitsOfWork::lockKeys('recent_views', 'd:b'));
        $this->assertSame(UnitsOfWork::lockKeys('recent_views', 'd:a'), UnitsOfWork::lockKeys('recent_views', 'd:a'));
        $this->assertNotSame(UnitsOfWork::lockKeys('saved_filters')[0], UnitsOfWork::lockKeys('recent_views')[0]);
        foreach (UnitsOfWork::lockKeys('recent_views', 'd:' . str_repeat('f', 32)) as $k) {
            $this->assertGreaterThanOrEqual(-2147483648, $k);
            $this->assertLessThanOrEqual(2147483647, $k);
        }
    }

    /**
     * L-1661: recently viewed in each mode. The prune keeps other devices'
     * lists, a repeat view refreshes viewed_at, and the sweep of views older
     * than 90 days runs when the sequence reaches 50, not before.
     */
    #[DataProvider('modes')]
    public function testRecentViewsPruneOneListRefreshAndSweep(string $driver, bool $batch): void
    {
        $db = $this->fresh($driver);
        $units = UnitsOfWork::on($batch ? new LocalBatchExecutor($db) : new ConnectionExecutor($db));
        foreach (['Lamp', 'Saw', 'Box', 'Tent'] as $name) {
            $db->query("INSERT INTO items (name, created_at, updated_at) VALUES (?, '2026-06-15 09:00:00', '2026-06-15 09:00:00')", [$name]);
        }
        /** @var ItemList $list */
        $list = (new ReflectionClass(ItemList::class))->newInstanceWithoutConstructor();
        [$a, $b] = [str_repeat('a', 32), str_repeat('b', 32)];
        $mine = new Favourites($db, $list, 'device', 2, $a, $units);
        $theirs = new Favourites($db, $list, 'device', 2, $b, $units);
        $mine->record(1);
        $mine->record(2);
        $theirs->record(3);
        $theirs->record(4);
        $theirs->record(1);
        $rows = fn (): array => array_map(static fn (array $r): array => [(string)$r['scope'], (int)$r['item_id'], (int)$r['seq'], (string)$r['viewed_at']],
            Writes::rows($db, 'SELECT scope, item_id, seq, viewed_at FROM recent_views ORDER BY scope, seq'));
        $this->assertSame([['d:' . $a, 1, 1, '2026-06-15 09:00:00'], ['d:' . $a, 2, 2, '2026-06-15 09:00:00'],
            ['d:' . $b, 4, 2, '2026-06-15 09:00:00'], ['d:' . $b, 1, 3, '2026-06-15 09:00:00']], $rows(), 'the prune keeps the other list');
        putenv('APOTHEKE_CLOCK=2026-06-15 10:30:00');
        $mine->record(1);
        $this->assertSame([['d:' . $a, 2, 2, '2026-06-15 09:00:00'], ['d:' . $a, 1, 3, '2026-06-15 10:30:00']], array_slice($rows(), 0, 2), 'a repeat view moves up and is refreshed');
        $db->query("INSERT INTO recent_views (scope, item_id, seq, viewed_at) VALUES ('*', 3, 1, '2026-03-01 09:00:00'), ('*', 4, 2, '2026-06-01 09:00:00')");
        for ($seq = 4; $seq < 50; $seq++) {
            $mine->record(2 + $seq % 2);
        }
        $this->assertContains(['*', 3, 1, '2026-03-01 09:00:00'], $rows(), 'no sweep before the sequence reaches 50');
        $mine->record(1);
        $left = $rows();
        $this->assertNotContains(['*', 3, 1, '2026-03-01 09:00:00'], $left, 'the sweep at 50 removes views older than 90 days');
        $this->assertContains(['*', 4, 2, '2026-06-01 09:00:00'], $left, 'and keeps newer ones');
        $this->assertSame([50], array_values(array_filter(array_map(static fn (array $r): int => $r[2], $left), static fn (int $s): bool => $s === 50)));
    }

    public function testASiteRefusesUnitsOfWorkOnAnotherConnection(): void
    {
        $db = $this->fresh('sqlite');
        $other = $this->database?->connectAgain();
        $this->assertNotNull($other);
        $this->assertNotSame($db, $other);
        $units = UnitsOfWork::on(new ConnectionExecutor($db));
        $this->assertSame($units, UnitsOfWork::forSite($db, $units));
        $batch = UnitsOfWork::on(new LocalBatchExecutor($db));
        $this->assertSame($batch, UnitsOfWork::forSite($other, $batch), 'a batch executor has no connection to compare');
        $this->assertNotSame($units, UnitsOfWork::forSite($db, null));
        $this->expectException(LogicException::class);
        new SavedFilters($other, new Clock(), 3, $units);
    }

    /** @return array<string,mixed> */
    private function scenario(string $driver, bool $batch): array
    {
        $db = $this->fresh($driver);
        $units = UnitsOfWork::on($batch ? new LocalBatchExecutor($db) : new ConnectionExecutor($db));
        foreach (['Lamp', 'Saw', 'Box', 'Tent'] as $name) {
            $db->query("INSERT INTO items (name, created_at, updated_at) VALUES (?, '2026-06-15 09:00:00', '2026-06-15 09:00:00')", [$name]);
        }
        $saved = new SavedFilters($db, new Clock(), 3, $units);
        $ids = [$saved->create('Tools', 'cat=1'), $saved->create('Low', 'stock=low')];
        $errors = [];
        foreach ([['tools', 'x=1'], ['Third', 'x=3'], ['Fourth', 'x=4']] as [$n, $q]) {
            try {
                $ids[] = $saved->create($n, $q);
            } catch (UserError $e) {
                $errors[] = $e->getCode();
            }
        }
        /** @var ItemList $list */
        $list = (new ReflectionClass(ItemList::class))->newInstanceWithoutConstructor();
        $recent = new Favourites($db, $list, 'install', 2, null, $units);
        $kept = [];
        foreach ([1, 2, 1, 3, 4, 3] as $item) {
            $recent->record($item);
            $kept[] = count(Writes::rows($db, 'SELECT item_id FROM recent_views'));
        }
        $this->assertSame([1, 2, 2, 2, 2, 2], $kept, 'the list keeps the last 2');
        $db->query("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('helpers', 'Helpers', '[]', 0, '2026-06-15 09:00:00')");
        $db->query("INSERT INTO users (username, username_key, password_hash, role, created_at) VALUES ('h', 'h', 'x', 'helpers', '2026-06-15 09:00:00')");
        $db->query("UPDATE roles SET name = 'Renamed', perms = '[]' WHERE role_key = 'viewer'");
        $roles = new RoleRepository($db, $units);
        $roles->delete('helpers', 'viewer');
        $roles->resetBuiltin();
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $taxonomy = new TaxonomyRepository($db, $clock, $history);
        $shed = $taxonomy->create('location', 'Shed')['id'];
        $shelf = $taxonomy->createUnder('location', 'Shelf', $shed)['id'];
        $db->query('UPDATE items SET location_id = ? WHERE id IN (1, 3)', [$shelf]);
        $db->query('UPDATE items SET location_id = ? WHERE id = 2', [$shed]);
        $this->assertNotNull($this->database);
        $takes = new Stocktakes($db, $clock, $taxonomy, $this->database->items($db), $history, new Barcode($db), $units);
        $ids[] = $takes->start($shed);
        $ids[] = $takes->start($shed);
        $ids[] = $takes->start($shelf);
        $out = ['ids' => $ids, 'errors' => $errors];
        // The built-in roles are seeded with the real time by the migration: their created_at is not compared.
        foreach (['saved_filters' => '*', 'recent_views' => '*', 'roles' => 'id, role_key, name, perms, builtin', 'users' => '*', 'stocktakes' => '*', 'stocktake_lines' => '*', 'uow_refs' => '*', 'uow_guard' => '*'] as $t => $cols) {
            $order = ['recent_views' => 'scope, item_id', 'roles' => 'role_key', 'uow_refs' => '1', 'uow_guard' => '1'][$t] ?? 'id';
            $out[$t] = array_map(static fn (array $r): array => array_map(static fn (mixed $v): ?string => $v === null ? null : (string)$v, $r), Writes::rows($db, "SELECT $cols FROM $t ORDER BY $order"));
        }
        $this->assertSame([1, 2, 3, 1, 1, 2], $ids, 'a second start returns the open stocktake');
        $this->assertSame([['1', 'Lamp', 'Shed › Shelf', (string)$shelf], ['1', 'Saw', 'Shed', (string)$shed], ['1', 'Box', 'Shed › Shelf', (string)$shelf],
            ['2', 'Lamp', 'Shed › Shelf', (string)$shelf], ['2', 'Box', 'Shed › Shelf', (string)$shelf]],
            array_map(static fn (array $r): array => [$r['stocktake_id'], $r['item_name'], $r['home_location'], $r['home_location_id']], $out['stocktake_lines']), 'each line has its item\'s home');
        $this->assertSame(['Shed', 'Shed › Shelf'], array_column($out['stocktakes'], 'location_name'));
        $this->assertSame([409, 422], $errors, 'a taken name, then the limit');
        $this->assertSame([['3', '6'], ['4', '5']], array_map(static fn (array $r): array => [$r['item_id'], $r['seq']], $out['recent_views']), 'the last two, sequence as before');
        $viewer = array_values(array_filter($out['roles'], static fn (array $r): bool => $r['role_key'] === 'viewer'))[0];
        $this->assertSame('Viewer', $viewer['name'], 'the built-in names are back');
        $this->assertNotSame('[]', $viewer['perms'], 'and their permissions');
        $this->assertNotContains('helpers', array_column($out['roles'], 'role_key'));
        $this->assertSame([['h', 'viewer']], array_map(static fn (array $r): array => [$r['username'], $r['role']], $out['users']));
        $this->assertSame(0, (int)$db->transDepth);

        return $out;
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function fresh(string $driver): BaseConnection
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 09:00:00');
        $this->database?->drop();
        $this->database = TestDatabase::create($driver);

        return $this->database->db;
    }
}

/** Another client's write lands once, between the unit's reads and its batch (not again in the failed step's replay). */
final class RacingExecutor implements SqlExecutor
{
    private bool $raced = false;

    public function __construct(private readonly LocalBatchExecutor $inner, private readonly string $race)
    {
    }

    public function run(array $statements): array
    {
        if (!$this->raced) {
            $this->raced = true;
            $this->inner->run([['sql' => $this->race, 'params' => []]]);
        }

        return $this->inner->run($statements);
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->inner->query($sql, $params);
    }

    public function dialect(): Dialect
    {
        return $this->inner->dialect();
    }

    public function connection(): ?BaseConnection
    {
        return null;
    }
}
