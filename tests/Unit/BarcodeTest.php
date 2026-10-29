<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

final class BarcodeTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function item(string $name, string $barcode): int
    {
        return item_save(null, ['name' => $name, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'barcode' => $barcode], null, false);
    }

    public function testClean(): void
    {
        $this->assertSame('4006381333931', barcode_clean(" 4006 381\t333931\n"));
        $this->assertSame('', barcode_clean("  \x07 "));
        $this->assertSame(BARCODE_MAX, mb_strlen(barcode_clean(str_repeat('9', 200))));
        $this->assertSame('https://ex.am/πλ', barcode_clean('https://ex.am/πλ'));
    }

    public function testStoredAndSearchable(): void
    {
        $id = $this->item('Batteries AA', ' 5000394 023970 ');
        $it = item_get($id);
        $this->assertSame('5000394023970', $it['barcode']);
        $this->assertSame([$id], array_map(fn($r) => (int)$r['id'], items_search('5000394023970', filters_norm([]), 'recent', 1)['items']));
        $this->assertSame([$id], array_map(fn($r) => (int)$r['id'], items_search('023970', filters_norm([]), 'recent', 1)['items']), 'partial code matches too');
        $none = $this->item('No code', '');
        $this->assertSame('', item_get($none)['barcode']);
        $this->assertNull(db()->query("SELECT barcode FROM items WHERE id = $none")->fetchColumn() ?: null, 'empty stored as NULL');
    }

    public function testLookupTargets(): void
    {
        $this->assertSame('/new?barcode=123', barcode_target('123'));
        $this->assertSame('/barcode', barcode_target('  '));
        $a = $this->item('Tea', '123');
        $this->assertSame('/item/' . $a, barcode_target(' 123 '));
        $this->assertSame('/new?barcode=12', barcode_target('12'), 'lookup is exact, not a prefix match');
        $this->item('Tea (spare)', '123');
        $this->assertSame('/?q=123', barcode_target('123'), 'several items share it → list them');
        $this->assertSame('/new?barcode=a%2Fbc', barcode_target('a/b c'), 'whitespace removed, the rest URL-encoded');
    }

    public function testHistoryAndDuplicate(): void
    {
        $id = $this->item('Drill', '');
        $it = item_get($id);
        item_save($it, ['barcode' => '777'] + item_input($it), null, false);
        $log = activity_list($id);
        $this->assertSame(['', '777'], $log[0]['details']['changes']['barcode']);
        $this->assertSame('777', item_input(item_get($id))['barcode']);
    }
}
