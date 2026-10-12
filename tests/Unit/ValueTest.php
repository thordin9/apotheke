<?php
declare(strict_types=1);

final class ValueTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function item(string $name, ?int $cents, ?int $loc = null, ?int $cat = null): int
    {
        return item_save(null, ['name' => $name, 'location_id' => $loc, 'category_id' => $cat, 'quantity' => 1, 'notes' => '', 'value_cents' => $cents], null, false);
    }

    public function testParseAndFormat(): void
    {
        $this->assertSame(7990, parse_money('79,90'));
        $this->assertSame(7990, parse_money('79.90'));
        $this->assertNull(parse_money(''));
        $this->assertSame('1,234.50 EUR', fmt_money(123450, 'EUR'));
        $this->assertSame('', fmt_money(null));
    }

    public function testTotalsRollUpAndBuckets(): void
    {
        $g = tax_create('location', 'Garage')['id'];
        $s = tax_create('location', 'Shelf')['id'];
        location_set_parent($s, $g);
        $a = tax_create('location', 'Attic')['id'];
        $tools = tax_create('category', 'Tools')['id'];
        $this->item('Drill', 15000, $s, $tools);
        $this->item('Saw', 5000, $g, $tools);
        $this->item('Lamp', 2550, $a, null);
        $this->item('Box', null, $a, $tools);          // no price: not counted
        $this->item('Loose', 1000, null, null);
        $v = stats_value();
        $this->assertSame(23550, $v['total']);
        $this->assertSame(4, $v['priced']);
        $this->assertSame([['Garage', 20000], ['Attic', 2550], ['No location', 1000]],
            array_map(fn($r) => [$r['name'], $r['n']], $v['by_location']), 'shelf rolled up into the garage');
        $this->assertSame([['Tools', 20000], ['No category', 3550]], array_map(fn($r) => [$r['name'], $r['n']], $v['by_category']));
    }

    public function testSortByValueHighestFirstWithoutPriceLast(): void
    {
        $this->item('Cheap', 100);
        $this->item('None', null);
        $this->item('Dear', 99900);
        $this->item('Mid', 5000);
        $this->assertSame(['Dear', 'Mid', 'Cheap', 'None'], array_column(items_search('', ['loc' => '', 'cat' => ''], 'value', 1)['items'], 'name'));
    }

    public function testEmptyCatalog(): void
    {
        $v = stats_value();
        $this->assertSame([0, 0, [], []], [$v['total'], $v['priced'], $v['by_location'], $v['by_category']]);
    }
}
