<?php
declare(strict_types=1);

/** Camera uploads stay byte-identical and off the web path; delete removes every copy. */
final class OriginalsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    public function testUploadKeepsTheOriginalAndServesAStrippedCopy(): void
    {
        $bytes = $this->markedJpeg(120, 80);
        $name = $this->store($bytes);
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $orig));
        $this->assertSame(1, preg_match('/^[a-f0-9]{64}\.jpg$/', basename($orig)));
        $this->assertNotSame(substr($name, 0, 32), substr(basename($orig), 0, 32));
        $served = (string)file_get_contents(IMG_DIR . '/' . $name);
        $this->assertStringStartsWith("\xFF\xD8", $served);
        $this->assertStringNotContainsString('GPSLatitude', $served);
        $this->assertStringNotContainsString('SerialNumber', $served);
        $this->assertStringNotContainsString("\xFF\xE1", $served);
        $this->assertNotSame(hash('sha256', $bytes), hash('sha256', $served));
        $this->assertFileExists(THUMB_DIR . '/' . $name);
        $this->assertFileExists(ORIGINALS_DIR . '/.htaccess');
        $this->assertStringContainsString('Require all denied', (string)file_get_contents(ORIGINALS_DIR . '/.htaccess'));
        $this->assertFalse(valid_image_name(basename($orig)));
    }

    public function testLegacyPhotoWithoutAnOriginalIsLeftAlone(): void
    {
        $name = bin2hex(random_bytes(16)) . '.jpg';
        file_put_contents(IMG_DIR . '/' . $name, $this->plainJpeg(20, 20));
        $this->assertNull(image_original_path($name));
        $this->assertFileExists(IMG_DIR . '/' . $name);
    }

    public function testDeleteRemovesOriginalDerivativeAndThumbnail(): void
    {
        $name = $this->store($this->plainJpeg(60, 40));
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $map = ORIGINALS_DIR . '/map/' . substr($name, 0, 32);
        image_delete($name);
        $this->assertFileDoesNotExist(IMG_DIR . '/' . $name);
        $this->assertFileDoesNotExist(THUMB_DIR . '/' . $name);
        $this->assertFileDoesNotExist($orig);
        $this->assertFileDoesNotExist($map);
        $this->assertNull(image_original_path($name));
    }

    public function testReplacingAPhotoRemovesThePreviousFiles(): void
    {
        $first = $this->store($this->plainJpeg(50, 40));
        $firstOrig = image_original_path($first);
        $id = item_save(null, ['name' => 'Mug', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $first, false);
        $secondBytes = $this->plainJpeg(70, 50);
        $second = $this->store($secondBytes);
        item_save(item_get($id), item_input(item_get($id)), $second, false);
        $this->assertFileDoesNotExist(IMG_DIR . '/' . $first);
        $this->assertFileDoesNotExist(THUMB_DIR . '/' . $first);
        $this->assertFileDoesNotExist((string)$firstOrig);
        $this->assertFileDoesNotExist(ORIGINALS_DIR . '/map/' . substr($first, 0, 32));
        $kept = image_original_path($second);
        $this->assertNotNull($kept);
        $this->assertSame(hash('sha256', $secondBytes), hash_file('sha256', $kept));
        $this->assertFileExists(IMG_DIR . '/' . $second);
        $this->assertFileExists(THUMB_DIR . '/' . $second);
    }

    public function testUnreadableHeicKeepsTheOriginalAndServesAPlaceholder(): void
    {
        $bytes = "\x00\x00\x00\x18ftypheic" . str_repeat('x', 200);
        $name = $this->store($bytes, 'shot.heic');
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $orig));
        $this->assertStringEndsWith('.heic', $orig);
        $served = (string)file_get_contents(IMG_DIR . '/' . $name);
        $this->assertStringStartsWith("\xFF\xD8", $served);
        $this->assertNotSame($bytes, $served);
        $this->assertFileExists(THUMB_DIR . '/' . $name);
    }

    private function store(string $bytes, string $filename = 'a.jpg'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $bytes);
        try {
            return image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'name' => $filename]);
        } finally {
            @unlink($tmp);
        }
    }

    private function plainJpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 80, 120));
        ob_start();
        imagejpeg($im);
        return (string)ob_get_clean();
    }

    private function markedJpeg(int $w, int $h): string
    {
        $jpeg = $this->plainJpeg($w, $h);
        $payload = "Exif\x00\x00GPSLatitude Make Model SerialNumber";
        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }
}
