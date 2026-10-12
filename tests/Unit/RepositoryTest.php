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
