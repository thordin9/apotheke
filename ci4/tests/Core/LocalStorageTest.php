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

    public function testModifiedAtAndFileMode(): void
    {
        $store = new LocalStorage($this->root, 0640);
        $this->assertNull($store->modifiedAt('images/a.jpg'));
        $store->write('images/a.jpg', 'jpeg');
        $this->assertSame(filemtime($this->root . '/images/a.jpg'), $store->modifiedAt('images/a.jpg'));
        $this->assertSame(0640, fileperms($this->root . '/images/a.jpg') & 0777);
        $this->assertNull($store->modifiedAt('../a.jpg'));
    }

    /** #134 (N-475): a missing directory gets the mode given (attachments: legacy's 0770), before the umask. */
    public function testMissingDirectoriesGetTheDirMode(): void
    {
        $old = umask(0007);
        try {
            (new LocalStorage($this->root))->write('a/x', '1');
            (new LocalStorage($this->root, null, 0770))->write('files/y.pdf', '%PDF');
        } finally {
            umask($old);
        }
        $this->assertSame(0700, fileperms($this->root . '/a') & 0777, 'default 0700');
        $this->assertSame(0770, fileperms($this->root . '/files') & 0777);
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

    public function testASymlinkIsNotFollowed(): void
    {
        $outside = sys_get_temp_dir() . '/apotheke-outside-' . bin2hex(random_bytes(4));
        $outDir = sys_get_temp_dir() . '/apotheke-outdir-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'secret');
        mkdir($outDir, 0700);
        file_put_contents($outDir . '/info.json', 'keep');
        try {
            $store = new LocalStorage($this->root);
            mkdir($this->root . '/autofill', 0700);
            symlink($outside, $this->root . '/autofill/info.json');
            try {
                $store->write('autofill/info.json', 'nope');
                $this->fail('wrote through a file symlink');
            } catch (InvalidArgumentException) {
                $this->assertSame('secret', (string)file_get_contents($outside));
                $this->assertNull($store->read('autofill/info.json'));
                $this->assertFalse($store->exists('autofill/info.json'));
            }
            $store->delete('autofill/info.json');
            $this->assertSame('secret', (string)file_get_contents($outside));
            $this->assertTrue(is_link($this->root . '/autofill/info.json'));

            symlink($outDir, $this->root . '/linked');
            try {
                $store->write('linked/info.json', 'nope');
                $this->fail('wrote through a directory symlink');
            } catch (InvalidArgumentException) {
                $this->assertSame('keep', (string)file_get_contents($outDir . '/info.json'));
                $this->assertNull($store->read('linked/info.json'));
            }
        } finally {
            @unlink($outside);
            @unlink($outDir . '/info.json');
            @rmdir($outDir);
        }
    }

    public function testRewriteReplacesAReadOnlyFileWithoutATempLeftBehind(): void
    {
        $store = new LocalStorage($this->root);
        $store->write('autofill/info.json', 'old');
        $path = $this->root . '/autofill/info.json';
        chmod($path, 0444);
        $store->write('autofill/info.json', 'new');
        $this->assertSame('new', (string)file_get_contents($path));
        $names = array_map('basename', glob($this->root . '/autofill/{,.}*', GLOB_BRACE) ?: []);
        $this->assertSame(['info.json'], array_values(array_filter($names, static fn (string $n): bool => $n !== '.' && $n !== '..')));
    }
}
