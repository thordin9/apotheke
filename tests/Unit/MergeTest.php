<?php
declare(strict_types=1);

/** Merging duplicates: combination rules and all-or-nothing behaviour. */
final class MergeTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
    }

    private function item(string $name, array $in = []): int
    {
        return item_save(null, $in + ['name' => $name, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
    }

    public function testCombines(): void
    {
        $a = $this->item('Tape', ['quantity' => 2, 'tags' => ['tools'], 'serial' => 'KEEP']);
        $b = $this->item('tape', ['quantity' => 3, 'tags' => ['tools', 'office'], 'serial' => 'LOST', 'notes' => 'blue', 'expires_on' => '2030-01-01']);
        fav_set($b, true);
        $p = merge_preview($b, $a);
        $this->assertSame(['expires_on', 'notes'], array_keys($p['fills']));
        $this->assertSame(2, (int)item_get($a)['quantity'], 'preview changes nothing');
        item_merge($b, $a);
        $t = item_get($a);
        $this->assertNull(item_get($b));
        $this->assertSame(5, (int)$t['quantity']);
        $this->assertSame('KEEP', $t['serial'], 'the target’s own values win');
        $this->assertSame('2030-01-01', $t['expires_on']);
        $this->assertSame('blue', $t['notes']);
        $this->assertSame(['office', 'tools'], array_column($t['tags'], 'name'));
        $this->assertSame(1, (int)$t['favourite']);
        $last = activity_list($a, 1, 1)[0];
        $this->assertSame('merged', $last['action']);
        $this->assertSame('tape', $last['details']['from']);
    }

    public function testRefusesSelfAndMissing(): void
    {
        $a = $this->item('X');
        try { merge_preview($a, $a); $this->fail(); } catch (UserError $e) { $this->assertSame(422, $e->getCode()); }
        try { item_merge($a, 999999); $this->fail(); } catch (UserError $e) { $this->assertSame(404, $e->getCode()); }
    }

    public function testAllOrNothing(): void
    {
        $a = $this->item('Keep', ['quantity' => 1]);
        $b = $this->item('Gone', ['quantity' => 6]);
        $this->pdo->prepare('INSERT INTO item_photos (item_id, file, position, created_at) VALUES (?, ?, 0, ?)')->execute([$b, str_repeat('a', 32) . '.jpg', now_utc()]);
        // make the final UPDATE of the target fail
        if ($this->driver === 'pgsql') {
            $this->pdo->exec("CREATE FUNCTION boom() RETURNS trigger AS \$\$ BEGIN IF NEW.quantity = 7 THEN RAISE EXCEPTION 'boom'; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql");
            $this->pdo->exec('CREATE TRIGGER t_boom BEFORE UPDATE ON items FOR EACH ROW EXECUTE FUNCTION boom()');
        } else {
            $this->pdo->exec("CREATE TRIGGER t_boom BEFORE UPDATE ON items WHEN NEW.quantity = 7 BEGIN SELECT RAISE(ABORT, 'boom'); END");
        }
        try { item_merge($b, $a); $this->fail('merge should fail'); } catch (PDOException) { /* expected */ }
        $this->assertNotNull(item_get($b), 'source still there');
        $this->assertSame(1, (int)item_get($a)['quantity']);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM item_photos WHERE item_id = $b")->fetchColumn(), 'photo not moved');
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM activity WHERE action = 'merged'")->fetchColumn());
    }
}
