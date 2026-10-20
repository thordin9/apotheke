<?php
declare(strict_types=1);

/** A real HEIC upload stays byte-identical. Imagick makes stripped JPEGs; GD keeps the placeholder. */
final class HeicTest extends DatabaseTestCase
{
    private string $bytes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $path = dirname(__DIR__) . '/fixtures/tiny-gps.heic';
        $this->bytes = (string)file_get_contents($path);
    }

    public function testFixtureIsAGeneratedHeicWithExif(): void
    {
        $this->assertGreaterThan(100, strlen($this->bytes));
        $this->assertSame('ftyp', substr($this->bytes, 4, 4));
        $this->assertContains(substr($this->bytes, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'mif1']);
        $this->assertStringContainsString('GPSLatitude', $this->bytes);
        $this->assertStringContainsString('SerialNumber', $this->bytes);
        $this->assertTrue(image_bytes_heic(substr($this->bytes, 0, 12)));
    }

    public function testHeicUploadKeepsTheOriginalAndBuildsStrippedCopiesWhenItCan(): void
    {
        $name = $this->store($this->bytes);
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $this->assertSame(hash('sha256', $this->bytes), hash_file('sha256', $orig));
        $this->assertStringEndsWith('.heic', $orig);
        $served = (string)file_get_contents(IMG_DIR . '/' . $name);
        $info = getimagesizefromstring($served);
        $this->assertIsArray($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertStringStartsWith("\xFF\xD8", $served);
        $this->assertJpegStripped($served);
        $analysis = autofill_analysis_jpeg($this->bytes);
        if (autofill_heic_supported()) {
            $this->assertSame([96, 64], [$info[0], $info[1]], 'decoded HEIC, not the 64×64 placeholder');
            $this->assertIsString($analysis);
            $this->assertStringStartsWith("\xFF\xD8", $analysis);
            $ai = getimagesizefromstring($analysis);
            $this->assertIsArray($ai);
            $this->assertSame([96, 64, IMAGETYPE_JPEG], [$ai[0], $ai[1], $ai[2]]);
            $this->assertJpegStripped($analysis);
            $opened = autofill_open_analysis($this->bytes, 1600, '{}');
            $this->assertTrue($opened['ok'], $opened['message'] ?? '');
            $this->assertFileExists($opened['path']);
            $this->assertJpegStripped((string)file_get_contents($opened['path']));
            autofill_unlink_analysis($opened['path']);
            $this->assertFileDoesNotExist($opened['path']);
        } else {
            $this->assertSame([64, 64], [$info[0], $info[1]], 'GD-only installs serve the placeholder');
            $this->assertNull($analysis);
            $this->assertSame([], autofill_image_engines($this->bytes));
        }
    }

    private function assertJpegStripped(string $jpeg): void
    {
        $this->assertStringNotContainsString('GPSLatitude', $jpeg);
        $this->assertStringNotContainsString('SerialNumber', $jpeg);
        $this->assertStringNotContainsString("\xFF\xE1", $jpeg);
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($jpeg));
            if (is_array($exif)) {
                $found = (string)($exif['SectionsFound'] ?? '');
                $this->assertStringNotContainsString('EXIF', $found);
                $this->assertStringNotContainsString('GPS', $found);
                $this->assertArrayNotHasKey('GPSLatitude', $exif);
            }
        }
    }

    private function store(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'heic');
        file_put_contents($tmp, $bytes);
        try {
            return image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'name' => 'lamp.heic']);
        } finally {
            @unlink($tmp);
        }
    }
}
