<?php
declare(strict_types=1);

final class DuplicateTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function photo(string $content): string
    {
        $f = bin2hex(random_bytes(16)) . '.jpg';
        @mkdir(IMG_DIR, 0775, true);
        @mkdir(THUMB_DIR, 0775, true);
        file_put_contents(IMG_DIR . '/' . $f, $content);
        file_put_contents(THUMB_DIR . '/' . $f, 't' . $content);
        return $f;
    }

    public function testCopiesFieldsAndOptionallyPhotos(): void
    {
        $loc = tax_create('location', 'Garage')['id'];
        $id = item_save(null, ['name' => 'Drill', 'location_id' => $loc, 'category_id' => null, 'quantity' => 3, 'min_qty' => 1, 'notes' => 'x',
            'tags' => ['Power'], 'value_cents' => 1000, 'purchase_date' => '2024-01-01', 'serial' => 'S'], $this->photo('A'), false);
        photo_add($id, $this->photo('B'));
        item_lend($id, 'Maria', null, null);

        $plain = item_duplicate($id, false);
        $c = item_get($plain);
        $this->assertSame(['Drill (copy)', $loc, 3, 1, 'x', ['Power'], 1000, '2024-01-01', 'S', null, null],
            [$c['name'], (int)$c['location_id'], (int)$c['quantity'], $c['min_qty'], $c['notes'], array_column($c['tags'], 'name'), $c['value_cents'], $c['purchase_date'], $c['serial'], $c['lent_to'], $c['image']]);
        $this->assertSame(['location' => 'Garage', 'quantity' => 3, 'min_qty' => 1, 'tags' => ['Power'], 'copied_from' => 'Drill'], activity_list($plain)[0]['details']);

        $with = item_duplicate($id, true);
        $src = array_column(photos_for($id), 'file');
        $new = array_column(photos_for($with), 'file');
        $this->assertCount(2, $new);
        $this->assertSame([], array_intersect($src, $new));
        $this->assertSame(['A', 'B'], array_map(fn($f) => file_get_contents(IMG_DIR . '/' . $f), $new));
        $this->assertSame('tA', file_get_contents(THUMB_DIR . '/' . $new[0]));
        $this->assertSame($new[0], item_get($with)['image']);
        $this->assertCount(1, activity_list($with), 'a single "created" entry');
    }

    public function testLongNameAndMissingFile(): void
    {
        $id = item_save(null, ['name' => str_repeat('é', 200), 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $this->photo('A'), false);
        $this->assertSame(200, mb_strlen(item_get(item_duplicate($id, false))['name']));
        @unlink(IMG_DIR . '/' . item_get($id)['image']);
        $before = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
        try { item_duplicate($id, true); $this->fail('copied a missing file'); } catch (UserError) { $this->addToAssertionCount(1); }
        $this->assertSame($before, (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn());
        $this->expectException(UserError::class);
        item_duplicate(99999, false);
    }
}
