<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

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

    /**
     * The served HEIC JPEG is GD's re-encode of an Imagick intermediate that does not force 4:2:0.
     * Compared by sha256 so Alpine GD, which writes 4:2:0 even at q95, still matches its own unforced encode.
     */
    public function testHeicDisplayAtHighQualityKeepsSampling(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        if (!autofill_heic_supported()) $this->markTestSkipped('Imagick HEIC support is not available');
        settings_store(['jpeg_quality' => 95]);
        $source = $this->jpeg444();
        $this->assertSame('1x1,1x1,1x1', $this->jpegSampling($source), 'the source JPEG is 4:4:4');
        $reference = $this->servedWithoutForcedSampling($source);
        $name = bin2hex(random_bytes(16)) . '.jpg';
        $tmp = tempnam(sys_get_temp_dir(), 'disp');
        file_put_contents($tmp, $source);
        try {
            $this->assertTrue(image_store_heic($tmp, $name));
        } finally {
            @unlink($tmp);
        }
        $served = (string)file_get_contents(IMG_DIR . '/' . $name);
        $this->assertSame($this->jpegSampling($reference), $this->jpegSampling($served));
        $this->assertSame(hash('sha256', $reference), hash('sha256', $served), 'the display copy matches the encode that does not force 4:2:0');
    }

    /** Imagick JPEG at 4:4:4, the sampling a high-quality encode used to keep. */
    private function jpeg444(): string
    {
        $side = 48;
        $im = imagecreatetruecolor($side, $side);
        for ($y = 0; $y < $side; $y++) {
            for ($x = 0; $x < $side; $x++) {
                imagesetpixel($im, $x, $y, (($x * 13) & 255) << 16 | (($y * 17) & 255) << 8 | (($x ^ $y) & 255));
            }
        }
        ob_start();
        imagejpeg($im, null, 95);
        $jpeg = (string)ob_get_clean();
        $magick = new Imagick();
        $magick->readImageBlob($jpeg);
        $magick->setImageFormat('jpeg');
        $magick->setOption('jpeg:sampling-factor', '1x1,1x1,1x1');
        if (method_exists($magick, 'setImageProperty')) $magick->setImageProperty('jpeg:sampling-factor', '1x1,1x1,1x1');
        $magick->setImageCompressionQuality(95);
        $magick->stripImage();
        $out = $magick->getImageBlob();
        $magick->clear();
        $this->assertIsString($out);
        return $out;
    }

    /** v2.5.1 display encode: Imagick JPEG with no sampling override, then GD writes the served file. */
    private function servedWithoutForcedSampling(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ref');
        file_put_contents($tmp, $bytes);
        try {
            $im = new Imagick();
            $im->readImage($tmp);
            if (method_exists($im, 'autoOrient')) $im->autoOrient();
            elseif (method_exists($im, 'autoOrientImage')) $im->autoOrientImage();
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality((int)setting('jpeg_quality'));
            $im->stripImage();
            $blob = $im->getImageBlob();
            $im->clear();
        } finally {
            @unlink($tmp);
        }
        $this->assertIsString($blob);
        $gd = @imagecreatefromstring($blob);
        $this->assertNotFalse($gd);
        $name = bin2hex(random_bytes(16)) . '.jpg';
        $this->assertTrue(image_write_pair($gd, $name));
        return (string)file_get_contents(IMG_DIR . '/' . $name);
    }

    private function jpegSampling(string $jpeg): string
    {
        $i = 2;
        $n = strlen($jpeg);
        while ($i + 3 < $n) {
            if ($jpeg[$i] !== "\xFF") { $i++; continue; }
            $marker = ord($jpeg[$i + 1]);
            while ($marker === 0xFF && $i + 2 < $n) { $i++; $marker = ord($jpeg[$i + 1]); }
            if ($marker === 0xD8 || $marker === 0xD9 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { $i += 2; continue; }
            if ($i + 4 > $n) break;
            $len = unpack('n', substr($jpeg, $i + 2, 2))[1];
            if ($len < 2) break;
            if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) {
                $comps = ord($jpeg[$i + 9]);
                $parts = [];
                $p = $i + 10;
                for ($c = 0; $c < $comps; $c++) {
                    if ($p + 1 >= $n) break;
                    $sf = ord($jpeg[$p + 1]);
                    $parts[] = ($sf >> 4) . 'x' . ($sf & 0x0F);
                    $p += 3;
                }
                return implode(',', $parts);
            }
            if ($marker === 0xDA) break;
            $i += 2 + $len;
        }
        return '';
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
