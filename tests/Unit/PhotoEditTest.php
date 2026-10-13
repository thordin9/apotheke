<?php
declare(strict_types=1);

/** Photo editing (rotate/crop → new version) and reordering. */
final class PhotoEditTest extends DatabaseTestCase
{
    private int $item;
    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
        @mkdir(IMG_DIR, 0777, true);
        @mkdir(THUMB_DIR, 0777, true);
        $this->pdo->prepare('INSERT INTO items (name, quantity, created_at, updated_at) VALUES (?, 1, ?, ?)')->execute(['Lamp', now_utc(), now_utc()]);
        $this->item = (int)$this->pdo->lastInsertId();
        foreach ([[1000, 500], [300, 300], [400, 200]] as $i => [$w, $h]) {
            $file = bin2hex(random_bytes(16)) . '.jpg';
            $im = imagecreatetruecolor($w, $h);
            imagejpeg($im, IMG_DIR . "/$file");
            imagejpeg($im, THUMB_DIR . "/$file");
            $this->pdo->prepare('INSERT INTO item_photos (item_id, file, position, created_at) VALUES (?, ?, ?, ?)')->execute([$this->item, $file, $i, now_utc()]);
            $this->ids[] = (int)$this->pdo->lastInsertId();
            if ($i === 0) $this->pdo->prepare('UPDATE items SET image = ? WHERE id = ?')->execute([$file, $this->item]);
        }
    }

    private function photo(int $id): array
    {
        return $this->pdo->query("SELECT file, version FROM item_photos WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }

    public function testRotateAndCropMakeANewVersion(): void
    {
        $old = $this->photo($this->ids[0])['file'];
        photo_edit($this->item, $this->ids[0], 90, [0, 0, 1, 0.5]);
        $p = $this->photo($this->ids[0]);
        $this->assertNotSame($old, $p['file']);
        $this->assertSame(2, (int)$p['version']);
        $this->assertSame([500, 500], array_slice(getimagesize(IMG_DIR . '/' . $p['file']), 0, 2), '1000×500 → rotated 500×1000 → top half');
        $this->assertFileDoesNotExist(IMG_DIR . "/$old");
        $this->assertSame($p['file'], $this->pdo->query("SELECT image FROM items WHERE id = {$this->item}")->fetchColumn());
        photo_edit($this->item, $this->ids[0], 270, [0, 0, 1, 1]);
        $this->assertSame(3, (int)$this->photo($this->ids[0])['version']);
    }

    public function testValidation(): void
    {
        foreach ([[45, [0, 0, 1, 1]], [0, [0, 0, 1, 1]], [0, [0.5, 0, 0.6, 1]], [0, [0, 0, 0.01, 1]], [0, [-0.1, 0, 0.5, 1]]] as [$r, $c]) {
            try {
                photo_edit($this->item, $this->ids[0], $r, $c);
                $this->fail('accepted ' . json_encode([$r, $c]));
            } catch (UserError $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
        $this->assertSame(1, (int)$this->photo($this->ids[0])['version']);
    }

    public function testReorderSetsCover(): void
    {
        $new = [$this->ids[2], $this->ids[0], $this->ids[1]];
        photo_reorder($this->item, $new);
        $this->assertSame($new, array_map('intval', $this->pdo->query("SELECT id FROM item_photos WHERE item_id = {$this->item} ORDER BY position")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame($this->photo($this->ids[2])['file'], $this->pdo->query("SELECT image FROM items WHERE id = {$this->item}")->fetchColumn());
        $this->expectException(UserError::class);
        photo_reorder($this->item, [$this->ids[0], $this->ids[1]]);
    }
}
