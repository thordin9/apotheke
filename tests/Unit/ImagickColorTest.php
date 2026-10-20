<?php
declare(strict_types=1);

/**
 * Imagick fallback matches GD: transparent pixels land on white, and a CMYK JPEG becomes sRGB.
 * Skips when the Imagick extension is not loaded.
 */
final class ImagickColorTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
    }

    public function testImagickFlattensTransparentPngOntoWhite(): void
    {
        $png = $this->transparentPng();
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

    public function testImagickConvertsCmykJpegToSrgb(): void
    {
        $src = $this->cmykJpeg();
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
        $icc = '/usr/share/color/icc/ghostscript/default_cmyk.icc';
        if (!is_file($icc)) return;
        $profiled = $this->cmykJpegWithProfile((string)file_get_contents($icc));
        $this->assertStringContainsString('ICC_PROFILE', $profiled);
        $converted = autofill_analysis_jpeg($profiled, 16, 95, ['imagick']);
        $this->assertIsString($converted);
        $this->assertSame(3, $this->jpegComponents($converted));
        $this->assertSame(Imagick::COLORSPACE_SRGB, $this->colorspace($converted));
        $this->assertStringNotContainsString('ICC_PROFILE', $converted);
        $gdProfiled = autofill_analysis_jpeg($profiled, 16, 95, ['gd']);
        $this->assertIsString($gdProfiled);
        $this->assertStringNotContainsString('ICC_PROFILE', $gdProfiled);
        $fromImagick = $this->pixel($converted, 4, 4);
        $fromGd = $this->pixel($gdProfiled, 4, 4);
        $this->assertEqualsWithDelta($fromGd[0], $fromImagick[0], 2);
        $this->assertEqualsWithDelta($fromGd[1], $fromImagick[1], 2);
        $this->assertEqualsWithDelta($fromGd[2], $fromImagick[2], 2);
    }

    /** The served HEIC JPEG is Imagick's intermediate, then GD. Alpha must already be white. */
    public function testHeicWriterFlattensTransparencyOntoWhite(): void
    {
        if (!function_exists('autofill_heic_supported') || !autofill_heic_supported()) {
            $this->markTestSkipped('Imagick HEIC support is not available');
        }
        $png = $this->transparentPng();
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

    /** 32×32 PNG: opaque red in the corner, everything else fully transparent. */
    private function transparentPng(): string
    {
        $im = imagecreatetruecolor(32, 32);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 0, 0, 7, 7, imagecolorallocatealpha($im, 200, 10, 10, 0));
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    /** Solid CMYK JPEG (cyan 0, magenta 80, yellow 90). GD turns that into sRGB red. */
    private function cmykJpeg(): string
    {
        $im = new Imagick();
        $im->newImage(16, 16, new ImagickPixel('cmyk(0%,80%,90%,0%)'));
        $im->setImageColorspace(Imagick::COLORSPACE_CMYK);
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(95);
        $out = $im->getImageBlob();
        $im->clear();
        $this->assertIsString($out);
        return $out;
    }

    /** Same solid CMYK JPEG with an embedded ICC profile. GD ignores the profile and still writes sRGB. */
    private function cmykJpegWithProfile(string $icc): string
    {
        $im = new Imagick();
        $im->readImageBlob($this->cmykJpeg());
        $im->profileImage('icc', $icc);
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(95);
        $out = $im->getImageBlob();
        $im->clear();
        $this->assertIsString($out);
        return $out;
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
