<?php

declare(strict_types=1);

namespace Tests\Taxonomy;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * M-25: with DBDebug off (the shipped connection) a failed statement returns
 * false instead of throwing. Every Taxonomy and Activity write must still
 * throw and roll back, as legacy's PDO exceptions do (the request answers
 * 500). A trigger makes one statement fail; nothing may change.
 */
final class WriteFailureTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** The table carrying the PostgreSQL test trigger. */
    private string $failing = '';

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
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function testAFailedAddChangesNothing(string $driver): void
    {
        [$repo] = $this->wire($driver);
        $garage = $repo->create('location', 'Garage')['id'];
        $before = $this->snapshot();
        $this->failOn('locations', 'INSERT');
        $this->assertFails(fn () => $repo->create('location', 'Box'));
        $this->assertFails(fn () => $repo->createUnder('location', 'Box', $garage));
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('drivers')]
    public function testAFailedRenameOrDefaultChangesNothing(string $driver): void
    {
        [$repo] = $this->wire($driver);
        $tools = $repo->create('category', 'Tools')['id'];
        $food = $repo->create('category', 'Food')['id'];
        $repo->setDefault('category', $tools, true);
        $before = $this->snapshot();
        $this->failOn('categories', 'UPDATE');
        $this->assertFails(fn () => $repo->rename('category', $tools, 'Gear'));
        $this->assertFails(fn () => $repo->setDefault('category', $food, true));
        $this->assertFails(fn () => $repo->setDefault('category', $tools, false));
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('drivers')]
    public function testAFailedMoveChangesNothing(string $driver): void
    {
        [$repo] = $this->wire($driver);
        $garage = $repo->create('location', 'Garage')['id'];
        $box = $repo->create('location', 'Box')['id'];
        $before = $this->snapshot();
        $this->failOn('locations', 'UPDATE');
        $this->assertFails(fn () => $repo->setParent($box, $garage));
        $this->assertSame($before, $this->snapshot());
    }

    /** @return iterable<string,array{0:string,1:string,2:string}> */
    public static function deleteFailures(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ': the item update' => [$driver, 'items', 'UPDATE'];
            yield $driver . ': the history insert' => [$driver, 'activity', 'INSERT'];
            yield $driver . ': the sub-location update' => [$driver, 'locations', 'UPDATE'];
            yield $driver . ': the delete' => [$driver, 'locations', 'DELETE'];
        }
    }

    #[DataProvider('deleteFailures')]
    public function testAFailedDeleteWithMoveChangesNothing(string $driver, string $table, string $op): void
    {
        [$repo] = $this->wire($driver);
        $garage = $repo->create('location', 'Garage')['id'];
        $shelf = $repo->createUnder('location', 'Shelf', $garage)['id'];
        $repo->createUnder('location', 'Box', $shelf);
        $attic = $repo->create('location', 'Attic')['id'];
        $this->item('Hammer', $shelf);
        $this->item('Saw', $shelf);
        $before = $this->snapshot();
        $this->failOn($table, $op);
        $this->assertFails(fn () => $repo->delete('location', $shelf, $attic));
        $this->assertSame($before, $this->snapshot());
        // The failure is cleared: the next write on this connection works.
        $this->dropFailure();
        $this->assertSame(2, $repo->delete('location', $shelf, $attic));
        $this->assertCount(2, $this->db()->table('activity')->get()->getResultArray());
    }

    #[DataProvider('drivers')]
    public function testAFailedHistoryInsertThrows(string $driver): void
    {
        [, $log] = $this->wire($driver);
        $this->failOn('activity', 'INSERT');
        $this->assertFails(fn () => $log->log(1, 'Hammer', 'moved'));
        $this->assertSame(0, $this->db()->table('activity')->countAllResults());
    }

    /** @return iterable<string,array{0:string,1:string,2:string}> */
    public static function tagFailures(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ': a new tag' => [$driver, 'tags', 'INSERT'];
            yield $driver . ': the item link' => [$driver, 'item_tags', 'INSERT'];
            yield $driver . ': clearing the links' => [$driver, 'item_tags', 'DELETE'];
            yield $driver . ': dropping unused tags' => [$driver, 'tags', 'DELETE'];
        }
    }

    #[DataProvider('tagFailures')]
    public function testAFailedTagSetChangesNothing(string $driver, string $table, string $op): void
    {
        $this->wire($driver);
        $tags = new TagRepository($this->db(), new Clock());
        $a = $this->item('A', null);
        $tags->set($a, ['red', 'old']);
        $before = $this->snapshot();
        $this->failOn($table, $op);
        $this->assertFails(fn () => $tags->set($a, ['red', 'blue']));
        $this->assertSame($before, $this->snapshot());
    }

    /** The transaction itself also catches a failed statement nobody checked, and rolls back the rest. */
    #[DataProvider('drivers')]
    public function testATransactionWithAnUncheckedFailureRollsBack(string $driver): void
    {
        $this->wire($driver);
        $before = $this->snapshot();
        $this->failOn('categories', 'INSERT');
        $this->assertFails(fn () => Writes::transaction($this->db(), function (): void {
            $this->db()->table('locations')->insert(['name' => 'Garage', 'name_key' => 'garage', 'sort_key' => 'garage', 'created_at' => '2026-01-01 00:00:00']);
            $this->db()->table('categories')->insert(['name' => 'Tools', 'name_key' => 'tools', 'sort_key' => 'tools', 'created_at' => '2026-01-01 00:00:00']);
        }));
        $this->assertSame($before, $this->snapshot());
    }

    /** A failed SELECT inside a write path throws too, instead of reading as "no rows". */
    #[DataProvider('drivers')]
    public function testAFailedReadThrows(string $driver): void
    {
        $this->wire($driver);
        $this->expectException(WriteFailed::class);
        Writes::rows($this->db(), 'SELECT nope FROM no_such_table');
    }

    /** @return array{0:TaxonomyRepository,1:ActivityLogger} */
    private function wire(string $driver): array
    {
        $this->database = TestDatabase::create($driver);
        $this->assertFalse((bool)$this->db()->DBDebug, 'the shipped connection has DBDebug off');
        $log = new ActivityLogger($this->db(), new Clock(), static fn (): ?string => 'ada');

        return [new TaxonomyRepository($this->db(), new Clock(), $log), $log];
    }

    private function assertFails(callable $write): void
    {
        try {
            $write();
            $this->fail('the failed statement must throw');
        } catch (WriteFailed) {
            $this->assertSame(0, (int)$this->db()->transDepth, 'the transaction is closed');
        }
    }

    /** Make every $op on $table fail, as a broken disk or constraint would. */
    private function failOn(string $table, string $op): void
    {
        if ($this->db()->DBDriver === 'Postgre') {
            $this->db()->query("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
            $this->db()->query("CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()");
            $this->failing = $table;

            return;
        }
        $this->db()->query("CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    }

    private function dropFailure(): void
    {
        $this->db()->query($this->db()->DBDriver === 'Postgre' ? 'DROP TRIGGER apotheke_test_fail ON ' . $this->failing : 'DROP TRIGGER apotheke_test_fail');
    }

    private function item(string $name, ?int $location): int
    {
        $this->db()->table('items')->insert(['name' => $name, 'location_id' => $location, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);

        return (int)$this->db()->insertID();
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (['locations', 'categories', 'items', 'activity', 'tags', 'item_tags'] as $table) {
            $out[$table] = $this->db()->table($table)->orderBy($table === 'item_tags' ? 'tag_id' : 'id')->get()->getResultArray();
        }

        return $out;
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        $this->assertNotNull($this->database);

        return $this->database->db;
    }
}
