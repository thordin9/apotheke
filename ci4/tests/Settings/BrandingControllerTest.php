<?php

declare(strict_types=1);

namespace Tests\Settings;

use Apotheke\Core\Libraries\AssetUrl;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Settings\Branding\Accent;
use Apotheke\Settings\Branding\Branding;
use Apotheke\Settings\Controllers\BrandingController;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\TestDatabase;

/**
 * The branding routes through CI4 routing and filters, with the services
 * injected. Byte parity with legacy is in tests/Unit/Ci4BrandingHttpTest.php.
 */
final class BrandingControllerTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private TestDatabase $database;

    private SettingsService $settings;

    private LocalBrandStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = TestDatabase::create('sqlite');
        $this->store = new LocalBrandStore($this->database->dataDir() . '/brand');
        $this->settings = new SettingsService(new SettingsRepository($this->database->db), new SettingsSchema(), $this->store, new Clock(), $this->database->config->values(), static fn (string $m): null => null);
        Services::injectMock('settings', $this->settings);
        Services::injectMock('brandStore', $this->store);
        Services::injectMock('branding', new Branding($this->settings, $this->store, new AssetUrl(ApothekeConfig::repoRoot() . '/public/assets')));
    }

    protected function tearDown(): void
    {
        $this->database->drop();
        parent::tearDown();
    }

    public function testThemeCss(): void
    {
        $r = $this->get('theme.css');
        $r->assertStatus(200);
        $this->assertSame('', $r->response()->getBody(), 'default accent: empty stylesheet');
        $this->assertSame('text/css; charset=utf-8', $r->response()->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache', $r->response()->getHeaderLine('Cache-Control'));
        $this->assertSame([], $this->settings->save(['accent' => '#0f766e']));
        $r = $this->get('theme.css?v=abc');
        $this->assertSame(Accent::css('#0F766E'), $r->response()->getBody());
        $this->assertStringContainsString('--accent:#0f766e', $r->response()->getBody());
        $this->assertSame('public, max-age=31536000, immutable', $r->response()->getHeaderLine('Cache-Control'));
    }

    public function testLabelsCss(): void
    {
        $this->settings->store(['label_width_mm' => 50, 'label_height_mm' => 30, 'label_columns' => 3, 'label_gap_mm' => 2, 'label_border' => false]);
        $body = $this->get('labels.css')->response()->getBody();
        $this->assertStringContainsString('grid-template-columns:repeat(3,50mm)!important;gap:2mm!important', $body);
        $this->assertStringContainsString(';border:0!important}', $body);
    }

    public function testManifest(): void
    {
        $this->settings->store(['app_name' => 'Πρίζα/Box']);
        $r = $this->get('manifest.webmanifest');
        $this->assertSame('application/manifest+json', $r->response()->getHeaderLine('Content-Type'));
        $data = json_decode($r->response()->getBody(), true);
        $this->assertSame('Πρίζα/Box', $data['name']);
        $this->assertStringContainsString('"Πρίζα/Box"', $r->response()->getBody(), 'unescaped slashes and unicode');
    }

    public function testFaviconFallsBackToTheBundledIcon(): void
    {
        $r = $this->get('favicon.ico');
        $bundled = (string)file_get_contents(ApothekeConfig::repoRoot() . '/public/assets/favicon.ico');
        $this->assertSame($bundled, $r->response()->getBody());
        $this->assertSame((string)strlen($bundled), $r->response()->getHeaderLine('Content-Length'));
        $this->assertSame('public, max-age=86400', $r->response()->getHeaderLine('Cache-Control'));
        $this->store->put('favicon.ico', 'ICO-BYTES');
        $this->settings->store(['logo' => 'png']);
        $this->assertSame('ICO-BYTES', $this->get('favicon.ico')->response()->getBody());
    }

    public function testBrandFiles(): void
    {
        $this->get('brand/logo.svg')->assertStatus(404);
        $this->store->put('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $r = $this->get('brand/logo.svg');
        $r->assertStatus(200);
        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg"/>', $r->response()->getBody());
        $this->assertSame('image/svg+xml', $r->response()->getHeaderLine('Content-Type'));
        $this->assertSame(BrandingController::BRAND_CSP, $r->response()->getHeaderLine('Content-Security-Policy'), 'the filter keeps the brand CSP');
        $this->assertSame('public, max-age=31536000, immutable', $r->response()->getHeaderLine('Cache-Control'));
        $missing = $this->get('brand/evil.php');
        $missing->assertStatus(404);
        $this->assertSame('', $missing->response()->getBody());
    }

    public function testBrandingPathsDoNotStartASession(): void
    {
        $this->get('theme.css');
        $this->get('brand/logo.svg');
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }
}
