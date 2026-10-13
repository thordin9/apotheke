<?php
declare(strict_types=1);

/** Undo tokens: snapshots, soft delete with the original id, expiry and file release. */
final class UndoTest extends DatabaseTestCase
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

    public function testEditUndo(): void
    {
        $id = $this->item('Lamp', ['quantity' => 3, 'tags' => ['light']]);
        $snap = undo_snapshot([$id]);
        item_save(item_get($id), ['name' => 'Desk lamp', 'location_id' => null, 'category_id' => null, 'quantity' => 9, 'notes' => 'x', 'tags' => ['desk']], null, false);
        $tok = undo_create('edit', 'Saved', $snap);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string)$tok);
        $r = undo_apply($tok);
        $this->assertSame([$id], $r['ids']);
        $it = item_get($id);
        $this->assertSame(['Lamp', 3, ['light']], [$it['name'], (int)$it['quantity'], array_column($it['tags'], 'name')]);
        $this->assertNull(undo_get($tok), 'consumed');
    }

    public function testDeleteRestoresSameIdAndReleasesFilesOnExpiry(): void
    {
        $id = $this->item('Radio');
        $file = str_repeat('b', 32) . '.jpg';
        @mkdir(IMG_DIR, 0777, true); @mkdir(THUMB_DIR, 0777, true);
        file_put_contents(IMG_DIR . "/$file", 'x'); file_put_contents(THUMB_DIR . "/$file", 'x');
        photo_insert($id, $file, true);
        $snap = undo_snapshot([$id]);
        item_delete(item_get($id), true);
        $tok = undo_create('delete', 'Deleted', $snap, [], undo_snapshot_files($snap));
        $this->assertNull(item_get($id));
        $this->assertFileExists(IMG_DIR . "/$file");
        undo_apply($tok);
        $this->assertSame('Radio', item_get($id)['name']);
        $this->assertSame($file, item_get($id)['image']);
        // delete again and let it expire: now the files go
        $snap = undo_snapshot([$id]);
        item_delete(item_get($id), true);
        undo_create('delete', 'Deleted', $snap, [], undo_snapshot_files($snap));
        $this->pdo->exec("UPDATE undo_tokens SET expires_at = '2000-01-01 00:00:00'");
        $this->assertSame(1, undo_prune());
        $this->assertFileDoesNotExist(IMG_DIR . "/$file");
        $this->assertFileDoesNotExist(THUMB_DIR . "/$file");
    }

    public function testReleaseKeepsReferencedFiles(): void
    {
        $id = $this->item('Clock');
        $file = str_repeat('c', 32) . '.jpg';
        @mkdir(IMG_DIR, 0777, true);
        file_put_contents(IMG_DIR . "/$file", 'x');
        photo_insert($id, $file, true);
        undo_release_files([['img', $file]]);
        $this->assertFileExists(IMG_DIR . "/$file", 'still used by an item');
        @unlink(IMG_DIR . "/$file");
    }

    public function testOffAndExpired(): void
    {
        $id = $this->item('Fan');
        $tok = undo_create('edit', 'x', undo_snapshot([$id]));
        $this->pdo->exec("UPDATE undo_tokens SET expires_at = '2000-01-01 00:00:00'");
        try { undo_apply($tok); $this->fail(); } catch (UserError $e) { $this->assertSame(410, $e->getCode()); }
        $this->assertSame([], settings_save(['undo_seconds' => '0']));
        settings_cache(null, true);
        $this->assertNull(undo_create('edit', 'x', undo_snapshot([$id])));
        $this->assertNull(undo_create('edit', 'x', []), 'nothing to undo');
    }
}
