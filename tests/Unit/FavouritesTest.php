<?php
declare(strict_types=1);

/** Favourites and the recently viewed list (driver-independent). */
final class FavouritesTest extends DatabaseTestCase
{
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
        $_COOKIE[RECENT_COOKIE] = str_repeat('ab', 16);
        foreach (['A', 'B', 'C', 'D'] as $n) {
            $this->ids[$n] = item_save(null, ['name' => $n, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
        }
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[RECENT_COOKIE]);
        parent::tearDown();
    }

    public function testStarAndFilter(): void
    {
        fav_set($this->ids['B'], true);
        fav_set($this->ids['D'], true);
        $this->assertSame(2, fav_count());
        $res = items_search('', ['fav' => '1'], 'name', 1);
        $this->assertSame(['B', 'D'], array_column($res['items'], 'name'));
        $this->assertSame(1, (int)item_get($this->ids['B'])['favourite']);
        fav_set($this->ids['B'], false);
        $this->assertSame(1, fav_count());
        $this->assertSame('', filters_from(['fav' => 'yes'])['fav'], 'unknown value ignored');
        $this->expectException(UserError::class);
        fav_set(999999, true);
    }

    public function testRecentOrderLimitAndScope(): void
    {
        foreach (['A', 'B', 'C', 'A'] as $n) recent_track($this->ids[$n]);
        $this->assertSame(['A', 'C', 'B'], array_column(recent_items(), 'name'));
        settings_save(['recent_limit' => '2']);
        settings_cache(null, true);
        recent_track($this->ids['D']);
        $this->assertSame(['D', 'A'], array_column(recent_items(), 'name'));
        $this->assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM recent_views')->fetchColumn(), 'pruned to the limit');
        $_COOKIE[RECENT_COOKIE] = str_repeat('cd', 16);
        $this->assertSame([], recent_items(), 'another device');
        settings_save(['recent_scope' => 'install']);
        settings_cache(null, true);
        recent_track($this->ids['C']);
        $this->assertSame(['C'], array_column(recent_items(), 'name'));
        item_delete(item_get($this->ids['C']));
        $this->assertSame([], recent_items(), 'deleted items disappear');
        recent_track($this->ids['A']);
        recent_clear();
        $this->assertSame([], recent_items());
    }
}
