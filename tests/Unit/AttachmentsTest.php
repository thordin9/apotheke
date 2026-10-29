<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

/** Attachments: type sniffing, names, limits, deletion (driver-independent). */
final class AttachmentsTest extends DatabaseTestCase
{
    private function tmp(string $data): array
    {
        $f = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($f, $data);
        return ['name' => 'doc.pdf', 'type' => 'application/pdf', 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => strlen($data)];
    }

    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
    }

    public function testSniffAndNames(): void
    {
        $f = $this->tmp("%PDF-1.7\n");
        $this->assertSame('pdf', attach_sniff($f['tmp_name']));
        file_put_contents($f['tmp_name'], 'GIF89a');
        $this->assertNull(attach_sniff($f['tmp_name']));
        $this->assertSame('evil.pdf', attach_display_name('../../etc/evil.exe', 'pdf'));
        $this->assertSame('a b.png', attach_display_name("a\x00 b<>.png", 'png'));
        $this->assertTrue(valid_attachment_name(str_repeat('a', 32) . '.pdf'));
        $this->assertFalse(valid_attachment_name('../x.pdf'));
        $this->assertFalse(valid_attachment_name(str_repeat('a', 32) . '.php'));
    }

    public function testAddListDeleteAndCascade(): void
    {
        $id = item_save(null, ['name' => 'Fridge', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
        $this->assertSame(1, attachments_add($id, [$this->tmp("%PDF-1.4 manual")]));
        $list = attachments_for($id);
        $this->assertCount(1, $list);
        $this->assertSame('application/pdf', $list[0]['mime']);
        $this->assertFileExists(files_dir() . '/' . $list[0]['file']);
        attachment_delete($id, $list[0]['id']);
        $this->assertSame([], attachments_for($id));
        $this->assertFileDoesNotExist(files_dir() . '/' . $list[0]['file']);
        attachments_add($id, [$this->tmp("%PDF-1.4 again")]);
        $file = attachments_for($id)[0]['file'];
        item_delete(item_get($id));
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM item_attachments')->fetchColumn());
        $this->assertFileDoesNotExist(files_dir() . '/' . $file);
    }

    public function testLimits(): void
    {
        $id = item_save(null, ['name' => 'TV', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
        $this->assertSame([], settings_save(['attach_types' => 'images', 'attach_max_per_item' => '1']));
        settings_cache(null, true);
        try { attachments_add($id, [$this->tmp('%PDF-1.4')]); $this->fail('pdf accepted'); } catch (UserError $e) { $this->assertStringContainsString('Only', $e->getMessage()); }
        $this->assertSame([], settings_save(['attach_types' => 'pdf_images']));
        settings_cache(null, true);
        try { attachments_add($id, [$this->tmp('%PDF-1.4'), $this->tmp('%PDF-1.4')]); $this->fail('limit ignored'); } catch (UserError $e) { $this->assertStringContainsString('at most 1', $e->getMessage()); }
        $this->assertSame([], attachments_for($id));
    }
}
