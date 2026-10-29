<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

final class NestedLocationsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function loc(string $name, ?int $parent = null): int
    {
        $id = tax_create('location', $name)['id'];
        if ($parent !== null) location_set_parent($id, $parent);
        return $id;
    }

    private function item(string $name, ?int $loc): int
    {
        return item_save(null, ['name' => $name, 'location_id' => $loc, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
    }

    public function testTreeOrderDepthPathAndTotals(): void
    {
        $g = $this->loc('Garage');
        $a = $this->loc('Attic');
        $s2 = $this->loc('Shelf 2', $g);
        $s10 = $this->loc('Shelf 10', $g);
        $b = $this->loc('Box 1024', $s2);
        $this->item('Drill', $b);
        $this->item('Broom', $g);
        $this->item('Lamp', $a);
        $list = tax_list('location');
        $this->assertSame(['Attic', 'Garage', 'Shelf 2', 'Box 1024', 'Shelf 10'], array_column($list, 'name'), 'parents first, children in natural order');
        $this->assertSame([0, 0, 1, 2, 1], array_column($list, 'depth'));
        $this->assertSame([null, null, $g, $s2, $g], array_column($list, 'parent_id'));
        $this->assertSame('Garage › Shelf 2 › Box 1024', location_path($b));
        $this->assertSame([1, 1, 0, 1, 0], array_column($list, 'n'));
        $this->assertSame([1, 2, 1, 1, 0], array_column($list, 'total'));
        $this->assertSame([['id' => $g, 'name' => 'Garage'], ['id' => $s2, 'name' => 'Shelf 2']], location_ancestors($b));
        $this->assertSame([], location_ancestors($g));
        $this->assertEqualsCanonicalizing([$g, $s2, $s10, $b], location_subtree_ids($g));
        $this->assertSame("\u{00A0}\u{00A0}\u{00A0}\u{00A0}\u{00A0}\u{00A0}↳ Box 1024", tax_option_label($list[3]));
        $this->assertSame('Garage', tax_option_label($list[1]));
        $this->assertSame('', location_path(null));
        $this->assertSame('', location_path(999));
    }

    public function testFilterIncludesSubLocations(): void
    {
        $g = $this->loc('Garage');
        $s = $this->loc('Shelf', $g);
        $b = $this->loc('Box', $s);
        $this->item('Drill', $b);
        $this->item('Broom', $g);
        $this->item('Elsewhere', null);
        $names = fn(int $loc) => array_column(items_search('', ['loc' => (string)$loc, 'cat' => ''], 'name', 1)['items'], 'name');
        $this->assertSame(['Broom', 'Drill'], $names($g));
        $this->assertSame(['Drill'], $names($s));
        $this->assertSame(['Drill'], $names($b));
    }

    public function testCyclesAndDepthAreRejected(): void
    {
        $g = $this->loc('Garage');
        $s = $this->loc('Shelf', $g);
        foreach ([$g, $s] as $target) {
            try {
                location_set_parent($g, $target);
                $this->fail('cycle accepted');
            } catch (UserError $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
        $this->assertFalse(location_set_parent($s, $g), 'unchanged parent is a no-op');
        $prev = $s;
        for ($i = 2; $i <= LOCATION_MAX_DEPTH; $i++) $prev = $this->loc("L$i", $prev);
        $this->assertSame(LOCATION_MAX_DEPTH, tax_list('location')[LOCATION_MAX_DEPTH]['depth']);
        $this->expectException(UserError::class);
        $this->loc('Too deep', $prev);
    }

    public function testMoveSubtreeRespectsDepthOfWholeBranch(): void
    {
        $a = $this->loc('A');
        $prev = $a;
        for ($i = 1; $i <= LOCATION_MAX_DEPTH; $i++) $prev = $this->loc("A$i", $prev);
        $x = $this->loc('X');
        try {
            location_set_parent($a, $x);
            $this->fail('too deep after move');
        } catch (UserError $e) {
            $this->assertStringContainsString('at most', $e->getMessage());
        }
        $this->assertNull(tax_list('location')[0]['parent_id']);
        $this->assertTrue(location_set_parent($x, $a), 'a leaf can go below a top-level location');
        $this->assertTrue(location_set_parent($x, null), 'and back to the top level');
    }

    public function testDeleteMovesChildrenUpAndKeepsTheirItems(): void
    {
        $g = $this->loc('Garage');
        $s = $this->loc('Shelf', $g);
        $b = $this->loc('Box', $s);
        $c = $this->loc('Crate', $s);
        $drill = $this->item('Drill', $b);
        $this->item('Saw', $s);
        tax_delete('location', $s, null);
        $byId = locations_by_id();
        $this->assertSame([$g, $g], [$byId[$b]['parent_id'], $byId[$c]['parent_id']]);
        $this->assertSame($b, item_get($drill)['location_id']);
        tax_delete('location', $g, null);
        $this->assertNull(locations_by_id()[$b]['parent_id'], 'deleting a top-level location makes its children top-level');
    }

    public function testCategoriesStayFlat(): void
    {
        tax_create('category', 'Tools');
        $list = tax_list('category');
        $this->assertSame([0], array_column($list, 'depth'));
        $this->assertSame(['Tools'], array_column($list, 'path'));
        $this->assertNull($list[0]['parent_id']);
    }

    public function testSearchMatchesAncestorLocationNames(): void
    {
        $g = $this->loc('Garage');
        $s = $this->loc('Shelf 2', $g);
        $b = $this->loc('Box 1024', $s);
        $a = $this->loc('Attic');
        $this->item('Drill', $b);
        $this->item('Broom', $g);
        $this->item('Lamp', $a);
        $this->item('Garage door remote', null);
        $find = fn(string $q) => array_column(items_search($q, ['loc' => '', 'cat' => ''], 'name', 1)['items'], 'name');
        $this->assertSame(['Broom', 'Drill', 'Garage door remote'], $find('garage'), 'descendants, the location itself and item names');
        $this->assertSame(['Drill'], $find('shelf'));
        $this->assertSame(['Drill'], $find('garage box'), 'every word may match a different level');
        $this->assertSame(['Drill'], $find('drill garage'));
        $this->assertSame([], $find('attic drill'));
        $this->assertSame([], location_ids_matching('%nothing%'));
        $this->assertSame([$g, $s, $b], location_ids_matching('%garage%'));
    }

    public function testOverviewRollsUpToTopLevelLocations(): void
    {
        $g = $this->loc('Garage');
        $s = $this->loc('Shelf', $g);
        $b = $this->loc('Box', $s);
        $a = $this->loc('Attic');
        foreach (['Drill', 'Saw', 'Hammer'] as $n) $this->item($n, $b);
        $this->item('Broom', $s);
        $this->item('Lamp', $a);
        $this->item('Fan', $a);
        $this->item('Loose', null);
        $this->assertSame([['id' => $g, 'name' => 'Garage', 'n' => 4], ['id' => $a, 'name' => 'Attic', 'n' => 2]], stats_location_rollup());
        $bars = stats_by('location');
        $this->assertSame(['Garage', 'Attic', 'No location'], array_column($bars, 'name'));
        $this->assertSame(7, array_sum(array_column($bars, 'n')), 'every item counted once');
        $this->assertTrue(locations_nested());
    }

    public function testRollupWithoutNestingEqualsDirectCounts(): void
    {
        $a = $this->loc('A');
        $b = $this->loc('B');
        $this->item('x', $b);
        $this->item('y', $b);
        $this->item('z', $a);
        $this->assertSame([['id' => $b, 'name' => 'B', 'n' => 2], ['id' => $a, 'name' => 'A', 'n' => 1]], stats_location_rollup());
        $this->assertFalse(locations_nested());
    }
}
