<?php
declare(strict_types=1);

/**
 * Header limits and reduced decode for large photos.
 * A 48 MP phone frame is 8064×6048. A 200 MP sensor is 16384×12288.
 * The hard stop is 250 megapixels and 30,000 px on a side, which keeps both and refuses a wider frame.
 */
final class ImageDecodeTest extends DatabaseTestCase
{
    /** 128 MiB. A full 48 MP truecolor frame is about 195 MiB of process RSS, so this bound fails a full decode. */
    private const HWM_BUDGET_KB = 131072;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    public function testImagickResourceLimitsAreSet(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        $this->assertSame(IMAGICK_LIMIT_MEMORY, (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY));
        $this->assertSame(IMAGICK_LIMIT_MAP, (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_MAP));
        $this->assertSame(IMAGICK_LIMIT_DISK, (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_DISK));
        $this->assertSame(IMAGE_MAX_PIXELS, (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_AREA));
        $this->assertSame(IMAGE_MAX_SIDE, (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_WIDTH));
        $this->assertSame(IMAGE_MAX_SIDE, (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_HEIGHT));
    }

    public function testSmallJpegUsesGdOnly(): void
    {
        $jpeg = $this->solidJpeg(40, 32);
        $this->assertSame(['gd'], autofill_image_engines($jpeg));
        $this->assertSame(['gd'], autofill_pick_engines(false, true, true, true));
        if (class_exists('Imagick') && autofill_heic_supported()) {
            $this->assertSame(['imagick'], autofill_image_engines($this->heicSample()));
        }
        $name = $this->store($jpeg, 'small.jpg');
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $this->assertSame(hash('sha256', $jpeg), hash_file('sha256', $orig));
        $info = getimagesize(IMG_DIR . '/' . $name);
        $this->assertIsArray($info);
        $this->assertSame([40, 32], [$info[0], $info[1]]);
    }

    public function testImagickDoesNotSniffAnUnlistedFormat(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        $ppm = "P6\n2 2\n255\n" . str_repeat("\xFF\x00\x00", 4);
        $this->assertNull(autofill_analysis_jpeg($ppm, 32, 80, ['imagick']));
    }

    public function testSideOverTheLimitIsRefusedFromTheHeader(): void
    {
        $before = $this->tree(DATA_DIR);
        try {
            $this->store($this->pngHeader(30008, 8), 'wide.png');
            $this->fail('a frame wider than the side limit was stored');
        } catch (UserError $e) {
            $this->assertSame('Image dimensions are too large.', $e->getMessage());
        }
        $this->assertSame($before, $this->tree(DATA_DIR));
    }

    public function testPngAboveTheGdLimitIsRefusedBeforeDecode(): void
    {
        $before = $this->tree(DATA_DIR);
        try {
            $this->store($this->pngHeader(8000, 5008), 'big.png');
            $this->fail('a PNG above the GD budget was stored');
        } catch (UserError $e) {
            $this->assertSame('Image dimensions are too large.', $e->getMessage());
        }
        $this->assertSame($before, $this->tree(DATA_DIR));
    }

    public function testJustOverThePixelCapIsRefusedFromTheHeader(): void
    {
        $before = $this->tree(DATA_DIR);
        try {
            $this->store($this->jpegSof(16000, 15632), 'over.jpg');
            $this->fail('a frame over 250 megapixels was stored');
        } catch (UserError $e) {
            $this->assertSame('Image dimensions are too large.', $e->getMessage());
        }
        $this->assertSame($before, $this->tree(DATA_DIR));
    }

    public function testTruncatedLargeJpegIsUnreadableNotTooLarge(): void
    {
        try {
            $this->store($this->jpegSof(8064, 6048), 'short.jpg');
            $this->fail('a truncated JPEG was stored');
        } catch (UserError $e) {
            $expected = class_exists('Imagick') ? 'Could not read that image.' : 'Image dimensions are too large.';
            $this->assertSame($expected, $e->getMessage());
        }
    }

    public function testTwoHundredMegapixelJpegIsDownscaled(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        $this->assertDownscaled($this->solidJpeg(16384, 12288), 16384, 12288);
    }

    public function testJustUnderThePixelCapIsDownscaled(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        $this->assertSame(249_984_000, 16000 * 15624);
        $this->assertFalse(image_dimensions_too_large(16000, 15624));
        $this->assertDownscaled($this->solidJpeg(16000, 15624), 16000, 15624);
    }

