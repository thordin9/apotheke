<?php
declare(strict_types=1);

final class SavedFiltersTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    public function testQueryIsNormalised(): void
    {
        $this->assertSame('', saved_filter_query([]));
        $this->assertSame('', saved_filter_query(['sort' => 'recent', 'page' => '3', 'junk' => 'x']));
        $this->assertSame('q=drill&loc=3&stock=low&sort=name',
            saved_filter_query(['sort' => 'name', 'stock' => 'low', 'loc' => '3', 'q' => '  drill ', 'select' => '1']));
        $this->assertSame('cat=none&exp=soon', saved_filter_query(['exp' => 'soon', 'cat' => 'none', 'lend' => 'bogus', 'sort' => 'nope']));
        $this->assertSame('', saved_filter_query(['q' => ['x'], 'loc' => ['1']]));   // arrays are ignored
    }

    public function testCreateListRenameDelete(): void
    {
        $b = saved_filter_create('Garage tools', 'loc=1&cat=2');
        $a = saved_filter_create('  ausgeliehen  ', 'lend=out');
        $list = saved_filters();
        $this->assertSame(['ausgeliehen', 'Garage tools'], array_column($list, 'name'));
        $this->assertSame('lend=out', $list[0]['query']);
        saved_filter_rename($b, 'Workshop');
        $this->assertSame('Workshop', saved_filter_get($b)['name']);
        saved_filter_rename($b, 'WORKSHOP');           // same filter, only case changed: allowed
        $this->assertSame('WORKSHOP', saved_filter_get($b)['name']);
        saved_filter_delete($a);
        $this->assertNull(saved_filter_get($a));
        $this->assertCount(1, saved_filters());
    }

    public function testErrors(): void
    {
        saved_filter_create('Low', 'stock=low');
        $cases = [[409, fn() => saved_filter_create('low', 'stock=low&sort=name')],
                  [422, fn() => saved_filter_create('Empty', '')],
                  [422, fn() => saved_filter_create('   ', 'q=x')],
                  [404, fn() => saved_filter_rename(999, 'x')],
                  [404, fn() => saved_filter_delete(999)]];
        foreach ($cases as [$code, $fn]) {
            try { $fn(); $this->fail('expected UserError ' . $code); }
            catch (UserError $e) { $this->assertSame($code, $e->getCode()); }
        }
    }

    public function testLimit(): void
    {
        for ($i = 1; $i <= saved_filters_max(); $i++) saved_filter_create('F' . $i, 'q=' . $i);
        try { saved_filter_create('One more', 'q=x'); $this->fail('limit not enforced'); }
        catch (UserError $e) { $this->assertSame(422, $e->getCode()); }
    }

    public function testIncludedInBackup(): void
    {
        $this->assertContains('saved_filters', backup_tables());
    }
}
