<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Storage\LocalStorage;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/** Local disk storage: relative keys only, rooted at the directory it was given. */
final class LocalStorageTest extends CIUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/apotheke-storage-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testRoundTripAndMissingKey(): void
    {
        $store = new LocalStorage($this->root);
        $this->assertNull($store->read('autofill/info.json'));
        $this->assertFalse($store->exists('autofill/info.json'));
        $store->write('autofill/info.json', '{"clamp":1}');
        $this->assertSame('{"clamp":1}', $store->read('autofill/info.json'));
        $this->assertSame('{"clamp":1}', (string)file_get_contents($this->root . '/autofill/info.json'));
        $store->write('autofill/tmp/autofill-0123456789abcdef.jpg', 'jpeg');
        $this->assertTrue($store->exists('autofill/tmp/autofill-0123456789abcdef.jpg'));
        $store->delete('autofill/tmp/autofill-0123456789abcdef.jpg');
        $this->assertFalse($store->exists('autofill/tmp/autofill-0123456789abcdef.jpg'));
        $store->delete('autofill/missing');
    }

    public function testKeysCannotLeaveTheRoot(): void
    {
        $store = new LocalStorage($this->root);
        foreach (['../secret', 'autofill/../../etc/passwd', '/etc/passwd', 'autofill\\info', '', '.', 'a/../b'] as $key) {
            try {
                $store->write($key, 'nope');
                $this->fail($key . ' was stored');
            } catch (InvalidArgumentException) {
                $this->assertNull($store->read($key));
            }
        }
        $this->assertSame([], glob($this->root . '/*') ?: []);
    }
}
