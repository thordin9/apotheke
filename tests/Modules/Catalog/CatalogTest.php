<?php

declare(strict_types=1);

namespace Tests\Catalog;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Calendar;
use Apotheke\Catalog\ItemList;
use Apotheke\Catalog\ListFilters;
use Apotheke\Catalog\SavedFilters;
use Apotheke\Catalog\Stats;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\Text;
use Apotheke\Favourites\Favourites;
use Apotheke\Search\SearchIndex;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Catalog, Search and Favourites on each driver: search (exact, prefix,
 * typo, the index rebuilt when the data changes), the filters and sorts,
 * "today" in the timezone setting, the overview, saved filters, the star
 * and recently viewed, and M-25 (a failed write throws and changes nothing).
 * The byte-level parity with legacy is tests/Unit/Ci4CatalogParityTest.php.
 */
final class CatalogTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

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
        putenv('APOTHEKE_CLOCK');
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function testSearchFindsWordsPrefixesTyposAndFollowsChanges(string $driver): void
    {
        $list = $this->seed($driver);
        $names = fn (string $q): array => array_column($list->search($q, ListFilters::from([]), 'name', 'recent', 1, 50)['items'], 'name');
        $this->assertSame(['Screwdriver set'], $names('screwdriver'));
        $this->assertSame(['Screwdriver set'], $names('screw'));
        $this->assertSame(['Screwdriver set'], $names('screwdrivr'), 'one typo');
        $this->assertSame(['Hammer'], $names('claw'), 'the notes');
        $this->assertSame(['Hammer', 'Saw', 'Screwdriver set'], $names('garage'), 'the location path, sub-locations included');
        $this->assertSame([], $names('zzzz'));
        $hit = $list->search('screwdrivr', ListFilters::from([]), 'name', 'recent', 1, 50);
        $this->assertSame(['screwdrivr' => 'screwdriver'], $hit['fixed']);
        $this->assertStringContainsString('<mark>', (string)$hit['items'][0]['hl']);
        // An edit (it moves updated_at) changes the fingerprint; the next search rebuilds the index.
        $this->db()->query("UPDATE items SET name = 'Wrench', updated_at = '2026-06-15 09:00:00' WHERE name = 'Hammer'");
        $this->assertSame(['Wrench'], $names('wrench'));
        $this->assertSame([], $names('hammer'));
    }

    /**
     * L-1620: the ranking query binds every id of each 500-id chunk as one
     * list. An exact name "Bolt" sits inside each chunk, never at a chunk's
     * first id, and "Hex bolt" repeats the word in its notes, so the
     * engine's own score (FTS5 or ts_rank) alone would not put both "Bolt"s
     * first: only the field weights, applied to every id of every chunk, do.
     */
    #[DataProvider('drivers')]
    public function testRankingScoresEveryIdOfEachChunk(string $driver): void
    {
        $this->seed($driver);
        $special = [250 => 'Bolt', 300 => 'Hex bolt', 550 => 'Bolt', 580 => 'Hex bolt'];
        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $name = $special[$i] ?? sprintf('Part %03d', $i);
            $rows[] = ['name' => $name, 'quantity' => 1, 'favourite' => 0, 'notes' => $name === 'Hex bolt' ? 'bolt bolt bolt bolt' : 'bolt',
                'sort_key' => Text::natkey($name), 'created_at' => '2026-06-01 08:00:00', 'updated_at' => '2026-06-01 08:00:00'];
        }
        $this->assertSame(600, (int)$this->db()->table('items')->insertBatch($rows));
        $ids = fn (string $name): array => array_map('intval', array_column($this->db()->query('SELECT id FROM items WHERE name = ? ORDER BY id', [$name])->getResultArray(), 'id'));
        $hits = (new SearchIndex($this->db(), $this->taxonomy(), true))->match('bolt')['ids'];
        $this->assertCount(600, $hits);
        $top = static function (array $part): array {
            sort($part);

            return $part;
        };
        // both exact names first, then the word-start names, then the notes-only hits
        $this->assertSame($ids('Bolt'), $top(array_slice($hits, 0, 2)));
        $this->assertSame($ids('Hex bolt'), $top(array_slice($hits, 2, 2)));
    }

    /** M-50: SQLite without FTS5 (migration 0019 skips the FTS tables) searches with LIKE, as legacy does. */
    public function testSqliteWithoutFts5SearchesWithLike(): void
    {
        $this->seed('sqlite');
        $this->db()->query('DROP TABLE search_fts_v');
        $this->db()->query('DROP TABLE search_fts');
        $this->db()->query('DELETE FROM search_meta');
        $search = new SearchIndex($this->db(), $this->taxonomy(), true);
        $list = new ItemList($this->db(), $search, $this->calendar());
        $names = fn (string $q): array => array_column($list->search($q, ListFilters::from([]), 'name', 'recent', 1, 50)['items'], 'name');
        $this->assertSame(['Saw'], $names('saw'));
        $this->assertSame(['Screwdriver set'], $names('screwdrivr'), 'one typo');
        $this->assertSame(['Hammer', 'Saw', 'Screwdriver set'], $names('garage'));
        $this->assertSame('like', $search->engine());
        $this->assertNotNull($this->db()->query("SELECT v FROM search_meta WHERE k = 'fingerprint'")->getRowArray(), 'the rebuild is stored');
    }

    #[DataProvider('drivers')]
    public function testFiltersSortsAndPaging(string $driver): void
    {
        $list = $this->seed($driver);
        $names = fn (array $f, string $sort = 'name', int $page = 1, int $size = 50): array => array_column($list->search('', ListFilters::from($f), $sort, 'recent', $page, $size)['items'], 'name');
        $this->assertSame(['Hammer', 'Saw', 'Screwdriver set'], $names(['loc' => '1']), 'a location includes its sub-locations');
        $this->assertSame(['Screwdriver set'], $names(['loc' => '3']));
        $this->assertSame(['Apple', 'Notebook'], $names(['loc' => 'none']));
        $this->assertSame(['Notebook'], $names(['cat' => 'none']));
        $this->assertSame(['Hammer'], $names(['tag' => '1']));
        $this->assertSame(['Apple', 'Milk'], $names(['stock' => 'low']));
        $this->assertSame(['Apple'], $names(['exp' => 'expired']));
        $this->assertSame(['Milk'], $names(['exp' => 'soon']));
        $this->assertSame(['Hammer'], $names(['fav' => '1']));
        $this->assertSame(['Saw', 'Screwdriver set'], $names(['lend' => 'out']));
        $this->assertSame(['Saw'], $names(['lend' => 'overdue']));
        $this->assertSame(['Saw', 'Hammer', 'Screwdriver set', 'Milk', 'Apple', 'Notebook'], $names([], 'value'));
        $this->assertSame(['Notebook', 'Milk', 'Screwdriver set', 'Saw', 'Apple', 'Hammer'], $names([], 'recent'));
        $this->assertSame(['Saw', 'Screwdriver set'], $names([], 'name', 2, 4));
        $res = $list->search('', ListFilters::from([]), 'name', 'recent', 1, 4);
        $this->assertSame([6, true], [$res['total'], $res['more']]);
        $this->assertSame(['items' => 6, 'qty' => 14, 'unassigned' => 2, 'uncategorized' => 1], $list->stats());
        $this->assertSame(1, $list->favouriteCount());
        $this->assertSame(['name' => 'Hammer', 'favourite' => true], $list->find(1));
        $this->assertNull($list->find(99));
        $this->assertSame([1, 1, ItemList::PAGE_MAX], [ItemList::clampPage(0), ItemList::clampPage(-5), ItemList::clampPage(PHP_INT_MAX)]);
    }

    public function testTodayFollowsTheTimezoneSetting(): void
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 22:30:00');
        $athens = new Calendar(new Clock(), 'Europe/Athens', 7);
        $utc = new Calendar(new Clock(), 'UTC', 7);
        $this->assertSame(['2026-06-16', '2026-06-15'], [$athens->today(), $utc->today()]);
        $this->assertSame('2026-06-23', $athens->soonUntil());
        $this->assertSame(['expired', 'soon', '', ''], array_map(static fn (?string $d): string => $athens->expiryState(['expires_on' => $d]),
            ['2026-06-15', '2026-06-23', '2026-06-24', null]));
        $this->assertTrue($athens->isOverdue(['lent_to' => 'Bob', 'lent_due' => '2026-06-15']));
        $this->assertFalse($utc->isOverdue(['lent_to' => 'Bob', 'lent_due' => '2026-06-15']));
        $this->assertSame('UTC', (new Calendar(new Clock(), 'Not/AZone', 7))->zone()->getName(), 'a broken setting keeps UTC');
    }

    #[DataProvider('drivers')]
    public function testTheOverview(string $driver): void
    {
        $this->seed($driver);
        $stats = new Stats($this->db(), $this->taxonomy(), $this->calendar());
        $totals = $stats->totals();
        $this->assertSame(['items' => 6, 'qty' => 14, 'week' => 0, 'month' => 1, 'low' => 2, 'expiring' => 1, 'expired' => 1, 'locations' => 4, 'categories' => 2], $totals);
        $value = $stats->value();
        $this->assertSame([7928, 4], [$value['total'], $value['priced']]);
        $this->assertSame(['Tools', 'Food', 'No category'], array_column($stats->by('category'), 'name'));
        $this->assertTrue($stats->locationsNested());
        $this->assertNotSame([], $stats->addedSeries('month'));
    }

    #[DataProvider('drivers')]
    public function testSavedFilters(string $driver): void
    {
        $this->seed($driver);
        $saved = new SavedFilters($this->db(), new Clock(), 2);
        $id = $saved->create('  Tools   low ', 'cat=1&stock=low');
        $this->assertSame(['id' => $id, 'name' => 'Tools low', 'query' => 'cat=1&stock=low'], array_intersect_key($saved->get($id) ?? [], ['id' => 1, 'name' => 1, 'query' => 1]));
        $this->assertUserError(409, fn () => $saved->create('TOOLS LOW', 'fav=1'));
        $this->assertUserError(422, fn () => $saved->create('Empty', ''));
        $this->assertUserError(422, fn () => $saved->create(' ', 'q=x'));
        $other = $saved->create('Saws', 'q=saw');
        $this->assertUserError(422, fn () => $saved->create('Third', 'q=x'), 'the limit');
        $saved->rename($other, 'A saw');
        $this->assertSame(['A saw', 'Tools low'], array_column($saved->all(), 'name'), 'natural name order');
        $this->assertUserError(404, fn () => $saved->rename(99, 'X'));
        $saved->delete($other);
        $this->assertUserError(404, fn () => $saved->delete($other));
        $this->assertSame('cat=1&stock=low&sort=name', ListFilters::savedQuery(['cat' => '1', 'stock' => 'low', 'sort' => 'name', 'page' => '3', 'select' => '1'], 'recent'));
        $this->assertSame('', ListFilters::savedQuery(['sort' => 'recent'], 'recent'));
    }

    #[DataProvider('drivers')]
    public function testTheStarAndRecentlyViewed(string $driver): void
    {
        $list = $this->seed($driver);
        $device = str_repeat('ab', 16);
        foreach ([[3, 1], [1, 2], [6, 3]] as [$item, $seq]) {
            $this->db()->query("INSERT INTO recent_views (scope, item_id, seq, viewed_at) VALUES ('d:$device', $item, $seq, '2026-06-01 00:00:00')");
        }
        $mine = new Favourites($this->db(), $list, 'device', 8, $device);
        $this->assertSame('d:' . $device, $mine->scope());
        $this->assertSame(['Notebook', 'Hammer', 'Saw'], array_column($mine->items(), 'name'), 'newest first');
        $this->assertSame(['Notebook', 'Hammer'], array_column((new Favourites($this->db(), $list, 'device', 2, $device))->items(), 'name'));
        $this->assertNull((new Favourites($this->db(), $list, 'device', 8, 'not-a-device'))->scope());
        $this->assertSame([], (new Favourites($this->db(), $list, 'device', 8, ['x']))->items());
        $this->assertSame('*', (new Favourites($this->db(), $list, 'install', 8, null))->scope());
        $mine->set(3, true);
        $mine->set(1, false);
        $this->assertSame(1, $mine->count());
        $this->assertSame('2026-03-01 12:00:00', $this->db()->query('SELECT updated_at FROM items WHERE id = 3')->getRowArray()['updated_at'], 'the star keeps updated_at');
        $this->assertUserError(404, fn () => $mine->set(99, true));
        $mine->clearRecent();
        $this->assertSame([], $mine->items());
    }

    /** @return iterable<string,array{0:string,1:string,2:string,3:string}> */
    public static function writeFailures(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ': the star' => [$driver, 'items', 'UPDATE', 'star'];
            yield $driver . ': a new saved filter' => [$driver, 'saved_filters', 'INSERT', 'create'];
            yield $driver . ': a rename' => [$driver, 'saved_filters', 'UPDATE', 'rename'];
            yield $driver . ': a delete' => [$driver, 'saved_filters', 'DELETE', 'delete'];
            yield $driver . ': clearing recently viewed' => [$driver, 'recent_views', 'DELETE', 'clear'];
            yield $driver . ': the search index' => [$driver, 'search_docs', 'INSERT', 'search'];
        }
    }

    #[DataProvider('writeFailures')]
    public function testAFailedWriteThrowsAndChangesNothing(string $driver, string $table, string $op, string $what): void
    {
        $list = $this->seed($driver);
        $saved = new SavedFilters($this->db(), new Clock(), 50);
        $id = $saved->create('Tools', 'cat=1');
        $this->db()->query("INSERT INTO recent_views (scope, item_id, seq, viewed_at) VALUES ('*', 1, 1, '2026-06-01 00:00:00')");
        $favourites = new Favourites($this->db(), $list, 'install', 8, null);
        $before = $this->snapshot();
        $this->failOn($table, $op);
        try {
            match ($what) {
                'star' => $favourites->set(2, true),
                'create' => $saved->create('Food', 'cat=2'),
                'rename' => $saved->rename($id, 'Gear'),
                'delete' => $saved->delete($id),
                'clear' => $favourites->clearRecent(),
                'search' => $list->search('hammer', ListFilters::from([]), 'name', 'recent', 1, 50),
            };
            $this->fail('the failed statement must throw');
        } catch (WriteFailed) {
            $this->assertSame(0, (int)$this->db()->transDepth, 'the transaction is closed');
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function seed(string $driver): ItemList
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 09:00:00');
        $this->database = TestDatabase::create($driver);
        $this->assertFalse((bool)$this->db()->DBDebug);
        $tax = $this->taxonomy();
        $garage = $tax->create('location', 'Garage')['id'];
        $shelf = $tax->createUnder('location', 'Shelf 2', $garage)['id'];
        $box = $tax->createUnder('location', 'Box 10', $shelf)['id'];
        $attic = $tax->create('location', 'Attic')['id'];
        $tools = $tax->create('category', 'Tools')['id'];
        $food = $tax->create('category', 'Food')['id'];
        $rows = [
            ['Hammer', $shelf, $tools, 1, null, null, 1999, null, null, 1, 'claw', '2026-01-02 08:00:00'],
            ['Apple', null, $food, 3, 5, '2026-06-10', null, null, null, 0, '', '2026-02-01 23:30:00'],
            ['Saw', $garage, $tools, 2, null, null, 4550, 'Bob', '2026-06-01', 0, '', '2026-03-01 12:00:00'],
            ['Screwdriver set', $box, $tools, 1, null, null, 1250, 'Ann', '2026-07-01', 0, 'philips', '2026-04-15 00:30:00'],
            ['Milk', $attic, $food, 0, 1, '2026-06-18', 129, null, null, 0, '', '2026-05-20 10:00:00'],
            ['Notebook', null, null, 7, null, '2027-01-01', null, null, null, 0, 'A5 dotted', '2026-06-14 22:30:00'],
        ];
        foreach ($rows as [$name, $loc, $cat, $qty, $min, $exp, $value, $lentTo, $due, $fav, $notes, $at]) {
            $this->assertTrue((bool)$this->db()->table('items')->insert(['name' => $name, 'location_id' => $loc, 'category_id' => $cat, 'quantity' => $qty, 'min_qty' => $min,
                'expires_on' => $exp, 'value_cents' => $value, 'lent_to' => $lentTo, 'lent_at' => $lentTo === null ? null : '2026-05-01', 'lent_due' => $due, 'favourite' => $fav,
                'notes' => $notes, 'sort_key' => Text::natkey($name), 'created_at' => $at, 'updated_at' => $at]));
        }
        $this->db()->query("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('winter', 'winter', 'winter', '2026-01-01')");
        $this->db()->query('INSERT INTO item_tags (item_id, tag_id) VALUES (1, 1)');

        return new ItemList($this->db(), new SearchIndex($this->db(), $tax, true), $this->calendar());
    }

    private function calendar(): Calendar
    {
        return new Calendar(new Clock(), 'UTC', 7);
    }

    private function taxonomy(): TaxonomyRepository
    {
        return new TaxonomyRepository($this->db(), new Clock(), new ActivityLogger($this->db(), new Clock(), static fn (): ?string => null));
    }

    private function assertUserError(int $code, callable $call, string $message = ''): void
    {
        try {
            $call();
            $this->fail('expected a UserError ' . $code);
        } catch (UserError $e) {
            $this->assertSame($code, $e->getCode(), $message . ': ' . $e->getMessage());
        }
    }

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

    /** @return array<string,list<array<string,mixed>>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (['items' => 'id', 'saved_filters' => 'id', 'recent_views' => 'seq', 'search_docs' => 'item_id', 'search_meta' => 'k'] as $table => $order) {
            $out[$table] = $this->db()->table($table)->orderBy($order)->get()->getResultArray();
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
