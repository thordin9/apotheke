<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

/**
 * Imagick fallback: four tests. Fixtures: tests/fixtures/transparent.png (32×32, red corner,
 * the rest fully transparent) and tests/fixtures/cmyk.jpg (16×16 CMYK). Skips when Imagick is absent.
 * The fourth test covers the HEIC display writer and skips when HEIC/HEIF is unavailable.
 *
 * testTransparentPngDerivedImageHasNoBlack
 * testCmykJpegDerivedImageIsSrgb
 * testStoredOriginalSha256UnchangedAfterImagickFallback
 * testHeicWriterFlattensTransparencyOntoWhite
 */
final class ImagickColorTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
    }

    /** Case 1. The derived JPEG has no black where the PNG was transparent. */
    public function testTransparentPngDerivedImageHasNoBlack(): void
    {
        $png = $this->fixture('transparent.png');
        $out = autofill_analysis_jpeg($png, 32, 95, ['imagick']);
        $this->assertIsString($out);
        $this->assertStringStartsWith("\xFF\xD8", $out);
        $clear = $this->pixel($out, 24, 24);
        $this->assertGreaterThan(250, $clear[0], 'transparent pixel is black: ' . implode(',', $clear));
        $this->assertGreaterThan(250, $clear[1], 'transparent pixel is black: ' . implode(',', $clear));
        $this->assertGreaterThan(250, $clear[2], 'transparent pixel is black: ' . implode(',', $clear));
        $gd = autofill_analysis_jpeg($png, 32, 95, ['gd']);
        $this->assertIsString($gd);
        $want = $this->pixel($gd, 24, 24);
        $this->assertEqualsWithDelta($want[0], $clear[0], 2);
        $this->assertEqualsWithDelta($want[1], $clear[1], 2);
        $this->assertEqualsWithDelta($want[2], $clear[2], 2);
        $red = $this->pixel($out, 2, 2);
        $this->assertGreaterThan(150, $red[0]);
        $this->assertLessThan(40, $red[1]);
        $this->assertLessThan(40, $red[2]);
        $this->assertSame(Imagick::COLORSPACE_SRGB, $this->colorspace($out));
        $this->assertStringNotContainsString('ICC_PROFILE', $out);
    }

    /** Case 2. A CMYK JPEG's derived copy is sRGB. */
    public function testCmykJpegDerivedImageIsSrgb(): void
    {
        $src = $this->fixture('cmyk.jpg');
        $this->assertSame(4, $this->jpegComponents($src), 'fixture must be a CMYK JPEG');
        $this->assertSame(Imagick::COLORSPACE_CMYK, $this->colorspace($src));
        $out = autofill_analysis_jpeg($src, 16, 95, ['imagick']);
        $this->assertIsString($out);
        $this->assertStringStartsWith("\xFF\xD8", $out);
        $this->assertSame(3, $this->jpegComponents($out), 'analysis JPEG is still CMYK');
        $this->assertSame(Imagick::COLORSPACE_SRGB, $this->colorspace($out));
        $this->assertStringNotContainsString('ICC_PROFILE', $out);
        $gd = autofill_analysis_jpeg($src, 16, 95, ['gd']);
        $this->assertIsString($gd, 'GD can read this CMYK JPEG and is the colour reference');
        $got = $this->pixel($out, 4, 4);
        $want = $this->pixel($gd, 4, 4);
        $this->assertEqualsWithDelta($want[0], $got[0], 2);
        $this->assertEqualsWithDelta($want[1], $got[1], 2);
        $this->assertEqualsWithDelta($want[2], $got[2], 2);
        $this->assertGreaterThan(200, $got[0], 'CMYK red became black or cyan');
        $this->assertLessThan(80, $got[2]);
    }

    /**
     * Case 3. The stored original stays the upload bytes. Only the derived analysis JPEG may change,
     * and that copy is the corrected one (so reverting the colour fix fails this test).
     */
    public function testStoredOriginalSha256UnchangedAfterImagickFallback(): void
    {
        $this->migrateFresh();
        foreach (['transparent.png' => 'png', 'cmyk.jpg' => 'jpg'] as $file => $ext) {
            $bytes = $this->fixture($file);
            $sha = hash('sha256', $bytes);
            $name = $this->store($bytes, 'upload.' . $ext);
            $orig = image_original_path($name);
            $this->assertIsString($orig, $file);
            $this->assertSame($sha, hash_file('sha256', $orig), $file . ' original differs from the upload');
            $derived = autofill_analysis_jpeg((string)file_get_contents($orig), 32, 95, ['imagick']);
            $this->assertIsString($derived, $file);
            $this->assertNotSame($sha, hash('sha256', $derived), $file . ' derived copy must be allowed to differ');
            $this->assertSame($sha, hash_file('sha256', $orig), $file . ' original changed when the derived JPEG was built');
            $this->assertSame($bytes, (string)file_get_contents($orig), $file . ' original bytes changed');
        }
        $pngDerived = autofill_analysis_jpeg($this->fixture('transparent.png'), 32, 95, ['imagick']);
        $this->assertIsString($pngDerived);
        $clear = $this->pixel($pngDerived, 24, 24);
        $this->assertGreaterThan(250, min($clear), 'derived PNG is black: ' . implode(',', $clear));
        $cmykDerived = autofill_analysis_jpeg($this->fixture('cmyk.jpg'), 16, 95, ['imagick']);
        $this->assertIsString($cmykDerived);
        $this->assertSame(3, $this->jpegComponents($cmykDerived), 'derived CMYK JPEG is still CMYK');
        $this->assertSame(Imagick::COLORSPACE_SRGB, $this->colorspace($cmykDerived));
    }

    /** The served HEIC JPEG is Imagick's intermediate, then GD. Alpha must already be white. */
    public function testHeicWriterFlattensTransparencyOntoWhite(): void
    {
        if (!function_exists('autofill_heic_supported') || !autofill_heic_supported()) {
            $this->markTestSkipped('Imagick HEIC support is not available');
        }
        $png = $this->fixture('transparent.png');
        $tmp = tempnam(sys_get_temp_dir(), 'alpha');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $png);
        $name = bin2hex(random_bytes(16)) . '.jpg';
        try {
            $this->assertTrue(image_store_heic($tmp, $name));
        } finally {
            @unlink($tmp);
        }
        $served = (string)file_get_contents(IMG_DIR . '/' . $name);
        $clear = $this->pixel($served, 24, 24);
        $this->assertGreaterThan(250, $clear[0], 'served transparent pixel is black: ' . implode(',', $clear));
        $this->assertGreaterThan(250, $clear[1], 'served transparent pixel is black: ' . implode(',', $clear));
        $this->assertGreaterThan(250, $clear[2], 'served transparent pixel is black: ' . implode(',', $clear));
        $this->assertSame(3, $this->jpegComponents($served));
        $this->assertSame(Imagick::COLORSPACE_SRGB, $this->colorspace($served));
    }

    private function fixture(string $name): string
    {
        $path = dirname(__DIR__) . '/fixtures/' . $name;
        $bytes = file_get_contents($path);
        $this->assertNotFalse($bytes, $path);
        $this->assertNotSame('', $bytes);
        return $bytes;
    }

    private function store(string $bytes, string $filename): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $bytes);
        try {
            return image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'name' => $filename]);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private function pixel(string $jpeg, int $x, int $y): array
    {
        $im = imagecreatefromstring($jpeg);
        $this->assertNotFalse($im);
        $c = imagecolorat($im, $x, $y);
        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    }

    private function colorspace(string $jpeg): int
    {
        $im = new Imagick();
        $im->readImageBlob($jpeg);
        $cs = $im->getImageColorspace();
        $im->clear();
        return $cs;
    }

    private function jpegComponents(string $jpeg): int
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
            if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) return ord($jpeg[$i + 9]);
            if ($marker === 0xDA) break;
            $i += 2 + $len;
        }
        return 0;
    }
}
