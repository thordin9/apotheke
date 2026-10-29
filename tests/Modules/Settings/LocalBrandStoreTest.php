<?php

declare(strict_types=1);

namespace Tests\Settings;

use Apotheke\Settings\BrandStore;
use Apotheke\Settings\LocalBrandStore;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class LocalBrandStoreTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/apotheke-brand-' . bin2hex(random_bytes(4)) . '/brand';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        @rmdir(dirname($this->dir));
        parent::tearDown();
    }

    public function testPutReadClear(): void
    {
        $store = new LocalBrandStore($this->dir);
        $this->assertFalse($store->has('logo.png'));
        $this->assertNull($store->read('logo.png'));
        $this->assertNull($store->size('logo.png'));
        $this->assertNull($store->modifiedAt('logo.png'));
        $store->clear();   // nothing there yet
        $store->put('logo.png', 'PNG');
        $store->put('icon-192.png', 'ICON');
        $this->assertTrue($store->has('logo.png'));
        $this->assertSame('PNG', $store->read('logo.png'));
        $this->assertSame(3, $store->size('logo.png'));
        $this->assertIsInt($store->modifiedAt('logo.png'));
        $store->clear();
        $this->assertFalse($store->has('logo.png'));
        $this->assertFalse($store->has('icon-192.png'));
    }

    public function testOnlyKnownNames(): void
    {
        $store = new LocalBrandStore($this->dir);
        $this->assertFalse($store->has('../config.php'));
        $this->assertNull($store->read('../config.php'));
        $this->expectException(InvalidArgumentException::class);
        $store->put('../evil.php', 'x');
    }

    public function testTheFileListMatchesLegacy(): void
    {
        $this->assertSame(['logo.svg', 'logo.png', 'icon-32.png', 'icon-180.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png', 'favicon.ico'], array_keys(BrandStore::FILES));
    }
}
