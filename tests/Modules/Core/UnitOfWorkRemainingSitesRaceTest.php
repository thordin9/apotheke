<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Auth\UserRepository;
use Apotheke\Auth\SessionEpoch;
use Apotheke\BulkMerge\MergeService;
use Apotheke\Catalog\ItemList;
use Apotheke\Search\SearchIndex;
use Apotheke\Stocktake\Stocktakes;
use Apotheke\Catalog\Barcode;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Real parallel races for the remaining sites (M-1752, L-1753, N-1754, M-1764, M-1765, M-1771, N-1776): several
 * processes share one database in batch mode and must leave a correct final
 * quantity, history, and lend state.
 */
final class UnitOfWorkRemainingSitesRaceTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

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

    /**
     * M-1752 / N-1754: six processes each +1 from 10 in batch mode. Final qty
     * is 16 and history has six consecutive from→to rows (no lost update).
     */
    #[DataProvider('drivers')]
    public function testParallelQuantityAdjustInBatchMode(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Lamp', 10, '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
        $answers = $this->parallel(array_fill(0, 6, ['op' => 'adjust', 'batch' => true, 'id' => 1, 'delta' => 1]));
        $ok = array_values(array_filter($answers, static fn (array $a): bool => ($a['ok'] ?? false) === true));
        $this->assertCount(6, $ok, json_encode($answers));
        $this->assertSame(16, (int)Writes::rows($db, 'SELECT quantity FROM items WHERE id = 1')[0]['quantity']);
        $rows = Writes::rows($db, 'SELECT details FROM activity WHERE action = ? ORDER BY id', ['quantity']);
        $this->assertCount(6, $rows);
        $pairs = array_map(static function (array $r): array {
            $d = json_decode((string)$r['details'], true, 8, JSON_THROW_ON_ERROR);

            return [(int)$d['from'], (int)$d['to']];
        }, $rows);
        $this->assertSame([[10, 11], [11, 12], [12, 13], [13, 14], [14, 15], [15, 16]], $pairs);
    }

    /**
     * L-1753 / N-1754: mixed lend, return and +1 on one item in batch mode
     * finishes without a 503; quantity and lend state stay coherent.
     */
    #[DataProvider('drivers')]
    public function testParallelLendReturnAndAdjustInBatchMode(string $driver): void
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 12:00:00');
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Drill', 5, '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
        $jobs = [
            ['op' => 'lend', 'batch' => true, 'id' => 1, 'to' => 'Alex', 'at' => '2026-06-10', 'due' => '2026-06-20'],
            ['op' => 'adjust', 'batch' => true, 'id' => 1, 'delta' => 1],
            ['op' => 'giveBack', 'batch' => true, 'id' => 1],
            ['op' => 'adjust', 'batch' => true, 'id' => 1, 'delta' => 1],
            ['op' => 'lend', 'batch' => true, 'id' => 1, 'to' => 'Bea', 'at' => '2026-06-11', 'due' => null],
            ['op' => 'adjust', 'batch' => true, 'id' => 1, 'delta' => 1],
        ];
        $answers = $this->parallel($jobs);
        foreach ($answers as $a) {
            $this->assertNotSame(503, $a['code'] ?? null, json_encode($answers));
            $this->assertTrue(($a['ok'] ?? false) === true || (int)($a['code'] ?? 0) === 409, json_encode($a));
        }
        $qty = (int)Writes::rows($db, 'SELECT quantity FROM items WHERE id = 1')[0]['quantity'];
        $this->assertSame(8, $qty, 'three +1 from 5');
        $lent = Writes::rows($db, 'SELECT lent_to FROM items WHERE id = 1')[0]['lent_to'];
        $this->assertTrue($lent === null || $lent === 'Alex' || $lent === 'Bea', (string)$lent);
    }

    /**
     * N-1754: createUnder parent-exists guard — parent deleted under the unit
     * answers 422, never a 500.
     */
    #[DataProvider('drivers')]
    public function testCreateUnderParentGuardInBatchMode(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $tax = new TaxonomyRepository($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $parent = $tax->create('location', 'Garage');
        $this->assertTrue($parent['created']);
        $wait = $this->database->holdInAnotherClient([
            ...($driver === 'pgsql' ? [['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('locations')]] : []),
            ['DELETE FROM locations WHERE id = ?', [$parent['id']]],
        ], 400);
        try {
            $tax->createUnder('location', 'Shelf', $parent['id']);
            $this->fail('parent is gone');
        } catch (\Apotheke\Core\Exceptions\UserError $e) {
            $this->assertSame([422, 'That parent location no longer exists.'], [$e->getCode(), $e->getMessage()]);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
    }

    /**
     * N-1756 / N-1757: two creates of the same name; exactly one created:true.
     */
    #[DataProvider('drivers')]
    public function testParallelCreateNameRaceReportsCreated(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $answers = $this->parallel([
            ['op' => 'create', 'batch' => true, 'kind' => 'location', 'name' => 'Shed'],
            ['op' => 'create', 'batch' => true, 'kind' => 'location', 'name' => 'Shed'],
            ['op' => 'create', 'batch' => true, 'kind' => 'location', 'name' => 'Shed'],
        ]);
        $ok = array_values(array_filter($answers, static fn (array $a): bool => ($a['ok'] ?? false) === true));
        $this->assertCount(3, $ok, json_encode($answers));
        $created = array_map(static fn (array $a): bool => (bool)$a['created'], $ok);
        $this->assertSame(1, count(array_filter($created)), json_encode($ok));
        $ids = array_unique(array_map(static fn (array $a): int => (int)$a['id'], $ok));
        $this->assertCount(1, $ids);
    }

    /**
     * N-1756: two createUnder of the same name; exactly one created:true, never a 500.
     */
    #[DataProvider('drivers')]
    public function testParallelCreateUnderNameRace(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $tax = new TaxonomyRepository($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $parent = $tax->create('location', 'Garage');
        $answers = $this->parallel([
            ['op' => 'createUnder', 'batch' => true, 'kind' => 'location', 'name' => 'Shelf', 'parent' => $parent['id']],
            ['op' => 'createUnder', 'batch' => true, 'kind' => 'location', 'name' => 'Shelf', 'parent' => $parent['id']],
            ['op' => 'createUnder', 'batch' => true, 'kind' => 'location', 'name' => 'Shelf', 'parent' => $parent['id']],
        ]);
        $ok = array_values(array_filter($answers, static fn (array $a): bool => ($a['ok'] ?? false) === true));
        $this->assertCount(3, $ok, json_encode($answers));
        $this->assertSame(1, count(array_filter($ok, static fn (array $a): bool => (bool)$a['created'])), json_encode($ok));
        $this->assertCount(1, array_unique(array_map(static fn (array $a): int => (int)$a['id'], $ok)));
        $this->assertSame($parent['id'], (int)Writes::rows($db, 'SELECT parent_id FROM locations WHERE name = ?', ['Shelf'])[0]['parent_id']);
    }


    /**
     * Parallel setDefault(true) on two locations: table-wide unit lock leaves
     * exactly one default (clear-then-set must not race on PostgreSQL).
     */
    #[DataProvider('drivers')]
    public function testParallelSetDefaultLeavesOneDefault(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $tax = new TaxonomyRepository($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $a = $tax->create('location', 'Alpha');
        $b = $tax->create('location', 'Beta');
        $answers = $this->parallel([
            ['op' => 'setDefault', 'batch' => true, 'kind' => 'location', 'id' => $a['id'], 'on' => true],
            ['op' => 'setDefault', 'batch' => true, 'kind' => 'location', 'id' => $b['id'], 'on' => true],
            ['op' => 'setDefault', 'batch' => true, 'kind' => 'location', 'id' => $a['id'], 'on' => true],
            ['op' => 'setDefault', 'batch' => true, 'kind' => 'location', 'id' => $b['id'], 'on' => true],
        ]);
        $ok = array_values(array_filter($answers, static fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertCount(4, $ok, json_encode($answers));
        $defaults = Writes::rows($db, 'SELECT id FROM locations WHERE is_default = 1');
        $this->assertCount(1, $defaults, json_encode($defaults));
        $this->assertContains((int)$defaults[0]['id'], [$a['id'], $b['id']]);
    }


    /**
     * M-1764: parallel demotions of the last two admins in batch and connection
     * mode leave exactly one active admin.
     *
     * @return iterable<string,array{0:string,1:bool}>
     */
    public static function adminRaceModes(): iterable
    {
        foreach (self::drivers() as $driver => [$d]) {
            yield $driver . '-batch' => [$d, true];
            yield $driver . '-conn' => [$d, false];
        }
    }

    #[DataProvider('adminRaceModes')]
    public function testParallelDemoteLastTwoAdminsLeavesOne(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $users = new UserRepository($db, new SessionEpoch(), $units);
        $a = $users->create('ada', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 'admin', 'active', '2026-01-01 00:00:00');
        $b = $users->create('bea', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 'admin', 'active', '2026-01-01 00:00:00');
        $answers = $this->parallel([
            ['op' => 'setRole', 'batch' => $batch, 'id' => $a, 'role' => 'viewer'],
            ['op' => 'setRole', 'batch' => $batch, 'id' => $b, 'role' => 'viewer'],
        ]);
        $ok = array_values(array_filter($answers, static fn (array $r): bool => ($r['ok'] ?? false) === true));
        $fail = array_values(array_filter($answers, static fn (array $r): bool => ($r['ok'] ?? false) !== true));
        $this->assertCount(1, $ok, json_encode($answers));
        $this->assertCount(1, $fail, json_encode($answers));
        $this->assertSame(409, (int)$fail[0]['code']);
        $this->assertSame(1, (int)Writes::rows($db, "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND status = 'active'")[0]['n']);
    }

    #[DataProvider('adminRaceModes')]
    public function testParallelDeleteLastTwoAdminsLeavesOne(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $users = new UserRepository($db, new SessionEpoch(), $units);
        $a = $users->create('ada', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 'admin', 'active', '2026-01-01 00:00:00');
        $b = $users->create('bea', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 'admin', 'active', '2026-01-01 00:00:00');
        $answers = $this->parallel([
            ['op' => 'deleteUser', 'batch' => $batch, 'id' => $a],
            ['op' => 'deleteUser', 'batch' => $batch, 'id' => $b],
        ]);
        $ok = array_values(array_filter($answers, static fn (array $r): bool => ($r['ok'] ?? false) === true));
        $fail = array_values(array_filter($answers, static fn (array $r): bool => ($r['ok'] ?? false) !== true));
        $this->assertCount(1, $ok, json_encode($answers));
        $this->assertCount(1, $fail, json_encode($answers));
        $this->assertSame(409, (int)$fail[0]['code']);
        $this->assertSame(1, (int)Writes::rows($db, "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND status = 'active'")[0]['n']);
    }

    /**
     * M-1765: two crossing setParent moves must not create a cycle.
     */
    #[DataProvider('adminRaceModes')]
    public function testParallelCrossingSetParentDoesNotCycle(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $tax = new TaxonomyRepository($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $a = $tax->create('location', 'A');
        $b = $tax->createUnder('location', 'B', $a['id']);
        $c = $tax->create('location', 'C');
        $d = $tax->createUnder('location', 'D', $c['id']);
        // Cross: put B under D, and D under B.
        $answers = $this->parallel([
            ['op' => 'setParent', 'batch' => $batch, 'id' => $b['id'], 'parent' => $d['id']],
            ['op' => 'setParent', 'batch' => $batch, 'id' => $d['id'], 'parent' => $b['id']],
        ]);
        $ok = array_values(array_filter($answers, static fn (array $r): bool => ($r['ok'] ?? false) === true));
        $this->assertGreaterThanOrEqual(1, count($ok), json_encode($answers));
        $this->assertLessThanOrEqual(1, count($ok), 'at most one crossing move may succeed: ' . json_encode($answers));
        // No cycle: walk parents from B and D with a cap.
        foreach ([$b['id'], $d['id']] as $start) {
            $seen = [];
            $cur = $start;
            for ($i = 0; $i < 20; $i++) {
                $this->assertArrayNotHasKey($cur, $seen, 'cycle at ' . $cur);
                $seen[$cur] = true;
                $row = Writes::rows($db, 'SELECT parent_id FROM locations WHERE id = ?', [$cur])[0] ?? null;
                if ($row === null || $row['parent_id'] === null) {
                    break;
                }
                $cur = (int)$row['parent_id'];
            }
        }
    }

    /**
     * M-1765: parallel nestings cannot push a tree past MAX_DEPTH.
     */
    #[DataProvider('adminRaceModes')]
    public function testParallelSetParentRespectsMaxDepth(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $tax = new TaxonomyRepository($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $ids = [];
        $parent = null;
        for ($i = 0; $i < 7; $i++) {
            $r = $parent === null
                ? $tax->create('location', 'L' . $i)
                : $tax->createUnder('location', 'L' . $i, $parent);
            $ids[] = $r['id'];
            $parent = $r['id'];
        }
        // Tip is at depth 6 (= MAX_DEPTH). Nesting under it would be depth 7.
        $leafA = $tax->create('location', 'ExtraA');
        $leafB = $tax->create('location', 'ExtraB');
        $tip = $ids[6];
        $answers = $this->parallel([
            ['op' => 'setParent', 'batch' => $batch, 'id' => $leafA['id'], 'parent' => $tip],
            ['op' => 'setParent', 'batch' => $batch, 'id' => $leafB['id'], 'parent' => $tip],
        ]);
        foreach ($answers as $a) {
            $this->assertFalse($a['ok'] ?? true, json_encode($answers));
            $this->assertSame(422, (int)$a['code']);
        }
        $this->assertNull(Writes::rows($db, 'SELECT parent_id FROM locations WHERE id = ?', [$leafA['id']])[0]['parent_id']);
        $this->assertNull(Writes::rows($db, 'SELECT parent_id FROM locations WHERE id = ?', [$leafB['id']])[0]['parent_id']);
    }

    /** L-1766: parallel deletes of one location write history once per item. */
    #[DataProvider('adminRaceModes')]
    public function testParallelDeleteLocationHistoryOnce(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $tax = new TaxonomyRepository($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $loc = $tax->create('location', 'Shed');
        $other = $tax->create('location', 'Yard');
        $db->query(
            "INSERT INTO items (name, quantity, location_id, notes, search, created_at, updated_at) VALUES ('Box', 1, ?, '', 'box', '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
            [$loc['id']],
        );
        $answers = $this->parallel([
            ['op' => 'deleteTaxonomy', 'batch' => $batch, 'kind' => 'location', 'id' => $loc['id'], 'moveTo' => $other['id']],
            ['op' => 'deleteTaxonomy', 'batch' => $batch, 'kind' => 'location', 'id' => $loc['id'], 'moveTo' => $other['id']],
        ]);
        $moved = array_sum(array_map(static fn (array $r): int => (int)($r['moved'] ?? 0), $answers));
        $this->assertSame(1, $moved, json_encode($answers));
        $this->assertSame(0, (int)Writes::rows($db, 'SELECT COUNT(*) AS n FROM locations WHERE id = ?', [$loc['id']])[0]['n']);
        $this->assertSame(1, (int)Writes::rows($db, "SELECT COUNT(*) AS n FROM activity WHERE action = 'moved'")[0]['n']);
    }

    /** L-1767: two merges of the same source — one wins. */
    #[DataProvider('adminRaceModes')]
    public function testParallelMergeSameSourceOneWins(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $items = $this->database->items($db, $units, $history);
        $src = $items->save(null, ['name' => 'A', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => []], null, false);
        $t1 = $items->save(null, ['name' => 'B', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => []], null, false);
        $t2 = $items->save(null, ['name' => 'C', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => []], null, false);
        $answers = $this->parallel([
            ['op' => 'merge', 'batch' => $batch, 'source' => $src, 'target' => $t1],
            ['op' => 'merge', 'batch' => $batch, 'source' => $src, 'target' => $t2],
        ]);
        $ok = array_values(array_filter($answers, static fn (array $r): bool => ($r['ok'] ?? false) === true));
        $this->assertCount(1, $ok, json_encode($answers));
        $this->assertNull($items->get($src));
        $alive = array_filter([$items->get($t1), $items->get($t2)]);
        $this->assertCount(2, $alive);
    }

    /** N-1768: parallel ticks of the same unexpected item succeed. */
    #[DataProvider('adminRaceModes')]
    public function testParallelUnexpectedStocktakeTicksSucceed(string $driver, bool $batch): void
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 09:00:00');
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $tax = new TaxonomyRepository($db, $clock, $history, $units);
        $loc = $tax->create('location', 'Room');
        $items = $this->database->items($db, $units, $history);
        $item = $items->save(null, ['name' => 'Stray', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => []], null, false);
        $takes = new Stocktakes($db, $clock, $tax, $items, $history, new Barcode($db), $units);
        $sid = $takes->start($loc['id']);
        $answers = $this->parallel([
            ['op' => 'stocktakeCheck', 'batch' => $batch, 'stocktake' => $sid, 'item' => $item],
            ['op' => 'stocktakeCheck', 'batch' => $batch, 'stocktake' => $sid, 'item' => $item],
        ]);
        $ok = array_values(array_filter($answers, static fn (array $r): bool => ($r['ok'] ?? false) === true));
        $this->assertCount(2, $ok, json_encode($answers));
        $this->assertSame(1, (int)Writes::rows($db, 'SELECT COUNT(*) AS n FROM stocktake_lines WHERE stocktake_id = ? AND item_id = ?', [$sid, $item])[0]['n']);
    }

    /**
     * M-1771: merge + 4 parallel +1 on the target ends at 9 (both keys serialize).
     */
    #[DataProvider('adminRaceModes')]
    public function testMergePlusParallelAdjustsKeepQuantity(string $driver, bool $batch): void
    {
        for ($run = 0; $run < 6; $run++) {
            $this->database?->drop();
            $this->database = TestDatabase::create($driver);
            $db = $this->database->db;
            $units = UnitsOfWork::on(new LocalBatchExecutor($db));
            $clock = new Clock();
            $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
            $items = $this->database->items($db, $units, $history);
            $target = $items->save(null, ['name' => 'T', 'location_id' => null, 'category_id' => null, 'quantity' => 5, 'notes' => '', 'tags' => []], null, false);
            $source = $items->save(null, ['name' => 'S', 'location_id' => null, 'category_id' => null, 'quantity' => 0, 'notes' => '', 'tags' => []], null, false);
            $jobs = [
                ['op' => 'merge', 'batch' => $batch, 'source' => $source, 'target' => $target],
            ];
            for ($i = 0; $i < 4; $i++) {
                $jobs[] = ['op' => 'adjust', 'batch' => $batch, 'id' => $target, 'delta' => 1];
            }
            $answers = $this->parallel($jobs);
            $ok = array_values(array_filter($answers, static fn (array $r): bool => ($r['ok'] ?? false) === true));
            $this->assertGreaterThanOrEqual(5, count($ok), "run $run: " . json_encode($answers));
            $qty = (int)Writes::rows($db, 'SELECT quantity FROM items WHERE id = ?', [$target])[0]['quantity'];
            $this->assertSame(9, $qty, "run $run answers=" . json_encode($answers));
            $this->assertNull($items->get($source));
        }
    }

    /**
     * M-1771 / L-1772 / N-1776 / N-1779: crossing/chained merges keep total 10;
     * failures are 404 or the merge-conflict 409 message.
     */
    #[DataProvider('adminRaceModes')]
    public function testCrossingMergesConserveTotal(string $driver, bool $batch): void
    {
        for ($run = 0; $run < 8; $run++) {
            $this->database?->drop();
            $this->database = TestDatabase::create($driver);
            $db = $this->database->db;
            $units = UnitsOfWork::on(new LocalBatchExecutor($db));
            $clock = new Clock();
            $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
            $items = $this->database->items($db, $units, $history);
            $a = $items->save(null, ['name' => 'A', 'location_id' => null, 'category_id' => null, 'quantity' => 4, 'notes' => '', 'tags' => []], null, false);
            $b = $items->save(null, ['name' => 'B', 'location_id' => null, 'category_id' => null, 'quantity' => 3, 'notes' => '', 'tags' => []], null, false);
            $c = $items->save(null, ['name' => 'C', 'location_id' => null, 'category_id' => null, 'quantity' => 3, 'notes' => '', 'tags' => []], null, false);
            $answers = $this->parallel([
                ['op' => 'merge', 'batch' => $batch, 'source' => $a, 'target' => $b],
                ['op' => 'merge', 'batch' => $batch, 'source' => $a, 'target' => $c],
                ['op' => 'merge', 'batch' => $batch, 'source' => $b, 'target' => $a],
                ['op' => 'merge', 'batch' => $batch, 'source' => $c, 'target' => $b],
                ['op' => 'merge', 'batch' => $batch, 'source' => $b, 'target' => $c],
                ['op' => 'merge', 'batch' => $batch, 'source' => $c, 'target' => $a],
            ]);
            foreach ($answers as $r) {
                if (($r['ok'] ?? false) === true) {
                    continue;
                }
                $code = (int)($r['code'] ?? 0);
                $this->assertContains($code, [404, 409], "run $run: " . json_encode($answers));
                if ($code === 409) {
                    $this->assertSame(
                        'This item was changed by someone else. Try again.',
                        (string)($r['message'] ?? ''),
                        "run $run: " . json_encode($answers),
                    );
                }
            }
            $alive = Writes::rows($db, 'SELECT id, quantity FROM items WHERE id IN (?, ?, ?)', [$a, $b, $c]);
            $total = array_sum(array_map(static fn (array $r): int => (int)$r['quantity'], $alive));
            $this->assertSame(10, $total, "run $run answers=" . json_encode($answers) . ' alive=' . json_encode($alive));
        }
    }

    /**
     * N-1776: +1 on the SOURCE during a merge is kept in the merged total.
     */
    #[DataProvider('adminRaceModes')]
    public function testMergePlusParallelSourceAdjustsKeepQuantity(string $driver, bool $batch): void
    {
        for ($run = 0; $run < 8; $run++) {
            $this->database?->drop();
            $this->database = TestDatabase::create($driver);
            $db = $this->database->db;
            $units = UnitsOfWork::on(new LocalBatchExecutor($db));
            $clock = new Clock();
            $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
            $items = $this->database->items($db, $units, $history);
            $target = $items->save(null, ['name' => 'T', 'location_id' => null, 'category_id' => null, 'quantity' => 5, 'notes' => '', 'tags' => []], null, false);
            $source = $items->save(null, ['name' => 'S', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => []], null, false);
            $answers = $this->parallel([
                ['op' => 'merge', 'batch' => $batch, 'source' => $source, 'target' => $target],
                ['op' => 'adjust', 'batch' => $batch, 'id' => $source, 'delta' => 1],
                ['op' => 'adjust', 'batch' => $batch, 'id' => $source, 'delta' => 1],
            ]);
            $mergeOk = ($answers[0]['ok'] ?? false) === true;
            $this->assertTrue($mergeOk, "run $run merge failed: " . json_encode($answers));
            $sourceAdjusts = 0;
            foreach (array_slice($answers, 1) as $r) {
                if (($r['ok'] ?? false) === true && $r['quantity'] !== null) {
                    $sourceAdjusts++;
                }
            }
            $qty = (int)Writes::rows($db, 'SELECT quantity FROM items WHERE id = ?', [$target])[0]['quantity'];
            $this->assertSame(5 + 1 + $sourceAdjusts, $qty, "run $run answers=" . json_encode($answers));
            $this->assertNull($items->get($source));
        }
    }

    /**
     * L-1773: parallel photo uploads get distinct positions 0,1,2.
     */
    #[DataProvider('adminRaceModes')]
    public function testParallelPhotoAddsGetDistinctPositions(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $items = $this->database->items($db, $units, $history);
        $item = $items->save(null, ['name' => 'Cam', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => []], null, false);
        $files = [
            str_repeat('a', 32) . '.jpg',
            str_repeat('b', 32) . '.jpg',
            str_repeat('c', 32) . '.jpg',
        ];
        $answers = $this->parallel([
            ['op' => 'photoAdd', 'batch' => $batch, 'item' => $item, 'file' => $files[0]],
            ['op' => 'photoAdd', 'batch' => $batch, 'item' => $item, 'file' => $files[1]],
            ['op' => 'photoAdd', 'batch' => $batch, 'item' => $item, 'file' => $files[2]],
        ]);
        $ok = array_values(array_filter($answers, static fn (array $r): bool => ($r['ok'] ?? false) === true));
        $this->assertCount(3, $ok, json_encode($answers));
        $positions = array_map(
            static fn (array $r): int => (int)$r['position'],
            Writes::rows($db, 'SELECT position FROM item_photos WHERE item_id = ? ORDER BY position, id', [$item]),
        );
        $this->assertSame([0, 1, 2], $positions, json_encode($answers));
        $cover = Writes::rows($db, 'SELECT image FROM items WHERE id = ?', [$item])[0]['image'] ?? null;
        $first = Writes::rows($db, 'SELECT file FROM item_photos WHERE item_id = ? ORDER BY position, id LIMIT 1', [$item])[0]['file'] ?? null;
        $this->assertSame($first, $cover);
    }

    /**
     * N-1774 / N-1777 / N-1781: setParent's recursive CTEs terminate on a seeded
     * cycle (n < 50) and refuse the move with a UserError rather than hanging.
     * Moving a node that sits inside the cycle exercises the descendant walk's
     * bound (N-1781); moving an outsider only covered the ancestor walk.
     */
    #[DataProvider('drivers')]
    public function testSetParentTerminatesOnSeededCycle(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $units = UnitsOfWork::on(new LocalBatchExecutor($db));
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $tax = new TaxonomyRepository($db, $clock, $history, $units);
        $a = $tax->create('location', 'CycleA');
        $b = $tax->create('location', 'CycleB');
        // Seed a cycle A→B→A by bypassing guards.
        Writes::check($db->query('UPDATE locations SET parent_id = ? WHERE id = ?', [$b['id'], $a['id']]), 'seed');
        Writes::check($db->query('UPDATE locations SET parent_id = ? WHERE id = ?', [$a['id'], $b['id']]), 'seed');
        $c = $tax->create('location', 'CycleC');
        // Cap the driver so an unbounded CTE cannot hang the suite (N-1777).
        if ($driver === 'pgsql') {
            Writes::check($db->query("SET statement_timeout = '1000'"), 'timeout');
        }
        $started = hrtime(true);
        $err = null;
        try {
            // N-1781: move A (inside the cycle) under C so the descendant walk
            // from A must traverse A→B→A and hit n < 50.
            $tax->setParent($a['id'], $c['id']);
        } catch (\Throwable $e) {
            $err = $e;
        } finally {
            if ($driver === 'pgsql') {
                $db->query('SET statement_timeout = 0');
            }
        }
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        $this->assertInstanceOf(\Apotheke\Core\Exceptions\UserError::class, $err, 'bound cycle walk must surface a UserError, not a driver timeout');
        $this->assertSame(422, $err->getCode());
        $this->assertLessThan(2000, $elapsedMs, 'seeded cycle must terminate quickly');
        $parent = Writes::rows($db, 'SELECT parent_id FROM locations WHERE id = ?', [$a['id']])[0]['parent_id'] ?? null;
        $this->assertSame((int)$b['id'], (int)$parent, 'A must stay under B (cycle unchanged)');
        $n = (int)Writes::rows($db, 'SELECT COUNT(*) AS n FROM locations')[0]['n'];
        $this->assertSame(3, $n);
    }

        /**
     * N-1755: ItemRepository and TagRepository from Services share one UnitsOfWork.
     */
    public function testItemAndTagServicesShareUnitsOfWork(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            Services::injectMock('apothekeDb', $database->db);
            $units = Services::unitsOfWork();
            $items = Services::itemRepository();
            $tags = Services::tags();
            $ref = new \ReflectionClass($items);
            $itemUnits = $ref->getProperty('units');
            $itemUnits->setAccessible(true);
            $tagRef = new \ReflectionClass($tags);
            $tagUnits = $tagRef->getProperty('units');
            $tagUnits->setAccessible(true);
            $this->assertSame($units, $itemUnits->getValue($items));
            $this->assertSame($units, $tagUnits->getValue($tags));
            $this->assertSame($itemUnits->getValue($items), $tagUnits->getValue($tags));
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    /**
     * @param list<array<string,mixed>> $jobs
     * @return list<array<string,mixed>>
     */
    private function parallel(array $jobs): array
    {
        $this->assertNotNull($this->database);
        $env = json_encode($this->database->clientEnv(), JSON_THROW_ON_ERROR);
        $worker = __DIR__ . '/../_support/uow-race-worker.php';
        $procs = [];
        $pipes = [];
        foreach ($jobs as $i => $job) {
            $proc = proc_open(
                [PHP_BINARY, $worker, $env, json_encode($job, JSON_THROW_ON_ERROR)],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipe,
                null,
                $this->database->clientEnv() + ['PATH' => (string)getenv('PATH'), 'APOTHEKE_CLOCK' => (string)(getenv('APOTHEKE_CLOCK') ?: '')],
            );
            $this->assertIsResource($proc, "worker $i");
            $procs[$i] = $proc;
            $pipes[$i] = $pipe;
        }
        $out = [];
        foreach ($procs as $i => $proc) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $code = proc_close($proc);
            // last non-empty line is the JSON result
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$stdout)), static fn (string $l): bool => $l !== ''));
            $json = $lines === [] ? '' : $lines[count($lines) - 1];
            try {
                $out[$i] = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->fail("worker $i exit $code stdout=$stdout stderr=$stderr");
            }
            if ($code !== 0 && ($out[$i]['ok'] ?? false) !== true) {
                // keep the decoded error for the assertion above
            }
        }

        return array_values($out);
    }
}
