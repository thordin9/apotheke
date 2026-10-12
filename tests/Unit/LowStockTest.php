<?php
declare(strict_types=1);

final class LowStockTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function in(array $over = []): array
    {
        return $over + ['name' => 'Batteries', 'location_id' => null, 'category_id' => null, 'quantity' => 3, 'notes' => ''];
    }

    public function testParseMinQty(): void
    {
        $this->assertNull(parse_min_qty(''));
        $this->assertNull(parse_min_qty('  '));
        $this->assertSame(0, parse_min_qty('0'));
        $this->assertSame(12, parse_min_qty(' 12 '));
        foreach (['-1', '1.5', 'x', '1000001'] as $bad) {
            try { parse_min_qty($bad); $this->fail("accepted $bad"); } catch (UserError) { $this->addToAssertionCount(1); }
        }
    }

    public function testIsLow(): void
    {
        $this->assertFalse(is_low(['quantity' => 0, 'min_qty' => null]));
        $this->assertTrue(is_low(['quantity' => 2, 'min_qty' => 2]));
        $this->assertFalse(is_low(['quantity' => 3, 'min_qty' => 2]));
        $this->assertTrue(is_low(['quantity' => 0, 'min_qty' => 0]));
    }

    public function testFilterTileAndHistory(): void
    {
        $a = item_save(null, $this->in(['min_qty' => 2]), null, false);
        item_save(null, $this->in(['name' => 'Tape', 'min_qty' => null, 'quantity' => 0]), null, false);
        $low = fn() => array_column(items_search('', ['loc' => '', 'cat' => '', 'stock' => 'low'], 'name', 1)['items'], 'name');
        $this->assertSame([], $low());
        $this->assertSame(0, stats_totals()['low']);
        item_adjust_qty($a, -1);
        $this->assertSame(['Batteries'], $low());
        $this->assertSame(1, stats_totals()['low']);
        $this->assertSame(2, item_get($a)['min_qty']);

        item_save(item_get($a), $this->in(['quantity' => 2, 'min_qty' => 1]), null, false);
        $this->assertSame([], $low());
        $this->assertSame(['min_qty' => ['2', '1']], activity_list($a)[0]['details']['changes']);
    }
}
