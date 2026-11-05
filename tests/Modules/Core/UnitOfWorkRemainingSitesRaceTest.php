<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Real parallel races for the remaining sites (M-1752, L-1753, N-1754): several
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