    public function testLargeJpegKeepsOrientation(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        $jpeg = $this->withOrientation($this->solidJpeg(8000, 5008), 6);
        $name = $this->store($jpeg, 'sideways.jpg');
        $info = getimagesize(IMG_DIR . '/' . $name);
        $this->assertIsArray($info);
        $this->assertGreaterThan($info[0], $info[1], 'orientation 6 turns the landscape frame upright');
        $this->assertLessThanOrEqual(image_full_max(), max($info[0], $info[1]));
    }

    /**
     * Peak RSS for a 48 MP JPEG store and analysis, in a fresh process under memory_limit=256M.
     * memory_get_peak_usage does not include the GD or ImageMagick pixel cache (they allocate outside Zend),
     * so the bound that can fail a full-frame decode is VmHWM from /proc/self/status. The PHP peak is still
     * recorded and must sit under 256M. Without the jpeg:size hint, that VmHWM was 520588 KiB here
     * (ImageMagick Q16). The reduced decode stays under 128 MiB.
     */
    public function testFortyEightMegapixelJpegIsDownscaledUnderTheMemoryBudget(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        $this->assertFalse(image_dimensions_too_large(8064, 6048));
        $jpeg = $this->solidJpeg(8064, 6048);
        $root = dirname(__DIR__, 2);
        $data = sys_get_temp_dir() . '/apotheke-hwm-' . bin2hex(random_bytes(4));
        $path = $data . '-photo.jpg';
        $runner = $data . '-run.php';
        mkdir($data, 0775, true);
        file_put_contents($path, $jpeg);
        file_put_contents($runner, str_replace('__ROOT__', var_export($root, true), $this->memoryRunner()));
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $data,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
            'TZ' => 'UTC',
        ];
        $proc = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', $runner, $data, $path], $spec, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->removeTree($data);
        @unlink($path);
        @unlink($runner);
        $this->assertSame(0, $code, $err . $out);
        $row = json_decode((string)$out, true);
        $this->assertIsArray($row, $out . $err);
        $this->assertTrue($row['orig_ok']);
        $this->assertLessThanOrEqual(1600, max($row['w'], $row['h']));
        $this->assertLessThan(8064, max($row['w'], $row['h']));
        $this->assertLessThanOrEqual(1600, max($row['analysis_w'], $row['analysis_h']));
        $this->assertLessThanOrEqual(1024, max($row['prep_w'], $row['prep_h']));
        $this->assertLessThan(self::HWM_BUDGET_KB, $row['hwm_kb'], 'VmHWM ' . $row['hwm_kb'] . ' KiB');
        $this->assertLessThan(256 * 1024, $row['hwm_kb']);
        $this->assertLessThan(256 * 1024 * 1024, $row['php_peak']);
    }

    public function testHeicOverTheSideLimitIsRefusedBeforeDecode(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        $bytes = $this->withIspe($this->heicSample(), 30008, 8);
        $ping = image_ping_heic($bytes);
        $this->assertSame([30008, 8], $ping);
        $before = $this->tree(DATA_DIR);
        try {
            $this->store($bytes, 'wide.heic');
            $this->fail('an over-wide HEIC was stored');
        } catch (UserError $e) {
            $this->assertSame('Image dimensions are too large.', $e->getMessage());
        }
        $this->assertSame($before, $this->tree(DATA_DIR));
    }

    /**
     * A declared 48 MP or 200 MP HEIC header is inside the hard limit.
     * This build has no HEVC encoder, and ImageMagick does not scale HEIC while decoding, so the test does not
     * decode a real 48 MP HEIC frame. The memory bound for that size is the JPEG case. The small uncompressed
     * sample is stored when this Imagick can read it.
     */
    public function testDeclaredFortyEightMegapixelHeicIsWithinTheHeaderGate(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        $sample = $this->heicSample();
        $phone = image_ping_heic($this->withIspe($sample, 8064, 6048));
        $sensor = image_ping_heic($this->withIspe($sample, 16384, 12288));
        $this->assertSame([8064, 6048], $phone);
        $this->assertSame([16384, 12288], $sensor);
        $this->assertFalse(image_dimensions_too_large(8064, 6048));
        $this->assertFalse(image_dimensions_too_large(16384, 12288));
        $this->assertSame(48_771_072, 8064 * 6048);
        $this->assertSame(201_326_592, 16384 * 12288);

        $readable = false;
        try {
            $im = imagick_read_explicit($sample, 'HEIC');
            $readable = $im->getImageWidth() === 16 && $im->getImageHeight() === 16;
            $im->clear();
        } catch (Throwable) {
            $readable = false;
        }
        $name = $this->store($sample, 'tiny.heic');
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $this->assertSame(hash('sha256', $sample), hash_file('sha256', $orig));
        $info = getimagesize(IMG_DIR . '/' . $name);
        $this->assertIsArray($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertLessThanOrEqual(image_full_max(), max($info[0], $info[1]));
        if ($readable) $this->assertSame([16, 16], [$info[0], $info[1]]);
    }

    private function assertDownscaled(string $jpeg, int $w, int $h): void
    {
        $name = $this->store($jpeg, 'large.jpg');
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $this->assertSame(hash('sha256', $jpeg), hash_file('sha256', $orig));
        $info = getimagesize(IMG_DIR . '/' . $name);
        $this->assertIsArray($info);
        $this->assertLessThanOrEqual(image_full_max(), max($info[0], $info[1]));
        $this->assertLessThan($w, $info[0]);
        $this->assertLessThan($h, $info[1]);
        $analysis = autofill_analysis_jpeg($jpeg, 1024, 80, ['gd']);
        $this->assertIsString($analysis);
        $ai = getimagesizefromstring($analysis);
        $this->assertIsArray($ai);
        $this->assertLessThanOrEqual(1024, max($ai[0], $ai[1]));
    }

    /** @return list<string> */
    private function tree(string $dir): array
    {
        if (!is_dir($dir)) return [];
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
        @rmdir($dir);
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

    private function pngHeader(int $w, int $h): string
    {
        $data = pack('N', $w) . pack('N', $h) . "\x08\x02\x00\x00\x00";
        return "\x89PNG\r\n\x1a\n" . pack('N', strlen($data)) . 'IHDR' . $data . pack('N', crc32('IHDR' . $data));
    }

    /** Baseline SOF0 only. getimagesize reads it; there is no scan to decode. */
    private function jpegSof(int $w, int $h): string
    {
        return "\xFF\xD8\xFF\xC0" . pack('n', 11) . "\x08" . pack('n', $h) . pack('n', $w) . "\x01\x01\x11\x00";
    }

    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /** Grey 4:4:4 JPEG. Huffman and quantisation tables come from GD; the scan is a repeated end-of-block. */
    private function solidJpeg(int $w, int $h): string
    {
        if ($w % 8 !== 0 || $h % 8 !== 0) $this->fail('solidJpeg needs multiples of 8');
        [$dqt, $dht] = $this->jpegTables();
        $sof = "\xFF\xC0" . pack('n', 17) . "\x08" . pack('n', $h) . pack('n', $w) . "\x03"
            . "\x01\x11\x00" . "\x02\x11\x01" . "\x03\x11\x01";
        $sos = "\xFF\xDA" . pack('n', 12) . "\x03" . "\x01\x00" . "\x02\x11" . "\x03\x11" . "\x00\x3F\x00";
        $mcus = intdiv($w, 8) * intdiv($h, 8);
        $scan = str_repeat("\x28\x00\xA0\x02\x80\x00\x0A", intdiv($mcus, 4));
        $left = $mcus % 4;
        if ($left > 0) {
            $acc = 0;
            $nbits = 0;
            $extra = '';
            for ($i = 0; $i < $left; $i++) {
                $this->emitJpegBits($extra, $acc, $nbits, 0, 2);
                $this->emitJpegBits($extra, $acc, $nbits, 0b1010, 4);
                $this->emitJpegBits($extra, $acc, $nbits, 0, 2);
                $this->emitJpegBits($extra, $acc, $nbits, 0, 2);
                $this->emitJpegBits($extra, $acc, $nbits, 0, 2);
                $this->emitJpegBits($extra, $acc, $nbits, 0, 2);
            }
            if ($nbits > 0) $this->emitJpegBits($extra, $acc, $nbits, (1 << (8 - $nbits)) - 1, 8 - $nbits);
            $scan .= $extra;
        }
        $jpeg = "\xFF\xD8" . $dqt . $sof . $dht . $sos . $scan . "\xFF\xD9";
        if ($w <= 32 && $h <= 32) {
            $gd = imagecreatefromstring($jpeg);
            $this->assertInstanceOf(GdImage::class, $gd);
            $this->assertSame(0x808080, imagecolorat($gd, 0, 0) & 0xFFFFFF);
        }
        return $jpeg;
    }

    /** @return array{0:string,1:string} */
    private function jpegTables(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $im = imagecreatetruecolor(8, 8);
        imagefill($im, 0, 0, imagecolorallocate($im, 128, 128, 128));
        ob_start();
        imagejpeg($im, null, 75);
        $small = (string)ob_get_clean();
        $dqt = '';
        $dht = '';
        $i = 2;
        $n = strlen($small);
        while ($i + 4 < $n) {
            if ($small[$i] !== "\xFF") { $i++; continue; }
            $marker = ord($small[$i + 1]);
            while ($marker === 0xFF && $i + 2 < $n) { $i++; $marker = ord($small[$i + 1]); }
            if ($marker === 0xD8 || $marker === 0xD9 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { $i += 2; continue; }
            $len = unpack('n', substr($small, $i + 2, 2))[1];
            if ($marker === 0xDB) $dqt .= substr($small, $i, 2 + $len);
            if ($marker === 0xC4) $dht .= substr($small, $i, 2 + $len);
            if ($marker === 0xDA) break;
            $i += 2 + $len;
        }
        $this->assertNotSame('', $dqt);
        $this->assertNotSame('', $dht);
        return $cached = [$dqt, $dht];
    }

    private function emitJpegBits(string &$out, int &$acc, int &$nbits, int $code, int $len): void
    {
        $acc = ($acc << $len) | $code;
        $nbits += $len;
        while ($nbits >= 8) {
            $nbits -= 8;
            $byte = ($acc >> $nbits) & 0xFF;
            $out .= chr($byte);
            if ($byte === 0xFF) $out .= "\x00";
            $acc &= $nbits === 0 ? 0 : (1 << $nbits) - 1;
        }
    }

    private function heicSample(): string
    {
        $bytes = base64_decode('AAAAGGZ0eXBoZWljAAAAAG1pZjFtaWFmAAABB21ldGEAAAAAAAAAIWhkbHIAAAAAAAAAAHBpY3QAAAAAAAAAAAAAAAAAAAAADnBpdG0AAAAAAAEAAAAiaWxvYwAAAABEQAABAAEAAAAAAScAAQAAAAAAAAMAAAAAI2lpbmYAAAAAAAEAAAAVaW5mZQIAAAAAAQAAdW5jaQAAAACHaXBycAAAAGlpcGNvAAAAEmNtcGQAAAADAAQABQAGAAAAO3VuY0MAAAAAAAAAAAAAAAMAAAcAAAABBwAAAAIHAAAAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAUaXNwZQAAAAAAAAAQAAAAEAAAABZpcG1hAAAAAAAAAAEAAQOBgoMAAAMIbWRhdChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeChQeA==', true);
        $this->assertIsString($bytes);
        $this->assertSame('heic', substr($bytes, 8, 4));
        return $bytes;
    }

    private function withIspe(string $bytes, int $w, int $h): string
    {
        $pos = strpos($bytes, 'ispe');
        $this->assertNotFalse($pos);
        return substr($bytes, 0, $pos + 8) . pack('N', $w) . pack('N', $h) . substr($bytes, $pos + 16);
    }

    private function memoryRunner(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
$data = $argv[1];
$path = $argv[2];
putenv('DATA_DIR=' . $data);
putenv('APOTHEKE_CONFIG=/nonexistent');
putenv('SEED_DEFAULTS=0');
require __ROOT__ . '/src/bootstrap.php';
require __ROOT__ . '/src/items.php';
$bytes = (string)file_get_contents($path);
$analysis = autofill_analysis_jpeg($bytes, 1600, 82, ['gd']);
$prep = autofill_prepare_image($bytes, 1024);
$tmp = tempnam(sys_get_temp_dir(), 'up');
file_put_contents($tmp, $bytes);
try {
    $name = image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'name' => 'a.jpg']);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    @unlink($tmp);
}
$info = getimagesize(IMG_DIR . '/' . $name);
$orig = image_original_path($name);
$ai = is_string($analysis) ? getimagesizefromstring($analysis) : false;
$hwm = 0;
foreach (file('/proc/self/status') ?: [] as $line) {
    if (str_starts_with($line, 'VmHWM:')) $hwm = (int)preg_replace('/\D/', '', $line);
}
echo json_encode([
    'w' => (int)($info[0] ?? 0),
    'h' => (int)($info[1] ?? 0),
    'analysis_w' => (int)($ai[0] ?? 0),
    'analysis_h' => (int)($ai[1] ?? 0),
    'prep_w' => (int)($prep['width'] ?? 0),
    'prep_h' => (int)($prep['height'] ?? 0),
    'hwm_kb' => $hwm,
    'php_peak' => memory_get_peak_usage(true),
    'orig_ok' => $orig !== null && hash_file('sha256', $orig) === hash('sha256', $bytes),
]);
PHP;
    }
}
