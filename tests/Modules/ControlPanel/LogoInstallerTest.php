<?php

declare(strict_types=1);

namespace Tests\ControlPanel;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Settings\Branding\LogoInstaller;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/** The Control Panel logo upload (legacy brand_store() and svg_sanitize()): what is accepted, what is refused and why. */
final class LogoInstallerTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
        $this->files = [];
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    public function testAPngBecomesTheLogoAndTheIcons(): void
    {
        [$installer, $store, $settings] = $this->installer();
        $this->assertSame('png', $installer->install(UPLOAD_ERR_OK, $this->file($this->png(96, 64)), 100, true, self::errorText(...)));
        $this->assertSame('png', $settings->get('logo'));
        foreach (['logo.png', 'icon-32.png', 'icon-180.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png', 'favicon.ico'] as $name) {
            $this->assertTrue($store->has($name), $name);
        }
        $this->assertSame([512, 512], array_slice((array)getimagesizefromstring((string)$store->read('logo.png')), 0, 2));
        $installer->reset();
        $this->assertSame('', $settings->get('logo'));
        $this->assertFalse($store->has('logo.png'));
    }

    public function testAnSvgIsCleaned(): void
    {
        [$installer, $store, $settings] = $this->installer();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="x()">'
            . '<script>x()</script><foreignObject><p>hi</p></foreignObject><a xlink:href="https://elsewhere.example/">'
            . '<rect width="10" height="10" fill="url(https://elsewhere.example/p)" style="fill:red" onclick="x()"/></a>'
            . '<use href="https://elsewhere.example/s.svg#a"/><circle cx="5" cy="5" r="2" fill="#fff"/></svg>';
        $this->assertSame('svg', $installer->install(UPLOAD_ERR_OK, $this->file($svg), strlen($svg), true, self::errorText(...)));
        $this->assertSame('svg', $settings->get('logo'));
        $clean = (string)$store->read('logo.svg');
        foreach (['<script', 'onload', 'onclick', 'foreignObject', 'elsewhere.example', 'style='] as $gone) {
            $this->assertStringNotContainsString($gone, $clean, $gone);
        }
        $this->assertStringContainsString('<circle', $clean);
        $this->assertFalse($store->has('logo.png'), 'a new logo replaces the old files');
    }

    /** @return iterable<string,array{0:int,1:string|null,2:bool,3:string}> */
    public static function refused(): iterable
    {
        yield 'upload error' => [UPLOAD_ERR_INI_SIZE, null, true, 'error 1'];
        yield 'not an upload' => [UPLOAD_ERR_OK, 'png', false, 'Invalid upload.'];
        yield 'too small' => [UPLOAD_ERR_OK, 'tiny', true, 'The logo must be at least 64×64 pixels.'];
        yield 'not an image' => [UPLOAD_ERR_OK, "hello\n", true, 'Unsupported logo. Use SVG, PNG, JPEG or WebP.'];
        yield 'svg with a doctype' => [UPLOAD_ERR_OK, '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg viewBox="0 0 1 1"/>', true, 'SVG files with a DOCTYPE or entities are not accepted.'];
        yield 'svg without a size' => [UPLOAD_ERR_OK, '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>', true, 'The SVG needs a viewBox (or width and height).'];
        yield 'svg too large' => [UPLOAD_ERR_OK, '<svg viewBox="0 0 1 1">' . str_repeat(' ', 512 * 1024) . '</svg>', true, 'That SVG is too large (max 512 KB).'];
    }

    #[DataProvider('refused')]
    public function testRefusedUploadsChangeNothing(int $error, ?string $data, bool $uploaded, string $message): void
    {
        [$installer, $store, $settings] = $this->installer();
        $installer->install(UPLOAD_ERR_OK, $this->file($this->png(64, 64)), 100, true, self::errorText(...));
        $bytes = match ($data) {
            'png' => $this->png(64, 64),
            'tiny' => $this->png(10, 10),
            default => (string)$data,
        };
        try {
            $installer->install($error, $this->file($bytes), strlen($bytes), $uploaded, self::errorText(...));
            $this->fail('accepted: ' . $message);
        } catch (UserError $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame('png', $settings->get('logo'));
        $this->assertTrue($store->has('logo.png'));
    }

    public function testTheSizeLimitIsCheckedFirst(): void
    {
        [$installer] = $this->installer();
        $this->expectExceptionMessage('That logo is too large (max 2 MB).');
        $installer->install(UPLOAD_ERR_OK, $this->file($this->png(64, 64)), 2 * 1024 * 1024 + 1, true, self::errorText(...));
    }

    private static function errorText(int $code): string
    {
        return 'error ' . $code;
    }

    /** @return array{0:LogoInstaller,1:LocalBrandStore,2:SettingsService} */
    private function installer(): array
    {
        $this->database = TestDatabase::create('sqlite');
        $store = new LocalBrandStore($this->database->dataDir() . '/brand');
        $settings = new SettingsService(new SettingsRepository($this->database->db), new SettingsSchema(), $store, new Clock(), $this->database->config->values(),
            static function (string $message): void {
            });

        return [new LogoInstaller($store, $settings), $store, $settings];
    }

    private function file(string $data): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'apotheke-logo-');
        file_put_contents($path, $data);
        $this->files[] = $path;

        return $path;
    }

    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int)imagecolorallocate($im, 200, 80, 45));
        ob_start();
        imagepng($im);

        return (string)ob_get_clean();
    }
}
