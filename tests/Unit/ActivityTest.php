<?php
declare(strict_types=1);

final class ActivityTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function in(array $over = []): array
    {
        return $over + ['name' => 'Drill', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''];
    }

    private function actions(int $id): array
    {
        return array_column(array_reverse(activity_list($id)), 'action');
    }

    public function testEveryKindOfChangeIsLogged(): void
    {
        $a = tax_create('location', 'Shelf A')['id'];
        $b = tax_create('location', 'Shelf B')['id'];
        $id = item_save(null, $this->in(['location_id' => $a, 'tags' => ['x']]), str_repeat('1', 32) . '.jpg', false);
        $created = activity_list($id)[0];
        $this->assertSame('created', $created['action']);
        $this->assertSame(['location' => 'Shelf A', 'quantity' => 1, 'photo' => true, 'tags' => ['x']], $created['details']);

        item_save(item_get($id), $this->in(['name' => 'Drill 18V', 'location_id' => $b, 'quantity' => 3, 'notes' => 'Makita', 'tags' => ['x', 'y'], 'value_cents' => 9900]), null, false);
        $this->assertSame(['created', 'edited', 'moved', 'quantity'], $this->actions($id));
        [$q, $m, $e] = array_slice(activity_list($id), 0, 3);
        $this->assertSame(['name' => ['Drill', 'Drill 18V'], 'notes' => ['', 'Makita'], 'value' => ['', '99.00'], 'tags' => ['x', 'x, y']], $e['details']['changes']);
        $this->assertSame(['from' => 'Shelf A', 'to' => 'Shelf B'], $m['details']);
        $this->assertSame(['from' => 1, 'to' => 3], $q['details']);

        item_save(item_get($id), $this->in(['name' => 'Drill 18V', 'location_id' => $b, 'quantity' => 3, 'notes' => 'Makita', 'tags' => ['x', 'y'], 'value_cents' => 9900]), null, false);
        $this->assertCount(4, activity_list($id), 'saving without changes logs nothing');

        item_adjust_qty($id, -1);
        item_adjust_qty($id, -100);
        item_adjust_qty($id, -1);   // already 0: no entry
        $this->assertSame(['from' => 2, 'to' => 0], activity_list($id)[0]['details']);
        $pid = photo_add($id, str_repeat('2', 32) . '.jpg');
        photo_action($id, $pid, 'cover');
        photo_action($id, $pid, 'left');   // already first: no entry
        photo_action($id, $pid, 'delete');
        $this->assertSame(['added', 'cover', 'deleted'], array_map(fn($e) => $e['details']['change'], array_reverse(array_slice(activity_list($id), 0, 3))));

        tax_delete('location', $b, $a);
        $this->assertSame(['from' => 'Shelf B', 'to' => 'Shelf A', 'reason' => 'location deleted'], activity_list($id)[0]['details']);

        $name = item_get($id)['name'];
        item_delete(item_get($id));
        $last = activity_list($id)[0];
        $this->assertSame('deleted', $last['action']);
        $this->assertSame($name, $last['item_name']);
        $this->assertFalse($last['exists'], 'history outlives the item');
        $this->assertSame('created', array_reverse(activity_list($id))[0]['action']);
    }

    public function testLogIsWrittenInTheSameTransaction(): void
    {
        $id = item_save(null, $this->in(), null, false);
        $this->pdo->exec('DROP TABLE activity');   // the log write now fails …
        try {
            item_save(item_get($id), $this->in(['name' => 'Renamed', 'quantity' => 7]), null, false);
            $this->fail('expected failure');
        } catch (PDOException) {
        }
        $this->assertSame('Drill', item_get($id)['name'], '… so the change itself is rolled back');
        try { item_adjust_qty($id, 5); $this->fail('expected failure'); } catch (PDOException) {}
        $this->assertSame(1, (int)item_get($id)['quantity']);
        try { item_delete(item_get($id)); $this->fail('expected failure'); } catch (PDOException) {}
        $this->assertNotNull(item_get($id));
        try { item_save(null, $this->in(['name' => 'New']), null, false); $this->fail('expected failure'); } catch (PDOException) {}
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function testListPagingAndHtml(): void
    {
        $id = item_save(null, $this->in(['name' => '<b>x</b>']), null, false);
        for ($i = 0; $i < 7; $i++) item_adjust_qty($id, 1);
        $this->assertCount(4, activity_list(null, 1, 3), 'one extra row tells the caller there is more');
        $this->assertSame(activity_list(null)[3]['id'], activity_list(null, 2, 3)[0]['id']);
        require_once dirname(__DIR__, 2) . '/src/views.php';
        $html = activity_items_html(activity_list($id), true);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('Quantity <b>7</b> → <b>8</b>', $html);
    }
}
