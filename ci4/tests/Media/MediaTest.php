<?php

declare(strict_types=1);

namespace Tests\Media;

use Apotheke\Auth\CurrentUser;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Media\ImageLimits;
use Apotheke\Media\ImageStore;
use Apotheke\Media\Storage\ImagickDecoder;
use Apotheke\Media\Storage\OriginalStore;
use Apotheke\Media\Storage\ServedImageWriter;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\BadRequestException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\TestDatabase;

/**
 * PLAN step 10: uploads keep a byte-identical original and a stripped served
 * copy in main's layout, with main's decode limits (#72, #79), and /media
 * serves only served names.
 */
final class MediaTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private TestDatabase $database;

    private SettingsService $settings;

    private ImageStore $store;

    private ServedImageWriter $served;

    private RequestCleanup $cleanup;

    /** @var list<string> */
    private array $logged = [];

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = TestDatabase::create('sqlite');
        $this->settings = new SettingsService(new SettingsRepository($this->database->db), new SettingsSchema(), new LocalBrandStore($this->database->dataDir() . '/brand'), new Clock(), $this->database->config->values(), static fn (string $m): null => null, new CommandLine());
        $this->served = new ServedImageWriter(new LocalStorage($this->database->dataDir()));
        ImageLimits::applyImagickLimits();
        $this->store = new ImageStore($this->settings, new OriginalStore(new LocalStorage($this->originals(), 0640)), $this->served, new ImagickDecoder(), $this->cleanup = new RequestCleanup(function (string $line): void {
            $this->logged[] = $line;
        }));
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        $this->database->drop();
        Services::reset(true);
        parent::tearDown();
    }

    public function testTheLimitsAreMains(): void
    {
        $this->assertFalse(ImageLimits::rasterHeaderOverLimit(30000, 8000, IMAGETYPE_JPEG), 'a JPEG is decoded reduced');
        $this->assertTrue(ImageLimits::rasterHeaderOverLimit(30001, 8, IMAGETYPE_JPEG));
        $this->assertTrue(ImageLimits::rasterHeaderOverLimit(16000, 16000, IMAGETYPE_JPEG), 'over 250 MP');
        $this->assertFalse(ImageLimits::rasterHeaderOverLimit(6500, 4000, IMAGETYPE_PNG));
        $this->assertTrue(ImageLimits::rasterHeaderOverLimit(6500, 4001, IMAGETYPE_PNG), 'PNG over 26 MP');
        $this->assertFalse(ImageLimits::rasterHeaderOverLimit(8000, 5000, IMAGETYPE_WEBP));
        $this->assertTrue(ImageLimits::rasterHeaderOverLimit(8000, 5001, IMAGETYPE_GIF), 'GIF over 40 MP');
        $this->assertFalse(ImageLimits::heicDimensionsTooLarge(16384, 7812));
        $this->assertTrue(ImageLimits::heicDimensionsTooLarge(16385, 8));
        $this->assertTrue(ImageLimits::heicDimensionsTooLarge(16000, 8008), 'HEIC over 128 MP');
        $this->assertFalse(ImageLimits::heicHeaderOverLimit(null), 'an unreadable header is not this refusal');
        $this->assertSame('1600x1200', ImageLimits::jpegSizeHint(8000, 6000, 1600));
        $this->assertNull(ImageLimits::jpegSizeHint(1600, 900, 1600));
        $policy = (string)file_get_contents(ApothekeConfig::repoRoot() . '/docker/imagemagick-policy.xml');
        $this->assertMatchesRegularExpression('/name="width" value="' . ImageLimits::HEIC_MAX_SIDE . '"/', $policy);
    }

    public function testAJpegKeepsItsOriginalAndServesARotatedStrippedCopy(): void
    {
        $bytes = $this->withOrientation6($this->jpeg(2000, 1000));
        $name = $this->upload($bytes);
        $this->assertTrue(ImageStore::validName($name));
        $base = trim((string)file_get_contents($this->originals() . '/map/' . substr($name, 0, 32)));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}\.jpg$/D', $base);
        $this->assertSame($bytes, file_get_contents($this->originals() . '/' . $base), 'byte-identical original');
        $this->assertSame(0640, fileperms($this->originals() . '/' . $base) & 0777);
        $this->assertSame(0640, fileperms($this->originals() . '/map/' . substr($name, 0, 32)) & 0777);
        $full = (string)$this->served->read('full', $name);
        $thumb = (string)$this->served->read('thumb', $name);
        $this->assertSame([800, 1600], array_slice((array)getimagesizefromstring($full), 0, 2), 'orientation 6 applied, long side 1600');
        $this->assertSame([240, 480], array_slice((array)getimagesizefromstring($thumb), 0, 2));
        $this->assertStringNotContainsString('Exif', $full);
        $this->assertStringNotContainsString('Exif', $thumb);
    }

    public function testServedBytesMatchMain(): void
    {
        $png = $this->png(900, 500, true);
        $jpeg = $this->withOrientation6($this->jpeg(1700, 900));
        foreach (['png' => $png, 'jpeg' => $jpeg] as $label => $bytes) {
            $name = $this->upload($bytes);
            $legacy = $this->legacyStore($bytes);
            $this->assertSame($legacy['full'], $this->served->read('full', $name), $label . ' full');
            $this->assertSame($legacy['thumb'], $this->served->read('thumb', $name), $label . ' thumb');
        }
    }

    public function testHeaderLimitsRefuseBeforeAnythingIsStored(): void
    {
        $before = $this->tree();
        foreach ([
            'PNG 6500x4001' => $this->pngHeader(6500, 4001),
            'GIF 8000x5001' => 'GIF89a' . pack('vv', 8000, 5001) . "\x00\x00\x00;",
            'JPEG 30001x8' => $this->jpegHeader(30001, 8),
        ] as $label => $bytes) {
            $this->assertUserError(ImageLimits::TOO_LARGE, $bytes, $label);
        }
        $this->assertSame($before, $this->tree());
    }

    public function testFailedDecodesLeaveNothingBehind(): void
    {
        $before = $this->tree();
        $this->assertUserError('Could not read that image.', $this->jpegHeader(400, 300), 'corrupt JPEG');
        $this->assertUserError(class_exists('Imagick') ? 'Could not read that image.' : ImageLimits::TOO_LARGE, $this->jpegHeader(8000, 6000), '48 MP JPEG that Imagick cannot read');
        $this->assertUserError('Unsupported image. Please use JPEG, PNG, WebP or GIF (on iPhone, HEIC photos are converted automatically).', 'plain text', 'text');
        $this->assertSame($before, $this->tree(), 'no original, map entry or served file is left');
    }

    public function testAnUnreadableHeicKeepsTheOriginalAndGetsThePlaceholder(): void
    {
        $bytes = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64);
        $name = $this->upload($bytes);
        $base = trim((string)file_get_contents($this->originals() . '/map/' . substr($name, 0, 32)));
        $this->assertStringEndsWith('.heic', $base);
        $this->assertSame($bytes, file_get_contents($this->originals() . '/' . $base));
        $full = (string)$this->served->read('full', $name);
        $this->assertSame([64, 64], array_slice((array)getimagesizefromstring($full), 0, 2));
        $gd = imagecreatefromstring($full);
        $this->assertNotFalse($gd);
        $this->assertSame([226, 226, 226], array_values(array_slice(imagecolorsforindex($gd, imagecolorat($gd, 10, 10)), 0, 3)));
    }

    /**
     * Needs Imagick with libheif. CI must run it (the ci4 legs install the HEIC
     * coder and run it again with --fail-on-skipped), so CI=true fails instead of skipping.
     */
    public function testARealHeicIsConvertedWhenImagickCanAndRefusedOverTheCaps(): void
    {
        $decoder = new ImagickDecoder();
        if (!$decoder->heicSupported()) {
            if (getenv('CI') === 'true') {
                $this->fail('CI has no HEIC decoder: the real-HEIC test must run there');
            }
            $this->markTestSkipped('Imagick without HEIC');
        }
        $bytes = (string)file_get_contents(ApothekeConfig::repoRoot() . '/tests/fixtures/tiny-gps.heic');
        $name = $this->upload($bytes);
        $full = (string)$this->served->read('full', $name);
        $this->assertSame("\xFF\xD8", substr($full, 0, 2));
        $this->assertNotSame([64, 64], array_slice((array)getimagesizefromstring($full), 0, 2), 'converted, not the placeholder');
        $this->assertStringNotContainsString('GPS', $full);

        $before = $this->tree();
        foreach ([[16385, 8], [16000, 8008]] as [$w, $h]) {
            $declared = $this->withIspe($bytes, $w, $h);
            $this->assertSame([$w, $h], $decoder->pingHeic($declared));
            $this->assertUserError(ImageLimits::TOO_LARGE, $declared, 'HEIC ' . $w . 'x' . $h);
            $this->assertSame($before, $this->tree(), 'a header-refused HEIC writes nothing');
        }
    }

    /**
     * M-20: CodeIgniter's shutdown handler exits on a fatal error, so the
     * request's RequestCleanup (run by the exception handler) has to remove
     * what an unfinished upload wrote. Real CI4 bootstrap, in its own process.
     */
    public function testAFatalAfterTheOriginalIsSavedLeavesNoFiles(): void
    {
        $data = $this->database->dataDir();
        $before = $this->tree();
        $jpeg = $this->tempFile($this->jpeg(120, 80));
        $env = ['DATA_DIR' => $data, 'APOTHEKE_CONFIG' => '/nonexistent', 'SEED_DEFAULTS' => '0', 'DB_DRIVER' => 'sqlite', 'CI_ENVIRONMENT' => 'production', 'PATH' => (string)getenv('PATH')];
        $cmd = [PHP_BINARY, '-d', 'memory_limit=64M', __DIR__ . '/fixtures/fatal-on-thumb.php', $jpeg];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        $exit = proc_close($proc);
        $this->assertStringContainsString('before-fatal originals=1 map=1 full=1', $out, $out . $err);
        $this->assertStringNotContainsString('stored', $out);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Allowed memory size', $out . $err . $this->ci4Log(), 'a real out-of-memory fatal');
        $after = array_values(array_diff($this->tree(), ['originals/.htaccess'])); // the boot's deny file
        $this->assertSame($before, $after, 'the original, its map entry and the full copy are gone');
    }

    public function testRequestCleanupRunsOnceAndDropsFinishedWork(): void
    {
        $logged = [];
        $cleanup = new RequestCleanup(static function (string $line) use (&$logged): void {
            $logged[] = $line;
        });
        $ran = [];
        $cleanup->add('a', static function () use (&$ran): void {
            $ran[] = 'a';
        });
        $cleanup->add('media:' . str_repeat('b', 32) . '.jpg', static function (): void {
            throw new \RuntimeException('could not unlink /data/originals/secret-path');
        });
        $cleanup->add('c', static function () use (&$ran): void {
            $ran[] = 'c';
        });
        $cleanup->add('d', static function () use (&$ran): void {
            $ran[] = 'd';
        });
        $cleanup->drop('c');
        $cleanup->run();
        $cleanup->run();
        $this->assertSame(['a', 'd'], $ran, 'a step after a failing one still runs; a dropped one never runs; the list resets');
        $this->assertSame(['[apotheke] request cleanup step failed: media:' . str_repeat('b', 32) . '.jpg (RuntimeException)'], $logged, 'key and class, never the message or a path');
    }

    /** N-31 (a): a finished upload is dropped from the request's list, so running it later removes nothing. */
    public function testAStoredUploadSurvivesTheRequestCleanup(): void
    {
        $name = $this->upload($this->jpeg(60, 40));
        $files = $this->tree();
        $this->assertContains('images/' . $name, $files);
        $this->assertContains('thumbs/' . $name, $files);
        $this->assertContains('originals/map/' . substr($name, 0, 32), $files);
        $this->assertCount(1, preg_grep('#^originals/[a-f0-9]{64}\.jpg$#', $files) ?: []);
        $this->cleanup->run();
        $this->assertSame($files, $this->tree(), 'the original, its map entry, the full copy and the thumb all remain');
        $this->assertSame([], $this->logged);
    }

    public function testTheSizeLimitIsMaxUploadMb(): void
    {
        $this->settings->store(['max_upload_mb' => 3]);
        $this->assertSame(3 * 1024 * 1024, $this->store->maxUpload(), 'the forms\' MAX_FILE_SIZE value');
        $tmp = $this->tempFile($this->jpeg(10, 10));
        foreach ([[3 * 1024 * 1024 + 1, UPLOAD_ERR_OK], [10, UPLOAD_ERR_FORM_SIZE], [10, UPLOAD_ERR_INI_SIZE]] as [$size, $error]) {
            try {
                $this->store->store($tmp, $size, $error);
                $this->fail('stored');
            } catch (UserError $e) {
                $this->assertSame('That photo is too large (max 3 MB).', $e->getMessage());
            }
        }
        $this->assertSame('The upload was interrupted. Please try again.', $this->store->uploadErrorText(UPLOAD_ERR_PARTIAL));
        $this->assertTrue(ImageStore::validName($this->store->store($tmp, 3 * 1024 * 1024)));
    }

    public function testDeleteRemovesEveryFile(): void
    {
        $before = $this->tree();
        $name = $this->upload($this->jpeg(50, 40));
        $this->assertNotSame($before, $this->tree());
        $this->store->delete($name);
        $this->store->delete('../../catalog.sqlite');
        $this->assertSame($before, $this->tree());
    }

    public function testMediaServesOnlyServedNames(): void
    {
        $this->wire();
        $name = $this->upload($this->jpeg(50, 40));
        $r = $this->get('media/full/' . $name);
        $r->assertStatus(200);
        $res = $r->response();
        $this->assertSame($this->served->read('full', $name), $res->getBody());
        $this->assertSame('image/jpeg', $res->getHeaderLine('Content-Type'));
        $this->assertSame('private, max-age=31536000, immutable', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $etag = '"' . $name . '-' . filemtime($this->database->dataDir() . '/images/' . $name) . '"';
        $this->assertSame($etag, $res->getHeaderLine('ETag'));
        $this->assertSame((string)strlen((string)$res->getBody()), $res->getHeaderLine('Content-Length'));
        $this->assertSame($this->served->read('thumb', $name), $this->get('media/thumb/' . $name)->response()->getBody());

        $cached = $this->withHeaders(['If-None-Match' => $etag])->get('media/full/' . $name);
        $cached->assertStatus(304);
        $this->assertSame('', (string)$cached->response()->getBody());
        $this->withHeaders([]);

        $base = trim((string)file_get_contents($this->originals() . '/map/' . substr($name, 0, 32)));
        foreach ([
            'media/full/' . strtoupper(substr($name, 0, 32)) . '.jpg', 'media/full/' . substr($name, 0, 32) . '.jpeg',
            'media/full/' . $base, 'media/full/' . str_repeat('a', 32) . '.jpg', 'media/big/' . $name,
            'media/full/..%2F..%2Fcatalog.sqlite', 'media/full/%2e%2e', 'media/full/' . $name . '%00',
            'media/full/' . $name . '/x', 'media/original/' . $name, 'media/full/../thumbs/' . $name,
        ] as $path) {
            try {
                $res = $this->get($path)->response();
            } catch (PageNotFoundException | BadRequestException) {
                continue; // no route (the app's 404 page), or a URI CI4 refuses (400)
            }
            $this->assertSame(404, $res->getStatusCode(), $path);
            $this->assertSame('', (string)$res->getBody(), $path);
        }
        $this->assertSame(404, $this->get('media/full/' . $base)->response()->getStatusCode());
        $this->assertSame('', (string)$this->get('media/full/' . str_repeat('b', 32) . '.jpg')->response()->getBody());
    }

    public function testTheEndOfEveryRequestRunsTheCleanup(): void
    {
        $this->wire();
        $cleanup = new RequestCleanup();
        Services::injectMock('requestCleanup', $cleanup);
        $ran = 0;
        $cleanup->add('left', static function () use (&$ran): void {
            $ran++;
        });
        $this->get('media/full/' . str_repeat('c', 32) . '.jpg')->assertStatus(404);
        $this->assertSame(1, $ran, 'the requestcleanup after filter ran it');
        $this->get('healthz')->assertStatus(200);
        $this->assertSame(1, $ran, 'and reset the list');
    }

    public function testValidNameIsAnchored(): void
    {
        $name = str_repeat('a', 32) . '.jpg';
        $this->assertTrue(ImageStore::validName($name));
        $this->assertFalse(ImageStore::validName($name . "\n"), '/D: no trailing newline');
        $this->assertFalse(ImageStore::validName(str_repeat('A', 32) . '.jpg'), 'uppercase hex');
        $this->assertFalse(ImageStore::validName(null));
    }

    public function testABadMapEntryIsIgnoredAndNothingOutsideIsDeleted(): void
    {
        $originals = new OriginalStore(new LocalStorage($this->originals(), 0640));
        $name = $this->upload($this->jpeg(30, 20));
        $map = $this->originals() . '/map/' . substr($name, 0, 32);
        $base = trim((string)file_get_contents($map));
        foreach (['../catalog.sqlite', '../../' . basename($this->database->dataDir()) . '/catalog.sqlite', strtoupper($base), $base . "\n/x", ''] as $bad) {
            file_put_contents($map, $bad);
            $this->assertNull($originals->read($name), var_export($bad, true));
        }
        $originals->forget($name);
        $this->assertFileDoesNotExist($map);
        $this->assertFileExists($this->originals() . '/' . $base, 'a bad pointer deletes no file but the map entry');
        $this->assertFileExists($this->database->dataDir() . '/catalog.sqlite');
        $this->assertNotNull($this->served->read('full', $name));
    }

    public function testOnlyAnExactIfNoneMatchGets304(): void
    {
        $this->wire();
        $name = $this->upload($this->jpeg(50, 40));
        $etag = '"' . $name . '-' . filemtime($this->database->dataDir() . '/images/' . $name) . '"';
        // Same as main's serve_image(): an exact string compare, so a weak or a list form gets the full answer.
        foreach (['W/' . $etag, '"x", ' . $etag, $etag . ', "x"', '*'] as $header) {
            $r = $this->withHeaders(['If-None-Match' => $header])->get('media/full/' . $name);
            $r->assertStatus(200);
            $this->assertSame($this->served->read('full', $name), $r->response()->getBody(), $header);
        }
        $this->withHeaders([]);
    }

    public function testAPlantedFileWithAnInvalidNameIsNotServed(): void
    {
        $this->wire();
        $images = $this->database->dataDir() . '/images';
        @mkdir($images, 0775, true);
        $jpeg = $this->jpeg(10, 10);
        foreach (['evil.jpg', str_repeat('A', 32) . '.jpg', str_repeat('a', 32) . '.png', str_repeat('a', 31) . '.jpg', 'catalog.jpg'] as $planted) {
            file_put_contents($images . '/' . $planted, $jpeg);
            $res = $this->get('media/full/' . $planted)->response();
            $this->assertSame(404, $res->getStatusCode(), $planted);
            $this->assertSame('', (string)$res->getBody(), $planted);
        }
    }

    private function ci4Log(): string
    {
        $out = '';
        foreach (glob(ApothekeConfig::repoRoot() . '/ci4/writable/logs/*.log') ?: [] as $log) {
            $out .= (string)file_get_contents($log);
        }

        return $out;
    }

    private function withIspe(string $bytes, int $w, int $h): string
    {
        $pos = strpos($bytes, 'ispe');
        $this->assertNotFalse($pos);

        return substr($bytes, 0, $pos + 8) . pack('NN', $w, $h) . substr($bytes, $pos + 16);
    }

    private function wire(): void
    {
        Services::injectMock('settings', $this->settings);
        Services::injectMock('currentUser', new CurrentUser(fn (): SettingsService => $this->settings, new UserRepository($this->database->db), static fn (): array => []));
        Services::injectMock('roles', new RoleRepository($this->database->db));
        Services::injectMock('servedImages', $this->served);
    }

    private function originals(): string
    {
        return $this->database->config->originalsDir();
    }

    private function upload(string $bytes): string
    {
        return $this->store->store($this->tempFile($bytes), strlen($bytes));
    }

    private function assertUserError(string $message, string $bytes, string $label): void
    {
        try {
            $this->upload($bytes);
            $this->fail($label . ' was stored');
        } catch (UserError $e) {
            $this->assertSame($message, $e->getMessage(), $label);
        }
    }

    private function tempFile(string $bytes): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return $path;
    }

    /** @return list<string> files under DATA_DIR other than the database and sessions */
    private function tree(): array
    {
        $out = [];
        $root = $this->database->dataDir();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = substr((string)$file, strlen($root) + 1);
            if (!str_starts_with($rel, 'catalog.sqlite') && !str_starts_with($rel, 'sessions/')) {
                $out[] = $rel;
            }
        }
        sort($out);

        return $out;
    }

    /** @return array{full:string,thumb:string} main's image_store() on the same bytes, in its own process */
    private function legacyStore(string $bytes): array
    {
        $root = ApothekeConfig::repoRoot();
        $data = sys_get_temp_dir() . '/apotheke-legacy-media-' . bin2hex(random_bytes(4));
        mkdir($data, 0775, true);
        $src = $this->tempFile($bytes);
        $code = 'require ' . var_export($root . '/src/bootstrap.php', true) . '; require ' . var_export($root . '/src/items.php', true) . ';'
            . ' echo image_store(["error" => 0, "tmp_name" => $argv[1], "size" => filesize($argv[1])]);';
        $env = ['DATA_DIR' => $data, 'APOTHEKE_CONFIG' => '/nonexistent', 'SEED_DEFAULTS' => '0', 'DB_DRIVER' => 'sqlite', 'PATH' => (string)getenv('PATH')];
        $proc = proc_open([PHP_BINARY, '-r', $code, $src], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        $name = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc), $err);
        $this->assertTrue(ImageStore::validName($name), $name . $err);
        $out = ['full' => (string)file_get_contents($data . '/images/' . $name), 'thumb' => (string)file_get_contents($data . '/thumbs/' . $name)];
        exec('rm -rf ' . escapeshellarg($data));

        return $out;
    }

    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x += 10) {
            imagefilledrectangle($im, $x, 0, $x + 4, (int)($h / 2), (int)imagecolorallocate($im, $x % 256, 80, 160));
        }
        ob_start();
        imagejpeg($im, null, 90);

        return (string)ob_get_clean();
    }

    private function png(int $w, int $h, bool $alpha): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, $alpha);
        imagefill($im, 0, 0, (int)imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, (int)($w / 2), (int)($h / 2), (int)($w / 2), (int)($h / 2), (int)imagecolorallocatealpha($im, 200, 40, 40, 30));
        ob_start();
        imagepng($im);

        return (string)ob_get_clean();
    }

    /** An EXIF APP1 segment with Orientation 6 (rotate 90° clockwise) right after SOI. */
    private function withOrientation6(string $jpeg): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;

        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /** A PNG signature and IHDR only: enough for the header check, no pixels. */
    private function pngHeader(int $w, int $h): string
    {
        $ihdr = 'IHDR' . pack('NN', $w, $h) . "\x08\x06\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
    }

    /** SOI and a baseline SOF0 with the given size, then junk: a header that never decodes. */
    private function jpegHeader(int $w, int $h): string
    {
        return "\xFF\xD8\xFF\xC0\x00\x11\x08" . pack('nn', $h, $w) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01" . str_repeat("\x00", 64) . "\xFF\xD9";
    }
}
