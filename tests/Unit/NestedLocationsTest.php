<?php
declare(strict_types=1);

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
}
