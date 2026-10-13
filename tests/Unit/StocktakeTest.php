<?php
declare(strict_types=1);

/** Stocktakes: expected snapshot, ticking, misplaced items, report and applying moves. */
final class StocktakeTest extends DatabaseTestCase
{
    private int $garage;
    private int $shelf;
    private int $attic;

    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
        $this->garage = tax_create('location', 'Garage')['id'];
        $this->shelf = tax_create('location', 'Shelf')['id'];
        $this->attic = tax_create('location', 'Attic')['id'];
        location_set_parent($this->shelf, $this->garage);
    }

    private function item(string $name, ?int $loc, array $in = []): int
    {
        return item_save(null, $in + ['name' => $name, 'location_id' => $loc, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
    }

    public function testFullCycle(): void
    {
        $drill = $this->item('Drill', $this->garage, ['barcode' => '123']);
        $saw = $this->item('Saw', $this->shelf);
        $tent = $this->item('Tent', $this->attic);
        $loose = $this->item('Loose', null);
        $id = stocktake_start($this->garage);
        $this->assertSame($id, stocktake_start($this->garage), 'one open stocktake per location');
        $later = $this->item('Added later', $this->garage);
        $this->assertSame(['found' => 0, 'expected' => 2, 'misplaced' => 0], stocktake_progress($id), 'expected = snapshot at the start');
        $this->assertSame('found', stocktake_check($id, stocktake_resolve_code('123'))['state']);
        $this->assertSame('misplaced', stocktake_check($id, stocktake_resolve_code('https://x/item/' . $tent))['state']);
        $this->assertSame('misplaced', stocktake_check($id, $later)['state'], 'not in the snapshot = misplaced');
        $this->assertSame('none', stocktake_check($id, $later, false)['state'], 'unticking a misplaced item removes it');
        $this->assertSame('misplaced', stocktake_check($id, $loose)['state']);
        $rep = stocktake_report($id);
        $this->assertSame(['Drill'], array_column($rep['found'], 'item_name'));
        $this->assertSame(['Saw'], array_column($rep['missing'], 'item_name'));
        $this->assertSame(['Loose', 'Tent'], array_column($rep['misplaced'], 'item_name'));
        $this->assertSame('Garage › Shelf', $rep['missing'][0]['home_location']);
        $this->assertSame(2, stocktake_finish($id, true));
        $this->assertSame($this->garage, (int)item_get($tent)['location_id']);
        $this->assertSame($this->garage, (int)item_get($loose)['location_id']);
        $this->assertSame($this->shelf, (int)item_get($saw)['location_id'], 'missing items stay');
        $s = stocktake_get($id);
        $this->assertSame(['done', 2], [$s['status'], $s['moves_applied']]);
        $this->assertSame(['expected' => 1 + 1, 'found' => 1, 'missing' => 1, 'misplaced' => 2, 'moved' => 2], $s['summary']);
        $this->assertSame('moved', activity_list($tent, 1, 1)[0]['action']);
        $this->assertSame('stocktake', activity_list($tent, 1, 1)[0]['details']['reason']);
        $this->expectException(UserError::class);
        stocktake_check($id, $saw);
    }

    public function testCodes(): void
    {
        $a = $this->item('A', null, ['barcode' => '555']);
        $this->item('B', null, ['barcode' => '555']);
        $this->assertSame($a, stocktake_resolve_code("#$a"));
        foreach (['555' => 409, '777' => 404, '' => 422, '#99999' => 404] as $code => $want) {
            try { stocktake_resolve_code((string)$code); $this->fail("no error for $code"); } catch (UserError $e) { $this->assertSame($want, $e->getCode(), (string)$code); }
        }
    }

    public function testFinishWithoutMovesAndCancel(): void
    {
        $tent = $this->item('Tent', $this->attic);
        $id = stocktake_start($this->garage);
        stocktake_check($id, $tent);
        $this->assertSame(0, stocktake_finish($id, false));
        $this->assertSame($this->attic, (int)item_get($tent)['location_id']);
        $id2 = stocktake_start($this->attic);
        stocktake_cancel($id2);
        $this->assertSame('cancelled', stocktake_get($id2)['status']);
        $this->assertNull(stocktake_open_for($this->attic));
        $this->assertSame([$id2, $id], array_column(stocktake_list(), 'id'));
    }

    public function testDeletedItemKeepsItsName(): void
    {
        $saw = $this->item('Saw', $this->garage);
        $id = stocktake_start($this->garage);
        item_delete(item_get($saw));
        $rep = stocktake_report($id);
        $this->assertSame([['Saw', null]], array_map(fn($l) => [$l['item_name'], $l['item_id']], $rep['missing']));
        $this->assertContains('stocktakes', backup_tables());
    }
}
