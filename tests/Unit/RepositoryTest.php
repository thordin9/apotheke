<?php
declare(strict_types=1);

final class RepositoryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function item(string $name, ?int $loc = null, ?int $cat = null, int $qty = 1, string $notes = ''): int
    {
        return item_save(null, ['name' => $name, 'location_id' => $loc, 'category_id' => $cat, 'quantity' => $qty, 'notes' => $notes], null, false);
    }

    private function photoName(int $n): string { return str_pad((string)$n, 32, '0', STR_PAD_LEFT) . '.jpg'; }

    public function testPhotosCoverReorderDeleteKeepItemsImageInSync(): void
    {
        $id = item_save(null, ['name' => 'p', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $this->photoName(1), false);
        $p2 = photo_add($id, $this->photoName(2));
        $p3 = photo_add($id, $this->photoName(3));
        $files = fn() => array_column(photos_for($id), 'file');
        $this->assertSame([$this->photoName(1), $this->photoName(2), $this->photoName(3)], $files());
        $this->assertSame($this->photoName(1), item_get($id)['image']);
        $this->assertSame(0, photo_action($id, $p3, 'cover'));
        $this->assertSame([$this->photoName(3), $this->photoName(1), $this->photoName(2)], $files());
        $this->assertSame($this->photoName(3), item_get($id)['image']);
        $this->assertSame(2, photo_action($id, $p2, 'right'), 'right at the end stays put');
        $this->assertSame(1, photo_action($id, $p2, 'left'));
        $this->assertSame(0, photo_action($id, $p2, 'left'));
        $this->assertSame($this->photoName(2), item_get($id)['image']);
        $this->assertSame([0, 1, 2], array_column(photos_for($id), 'position'));
        $this->assertSame(-1, photo_action($id, $p2, 'delete'));
        $this->assertSame([$this->photoName(3), $this->photoName(1)], $files());
        $this->assertSame($this->photoName(3), item_get($id)['image']);
        // edit with remove_image drops the cover; next photo is promoted
        $it = item_get($id);
        item_save($it, ['name' => 'p', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, true);
        $this->assertSame([$this->photoName(1)], $files());
        $this->assertSame($this->photoName(1), item_get($id)['image']);
        // edit with a new image replaces the cover
        item_save(item_get($id), ['name' => 'p', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $this->photoName(9), false);
        $this->assertSame([$this->photoName(9)], $files());
    }

    public function testPhotoLimitAndUnknownPhoto(): void
    {
        $id = $this->item('many');
        for ($i = 1; $i <= MAX_PHOTOS; $i++) photo_add($id, $this->photoName($i));
        try { photo_add($id, $this->photoName(99)); $this->fail('limit'); } catch (UserError $e) { $this->assertStringContainsString('at most', $e->getMessage()); }
        $other = $this->item('other');
        $pid = photos_for($id)[0]['id'];
        try { photo_action($other, $pid, 'cover'); $this->fail('foreign photo'); } catch (UserError $e) { $this->assertSame(404, $e->getCode()); }
        item_delete(item_get($id));
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM item_photos')->fetchColumn());
    }

    public function testTagsParseSetSearchAndCleanup(): void
    {
        $this->assertSame(['Garage', 'Ελλάδα', 'b'], tags_parse(' Garage, #garage ;Ελλάδα,, b '));
        $a = item_save(null, ['name' => 'drill', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => ['Garage', 'Power tools']], null, false);
        $b = item_save(null, ['name' => 'saw', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => ['garage'], 'serial' => 'XY-9'], null, false);
        $this->assertSame(['Garage', 'Power tools'], array_column(item_get($a)['tags'], 'name'));
        $this->assertSame(['Garage'], array_column(item_get($b)['tags'], 'name'), 'existing tag reused');
        $garage = item_get($a)['tags'][0]['id'];
        $this->assertSame([2, 1], array_map('intval', array_column(tags_all(), 'n')));
        $this->assertSame(['drill', 'saw'], array_column(items_search('', ['loc' => '', 'cat' => '', 'tag' => (string)$garage], 'name', 1)['items'], 'name'));
        $this->assertSame(['drill'], array_column(items_search('power', ['loc' => '', 'cat' => ''], 'name', 1)['items'], 'name'));
        $this->assertSame(['saw'], array_column(items_search('xy-9', ['loc' => '', 'cat' => ''], 'name', 1)['items'], 'name'));
        item_delete(item_get($a));
        $this->assertSame(['Garage'], array_column(tags_all(), 'name'), 'orphaned tag removed with its last item');
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn());
    }

    public function testMoneyAndDateParsing(): void
    {
        foreach (['' => null, '12' => 1200, '12.5' => 1250, '12,50' => 1250, '1,234.56' => 123456, '1.234,56' => 123456, '1,234.5' => 123450, '€ 9.99' => 999, '1234.5' => 123450] as $in => $cents) {
            $this->assertSame($cents, parse_money((string)$in), (string)$in);
        }
        foreach (['1.2.3', '12.345', '-4', 'abc', '1,234,56'] as $bad) {
            try { parse_money($bad); $this->fail($bad); } catch (UserError) { $this->addToAssertionCount(1); }
        }
        $this->assertSame('1,234.50 EUR', fmt_money(123450, 'EUR'));
        $this->assertSame('1234.50', money_input(123450));
        $this->assertSame('2024-02-29', parse_date_ymd('2024-02-29'));
        $this->assertNull(parse_date_ymd(''));
        foreach (['2023-02-29', '24-01-01', '1800-01-01', date('Y-m-d', time() + 5 * 86400)] as $bad) {
            try { parse_date_ymd($bad); $this->fail($bad); } catch (UserError) { $this->addToAssertionCount(1); }
        }
    }

    public function testTaxonomyCreateIsCaseAndAccentInsensitive(): void
    {
        $a = tax_create('location', '  Κουτί   5 ');
        $this->assertTrue($a['created']);
        $this->assertSame('Κουτί 5', $a['name']);
        $b = tax_create('location', 'κουτι 5');
        $this->assertFalse($b['created']);
        $this->assertSame($a['id'], $b['id']);
    }

    public function testRenameConflictAndSortKey(): void
    {
        $a = tax_create('category', 'Tools')['id'];
        tax_create('category', 'Toys');
        try {
            tax_rename('category', $a, 'TOYS');
            $this->fail('expected conflict');
        } catch (UserError $e) {
            $this->assertSame(409, $e->getCode());
        }
        tax_rename('category', $a, 'Box 10');
        tax_create('category', 'Box 9');
        $this->assertSame(['Box 9', 'Box 10', 'Toys'], array_column(tax_list('category'), 'name'));
    }

    public function testOnlyOneDefault(): void
    {
        $a = tax_create('location', 'A')['id'];
        $b = tax_create('location', 'B')['id'];
        tax_set_default('location', $a, true);
        tax_set_default('location', $b, true);
        $this->assertSame($b, tax_default_id('location'));
        tax_set_default('location', $b, false);
        $this->assertNull(tax_default_id('location'));
    }

    public function testDeleteWithMoveAndUnassign(): void
    {
        $a = tax_create('location', 'A')['id'];
        $b = tax_create('location', 'B')['id'];
        $i1 = $this->item('one', $a);
        $this->item('two', $a);
        $this->assertSame(2, tax_delete('location', $a, $b));
        $this->assertSame($b, (int)item_get($i1)['location_id']);
        $this->assertSame(2, tax_delete('location', $b, null));
        $this->assertNull(item_get($i1)['location_id']);
        $this->assertSame(2, (int)stats()['items']);
    }

    public function testSearchMatchesNameNotesLocationAndCategoryAllTokens(): void
    {
        $loc = tax_create('location', 'Garage shelf')['id'];
        $cat = tax_create('category', 'Ηλεκτρικά')['id'];
        $this->item('Cordless drill', $loc, $cat, 1, 'Makita 18V');
        $this->item('Πρίζα', null, $cat);
        $this->item('Hammer', $loc);
        $names = fn(string $q, array $f = ['loc' => '', 'cat' => '']) => array_column(items_search($q, $f, 'name', 1)['items'], 'name');
        $this->assertSame(['Cordless drill'], $names('makita'));
        $this->assertSame(['Cordless drill', 'Hammer'], $names('GARAGE'));
        $this->assertSame(['Cordless drill', 'Πρίζα'], $names('ηλεκτρικα'));
        $this->assertSame(['Cordless drill'], $names('drill garage'));
        $this->assertSame([], $names('50%'), 'LIKE wildcards are escaped');
        $this->assertSame(['Πρίζα'], $names('', ['loc' => 'none', 'cat' => '']));
        $this->assertSame(['Hammer'], $names('', ['loc' => (string)$loc, 'cat' => 'none']));
    }

    public function testSortOrders(): void
    {
        $l2 = tax_create('location', 'box 2')['id'];
        $l10 = tax_create('location', 'box 10')['id'];
        $this->item('item 10', $l2, null, 5);
        $this->item('item 9', $l10, null, 1);
        $this->item('item 100', null, null, 9);
        $s = fn(string $sort) => array_column(items_search('', ['loc' => '', 'cat' => ''], $sort, 1)['items'], 'name');
        $this->assertSame(['item 9', 'item 10', 'item 100'], $s('name'));
        $this->assertSame(['item 10', 'item 9', 'item 100'], $s('location'), 'natural by location, unassigned last');
        $this->assertSame(['item 100', 'item 10', 'item 9'], $s('qty'));
    }

    public function testQuantityNeverNegative(): void
    {
        $id = $this->item('x', null, null, 2);
        $this->assertSame(5, item_adjust_qty($id, 3));
        $this->assertSame(0, item_adjust_qty($id, -100));
        $this->assertNull(item_adjust_qty(999999, 1));
    }

    public function testStats(): void
    {
        $loc = tax_create('location', 'A')['id'];
        $this->item('x', $loc, null, 3);
        $this->item('y', null, null, 2);
        $s = stats();
        $this->assertSame([2, 5, 1, 2], [(int)$s['items'], (int)$s['qty'], $s['unassigned'], $s['uncategorized']]);
        $t = stats_totals();
        $this->assertSame([2, 5, 2, 2, 1, 0], [$t['items'], $t['qty'], $t['week'], $t['month'], $t['locations'], $t['categories']]);
        $days = stats_added_series('day');
        $this->assertCount(30, $days);
        $this->assertSame(2, end($days)['n'], 'today bucket (local time) has both items');
        $months = stats_added_series('month');
        $this->assertCount(12, $months);
        $this->assertSame(2, end($months)['n']);
        $by = stats_by('location');
        $this->assertSame(['A', 'No location'], array_column($by, 'name'));
    }
}
