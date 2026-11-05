<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\BulkMerge\QuantityAdjust;
use Apotheke\Catalog\Calendar;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Lending\Loans;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * The parity gate of P4-2b for the remaining sites (ADR-002): item save with
 * tags, taxonomy create, quantity adjust and lending. Each runs as a real
 * transaction and as one batch (LocalBatchExecutor), and both leave the same
 * rows and ids, history included, on SQLite and PostgreSQL.
 */
final class UnitOfWorkRemainingSitesTest extends CIUnitTestCase
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
    public function testItemSaveWithTagsInEachMode(string $driver, bool $batch): void
    {
        $out = $this->saveScenario($driver, $batch);
        $this->assertSame([], $out['uow_refs']);
        $this->assertSame([], $out['uow_guard']);
        $this->assertSame(['Lamp', 'garage', 'tools'], [$out['item']['name'], $out['item']['location'], $out['item']['category']]);
        $this->assertSame(['metal', 'red'], array_column($out['item']['tags'], 'name'));
    }

    #[DataProvider('drivers')]
    public function testItemSaveBothModesLeaveTheSameRows(string $driver): void
    {
        $this->assertSame($this->saveScenario($driver, false), $this->saveScenario($driver, true));
    }

    #[DataProvider('modes')]
    public function testTaxonomyCreateInEachMode(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        $tax = new TaxonomyRepository($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $a = $tax->create('location', 'Shed');
        $b = $tax->create('location', 'Shed');
        $this->assertTrue($a['created']);
        $this->assertFalse($b['created']);
        $this->assertSame($a['id'], $b['id']);
        $under = $tax->createUnder('location', 'Shelf', $a['id']);
        $this->assertTrue($under['created']);
        $this->assertSame($a['id'], (int)Writes::rows($db, 'SELECT parent_id FROM locations WHERE id = ?', [$under['id']])[0]['parent_id']);
    }

    #[DataProvider('modes')]
    public function testQuantityAdjustInEachMode(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Lamp', 2, '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
        $adj = new QuantityAdjust($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $this->assertSame(5, $adj->adjust(1, 3));
        $this->assertSame(0, $adj->adjust(1, -99));
        $this->assertNull($adj->adjust(99, 1));
        $this->assertSame([['quantity', '{"from":2,"to":5}'], ['quantity', '{"from":5,"to":0}']],
            array_map(static fn (array $r): array => [(string)$r['action'], (string)$r['details']], Writes::rows($db, 'SELECT action, details FROM activity ORDER BY id')));
    }

    #[DataProvider('modes')]
    public function testLendingInEachMode(string $driver, bool $batch): void
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 12:00:00');
        [$db, $units] = $this->wire($driver, $batch);
        $this->assertNotNull($this->database);
        $items = $this->database->items($db, $units);
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $loans = new Loans($db, $clock, new Calendar($clock, 'UTC', 7), $history, $items, $units);
        $id = $items->save(null, ['name' => 'Drill', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => []], null, false);
        $loans->lend($id, 'Alex', '2026-06-10', '2026-06-20');
        $this->assertSame('Alex', Writes::rows($db, 'SELECT lent_to FROM items WHERE id = ?', [$id])[0]['lent_to']);
        $loans->giveBack($id);
        $this->assertNull(Writes::rows($db, 'SELECT lent_to FROM items WHERE id = ?', [$id])[0]['lent_to']);
        $actions = array_column(Writes::rows($db, 'SELECT action FROM activity WHERE item_id = ? ORDER BY id', [$id]), 'action');
        $this->assertSame(['created', 'lent', 'returned'], $actions);
    }

    /**
     * @return array{item:array<string,mixed>,uow_refs:list<mixed>,uow_guard:list<mixed>}
     */
    private function saveScenario(string $driver, bool $batch): array
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 09:00:00');
        [$db, $units] = $this->wire($driver, $batch);
        $this->assertNotNull($this->database);
        $tax = new TaxonomyRepository($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $loc = $tax->create('location', 'Garage');
        $cat = $tax->create('category', 'Tools');
        $items = $this->database->items($db, $units);
        $id = $items->save(null, [
            'name' => 'Lamp', 'location_id' => $loc['id'], 'category_id' => $cat['id'], 'quantity' => 1, 'notes' => '',
            'tags' => ['red', 'metal'],
        ], null, false);
        $item = $items->get($id) ?? [];
        // Stable shape for the parity compare (drop volatile keys).
        unset($item['created_at'], $item['updated_at']);

        return [
            'item' => $item,
            'tags' => Writes::rows($db, 'SELECT name FROM tags ORDER BY name'),
            'item_tags' => Writes::rows($db, 'SELECT item_id, tag_id FROM item_tags ORDER BY tag_id'),
            'history' => Writes::rows($db, 'SELECT item_id, action, details FROM activity ORDER BY id'),
            'uow_refs' => Writes::rows($db, 'SELECT * FROM uow_refs'),
            'uow_guard' => Writes::rows($db, 'SELECT * FROM uow_guard'),
        ];
    }

    /**
     * @return array{0:BaseConnection<object|resource, object|resource>,1:UnitsOfWork}
     */
    private function wire(string $driver, bool $batch): array
    {
        $this->database?->drop();
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;

        return [$db, UnitsOfWork::on($batch ? new LocalBatchExecutor($db) : new ConnectionExecutor($db))];
    }
}
