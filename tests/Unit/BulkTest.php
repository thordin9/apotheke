<?php
declare(strict_types=1);

final class BulkTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function item(string $name, array $over = []): int
    {
        return item_save(null, $over + ['name' => $name, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
    }

    public function testMoveCategoryAndTagsKeepOtherFields(): void
    {
        $loc = tax_create('location', 'Attic')['id'];
        $cat = tax_create('category', 'Camping')['id'];
        $a = $this->item('Tent', ['notes' => 'green', 'quantity' => 2, 'min_qty' => 1, 'serial' => 'T-1', 'tags' => ['Old']]);
        $b = $this->item('Stove');
        $this->assertSame(2, items_bulk([$a, $b, $b, 0], 'move', ['location_id' => $loc]));
        $this->assertSame(2, items_bulk([$a, $b], 'category', ['category_id' => $cat]));
        $this->assertSame(2, items_bulk([$a, $b], 'tag_add', ['tags' => ['Summer']]));
        $t = item_get($a);
        $this->assertSame([$loc, $cat, 'green', 2, 1, 'T-1'], [(int)$t['location_id'], (int)$t['category_id'], $t['notes'], (int)$t['quantity'], $t['min_qty'], $t['serial']]);
        $this->assertSame(['Old', 'Summer'], array_column($t['tags'], 'name'));
        $old = (int)$t['tags'][0]['id'];
        items_bulk([$a, $b], 'tag_remove', ['tag_id' => $old]);
        $this->assertSame(['Summer'], array_column(item_get($a)['tags'], 'name'));
        $this->assertSame(['created', 'moved', 'edited', 'edited', 'edited'], array_column(array_reverse(activity_list($a)), 'action'));
    }

    public function testDeleteAndValidation(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $this->assertSame(2, items_bulk([$a, $b, 999], 'delete'));
        $this->assertNull(item_get($a));
        $this->assertSame('deleted', activity_list($b)[0]['action']);
        foreach ([[[], 'move'], [[1], 'nope'], [range(1, BULK_MAX + 1), 'move'], [[1], 'tag_add'], [[1], 'move', ['location_id' => 999]]] as $case) {
            try { items_bulk($case[0], $case[1], $case[2] ?? []); $this->fail('accepted ' . json_encode($case)); } catch (UserError) { $this->addToAssertionCount(1); }
        }
    }

    public function testAllOrNothing(): void
    {
        $a = $this->item('A');
        $b = $this->item('B', ['tags' => array_map(fn($i) => "t$i", range(1, 20))]);
        try { items_bulk([$a, $b], 'tag_add', ['tags' => ['extra']]); $this->fail('21 tags accepted'); } catch (UserError) {}
        $this->assertSame([], item_get($a)['tags'], 'the first item was rolled back too');
        $this->assertCount(1, activity_list($a));
    }
}
