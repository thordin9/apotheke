<?php

declare(strict_types=1);

namespace Tests\Stocktake;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Barcode;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\Text;
use Apotheke\Stocktake\Stocktakes;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Finishing and cancelling a stocktake when another client closes it at
 * the same time (L-240): only one of them gets the open stocktake. The
 * byte-level parity with legacy is tests/Unit/Ci4StocktakeParityTest.php.
 */
final class StocktakesTest extends CIUnitTestCase
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
        parent::tearDown();
    }

    /** A finish that read the stocktake as open while another client cancelled it answers 409 and moves nothing. */
    #[DataProvider('drivers')]
    public function testAFinishAfterAnotherClientsCancelChangesNothing(string $driver): void
    {
        [$takes, $take, $item, $home] = $this->seed($driver);
        $wait = $this->testDb()->holdInAnotherClient([["UPDATE stocktakes SET status = 'cancelled', finished_at = '2026-06-15 10:00:00' WHERE id = ?", [$take]]], 300);
        $this->assertConflict(fn () => $takes->finish($take, true));
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame(['cancelled', '2026-06-15 10:00:00', 0], $this->take($take));
        $this->assertSame($home, $this->locationOf($item), 'not moved');
        $this->assertSame([], $this->actions());
    }

    /** A second finish with moves answers 409: the move and its history entry happen once. */
    #[DataProvider('drivers')]
    public function testTwoFinishesWithMovesMoveOnce(string $driver): void
    {
        [$takes, $take, $item] = $this->seed($driver);
        $other = $this->stocktakes($this->testDb()->connectAgain());
        $this->assertSame(1, $takes->finish($take, true));
        $this->assertConflict(fn () => $other->finish($take, true));
        $this->assertConflict(fn () => $other->cancel($take));
        $this->assertSame(['moved'], $this->actions());
        $this->assertSame('done', $this->take($take)[0]);

        // The other client finished meanwhile: this one waits and then answers 409.
        [$takes, $take, $item, $home] = $this->seed($driver, 'Second');
        $wait = $this->testDb()->holdInAnotherClient([
            ["UPDATE stocktakes SET status = 'done', finished_at = '2026-06-15 10:00:00' WHERE id = ?", [$take]],
        ], 300);
        $this->assertConflict(fn () => $takes->finish($take, true));
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame($home, $this->locationOf($item));
        $this->assertSame(['moved'], $this->actions(), 'nothing logged for the second stocktake');
    }

    /** A cancel after another client's finish answers 409 and keeps the finish. */
    #[DataProvider('drivers')]
    public function testACancelAfterAnotherClientsFinishKeepsTheFinish(string $driver): void
    {
        [$takes, $take] = $this->seed($driver);
        $wait = $this->testDb()->holdInAnotherClient([["UPDATE stocktakes SET status = 'done', finished_at = '2026-06-15 10:00:00', moves_applied = 2 WHERE id = ?", [$take]]], 300);
        $this->assertConflict(fn () => $takes->cancel($take));
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame(['done', '2026-06-15 10:00:00', 2], $this->take($take));
    }

    /**
     * A tick that read the stocktake as open while another client finished
     * it (L-340): it waits for the finish, answers like a finished stocktake
     * and adds, changes or removes no line.
     */
    #[DataProvider('drivers')]
    public function testATickDuringAnotherClientsFinishChangesNoLine(string $driver): void
    {
        [$takes, $take, $misplaced, $home] = $this->seed($driver);
        $expected = $this->itemNamed('First expected');
        $stray = $this->addItem('First stray', $home);
        $before = $this->lineRows($take);
        foreach ([[$stray, true], [$expected, true], [$misplaced, false]] as [$item, $found]) {
            $wait = $this->testDb()->holdInAnotherClient([["UPDATE stocktakes SET status = 'done', finished_at = '2026-06-15 10:00:00' WHERE id = ? AND status = 'open'", [$take]]], 300);
            try {
                $takes->check($take, $item, $found);
                $this->fail('expected a 409');
            } catch (UserError $e) {
                $this->assertSame([409, 'This stocktake is finished.'], [$e->getCode(), $e->getMessage()]);
            }
            $this->assertStringEndsWith("committed\n", $wait());
            $this->assertSame($before, $this->lineRows($take), 'no line added, ticked or removed');
            $this->db()->query("UPDATE stocktakes SET status = 'open', finished_at = NULL WHERE id = ?", [$take]);
        }
        $this->assertSame('found', $takes->check($take, $expected)['state'], 'an open one still ticks');
    }

    /**
     * A tick while another client holds the write lock, finishing another
     * stocktake (N-341): it takes the write lock first, so on SQLite it waits
     * for that commit instead of failing on a read lock it cannot upgrade
     * ("database is locked"), then ticks.
     */
    #[DataProvider('drivers')]
    public function testATickWaitsForAnotherClientsWrite(string $driver): void
    {
        [, $other] = $this->seed($driver, 'Other');
        [$takes, $take, $misplaced, $home] = $this->seed($driver);
        $expected = $this->itemNamed('First expected');
        $stray = $this->addItem('First stray', $home);
        foreach ([[$expected, true, 'found'], [$stray, true, 'misplaced'], [$misplaced, false, 'none']] as [$item, $found, $state]) {
            $wait = $this->testDb()->holdInAnotherClient([["UPDATE stocktakes SET status = 'done', finished_at = '2026-06-15 10:00:00' WHERE id = ?", [$other]]], 300);
            $this->assertSame($state, $takes->check($take, $item, $found)['state']);
            $this->assertStringEndsWith("committed\n", $wait());
            $this->db()->query("UPDATE stocktakes SET status = 'open', finished_at = NULL WHERE id = ?", [$other]);
        }
        $this->assertSame([[$expected, 1], [$stray, 1]], array_map(static fn (array $r): array => [(int)$r['item_id'], (int)$r['found']], $this->lineRows($take)));
    }

    /** A plain finish stores the counts and the moves once; a cancel of an open one cancels it. */
    #[DataProvider('drivers')]
    public function testFinishAndCancel(string $driver): void
    {
        [$takes, $take, $item] = $this->seed($driver);
        putenv('APOTHEKE_CLOCK=2026-06-15 11:00:00');
        try {
            $this->assertSame(1, $takes->finish($take, true));
            $s = $takes->get($take);
            $this->assertNotNull($s);
            $this->assertSame(['done', '2026-06-15 11:00:00', 1, ['expected' => 1, 'found' => 0, 'missing' => 1, 'misplaced' => 1, 'moved' => 1]],
                [$s['status'], $s['finished_at'], $s['moves_applied'], $s['summary']]);
            $this->assertSame($s['location_id'], $this->locationOf($item));
            $this->assertConflict(fn () => $takes->finish($take, false));

            [$takes, $second] = $this->seed($driver, 'Second');
            $takes->cancel($second);
            $this->assertSame(['cancelled', '2026-06-15 11:00:00', 0], $this->take($second));
            $this->assertConflict(fn () => $takes->cancel($second));
            $this->assertConflict(fn () => $takes->finish($second, false));
        } finally {
            putenv('APOTHEKE_CLOCK');
        }
    }

    /**
     * A stocktake of a fresh location with one expected item and one
     * misplaced item (ticked, its home elsewhere).
     *
     * @return array{0:Stocktakes,1:int,2:int,3:int} stocktakes, stocktake id, misplaced item id, its home location
     */
    private function seed(string $driver, string $prefix = 'First'): array
    {
        $this->database ??= TestDatabase::create($driver);
        $db = $this->db();
        $tax = $this->taxonomy($db);
        $here = (int)$tax->create('location', $prefix . ' shelf')['id'];
        $home = (int)$tax->create('location', $prefix . ' home')['id'];
        $add = function (string $name, int $loc) use ($db): int {
            $db->query('INSERT INTO items (name, location_id, quantity, notes, search, sort_key, created_at, updated_at) VALUES (?, ?, 1, \'\', ?, ?, ?, ?)',
                [$name, $loc, mb_strtolower($name), Text::natkey($name), '2026-01-02 08:00:00', '2026-01-02 08:00:00']);

            return (int)$db->insertID();
        };
        $add($prefix . ' expected', $here);
        $item = $add($prefix . ' misplaced', $home);
        $takes = $this->stocktakes($db);
        $take = $takes->start($here);
        $this->assertSame('misplaced', $takes->check($take, $item)['state']);

        return [$takes, $take, $item, $home];
    }

    private function addItem(string $name, int $loc): int
    {
        $this->db()->query('INSERT INTO items (name, location_id, quantity, notes, search, sort_key, created_at, updated_at) VALUES (?, ?, 1, \'\', ?, ?, ?, ?)',
            [$name, $loc, mb_strtolower($name), Text::natkey($name), '2026-01-02 08:00:00', '2026-01-02 08:00:00']);

        return (int)$this->db()->insertID();
    }

    private function itemNamed(string $name): int
    {
        return (int)($this->db()->query('SELECT id FROM items WHERE name = ?', [$name])->getRowArray()['id'] ?? 0);
    }

    /** @return list<array<string,mixed>> the stocktake's lines as stored */
    private function lineRows(int $take): array
    {
        return $this->db()->query('SELECT id, item_id, expected, found, found_at FROM stocktake_lines WHERE stocktake_id = ? ORDER BY id', [$take])->getResultArray();
    }

    /** @param BaseConnection<object|resource, object|resource> $db */
    private function stocktakes(BaseConnection $db): Stocktakes
    {
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);

        return new Stocktakes($db, $clock, $this->taxonomy($db), $this->testDb()->items($db), $history, new Barcode($db));
    }

    /** @param BaseConnection<object|resource, object|resource> $db */
    private function taxonomy(BaseConnection $db): TaxonomyRepository
    {
        $clock = new Clock();

        return new TaxonomyRepository($db, $clock, new ActivityLogger($db, $clock, static fn (): ?string => null));
    }

    private function assertConflict(callable $call): void
    {
        try {
            $call();
            $this->fail('expected a 409');
        } catch (UserError $e) {
            $this->assertSame([409, 'This stocktake is already finished.'], [$e->getCode(), $e->getMessage()]);
        }
    }

    /** @return array{0:string,1:?string,2:int} status, finished_at, moves_applied */
    private function take(int $id): array
    {
        $r = $this->db()->query('SELECT status, finished_at, moves_applied FROM stocktakes WHERE id = ?', [$id])->getRowArray() ?? [];

        return [(string)$r['status'], $r['finished_at'] === null ? null : (string)$r['finished_at'], (int)$r['moves_applied']];
    }

    private function locationOf(int $item): int
    {
        return (int)($this->db()->query('SELECT location_id FROM items WHERE id = ?', [$item])->getRowArray()['location_id'] ?? 0);
    }

    /** @return list<string> history entries for items (not the taxonomy) */
    private function actions(): array
    {
        return array_column($this->db()->query("SELECT action FROM activity WHERE action = 'moved' ORDER BY id")->getResultArray(), 'action');
    }

    private function testDb(): TestDatabase
    {
        $this->assertNotNull($this->database);

        return $this->database;
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        return $this->testDb()->db;
    }
}
