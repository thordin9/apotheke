<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

/** Full-text search: documents, ranking, typo tolerance, fallbacks and escaped highlighting. */
final class SearchTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
        search_engine('');
        search_trgm(null, true);
    }

    protected function tearDown(): void
    {
        search_engine('');
        search_trgm(null, true);
        parent::tearDown();
    }

    private function item(string $name, array $in = []): int
    {
        return item_save(null, $in + ['name' => $name, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
    }

    private function names(string $q): array
    {
        return array_column(items_search($q, [], default_sort(), 1)['items'], 'name');
    }

    public function testEngineAndRanking(): void
    {
        $this->assertSame(db_driver() === 'pgsql' ? 'pg' : 'fts5', search_engine());
        $loc = tax_create('location', 'Attic')['id'];
        $this->item('Tent', ['notes' => 'camping gear', 'location_id' => $loc]);
        $this->item('Camping stove');
        $this->item('Lamp', ['tags' => ['camping']]);
        $this->assertSame(['Camping stove', 'Lamp', 'Tent'], $this->names('camping'), 'name > tags > notes');
        $this->assertSame(['Tent'], $this->names('attic gear'));
        $this->assertSame(['Camping stove'], $this->names('stov'), 'prefix');
        $this->assertSame(['Camping stove'], $this->names('amping st'), 'substring');
    }

    public function testTyposAndSwitch(): void
    {
        $this->item('Screwdriver set');
        $this->item('Πρίζα πολύπριζο');
        $m = search_match('screwdrivr');
        $this->assertCount(1, $m['ids']);
        $this->assertSame(['screwdrivr' => 'screwdriver'], $m['fixed']);
        $this->assertCount(1, search_match('πολυπριζα')['ids'], 'Greek, accents folded, one letter off');
        $this->assertSame([], search_match('scr3wdr1v3r')['ids'], 'too many typos');
        $this->assertSame([], search_match('sex')['ids'], 'short words need an exact match');
        settings_save(['search_typos' => '0']);
        settings_cache(null, true);
        $this->assertSame([], search_match('screwdrivr')['ids']);
    }

    public function testFallbackWithoutExtensions(): void
    {
        $this->item('Garden hose', ['notes' => 'green, 25 m']);
        search_engine('like');
        search_trgm(false);
        $this->assertSame(['Garden hose'], $this->names('hose'));
        $this->assertSame(['Garden hose'], $this->names('gardn'), 'typos via the PHP word list');
        $this->assertSame([], $this->names('lawnmower'));
    }

    public function testIndexFollowsChanges(): void
    {
        $id = $this->item('Kettle');
        $this->assertSame(['Kettle'], $this->names('kettle'));
        $loc = tax_create('location', 'Kitchen')['id'];
        item_save(item_get($id), ['name' => 'Kettle', 'location_id' => $loc, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'barcode' => '5012345678900'], null, false);
        $this->assertSame(['Kettle'], $this->names('kitchen'));
        $this->assertSame(['Kettle'], $this->names('5012345678900'));
        tax_rename('location', $loc, 'Scullery');
        $this->assertSame(['Kettle'], $this->names('scullery'));
        $this->assertSame([], $this->names('kitchen'));
    }

    public function testHighlightEscapes(): void
    {
        $this->assertSame('<mark>Ελλη</mark>νικά &lt;b&gt;', search_highlight('Ελληνικά <b>', ['ελλη']));
        $this->assertSame('a &amp; <mark>B</mark>', search_highlight('a & B', ['b']));
        $this->assertSame('Stra<mark>ße</mark>', search_highlight('Straße', ['sse']), 'one character folding to two');
        $this->assertSame('&lt;script&gt;', search_highlight('<script>', []));
        $this->assertSame(3, search_lev('kitten', 'sitting'));
        $this->assertSame(1, search_lev('πρίζα', 'πρίζε'));
    }
}
