<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Boot\StoragePreflight;
use Apotheke\Core\Boot\StorageRefused;
use CodeIgniter\Test\CIUnitTestCase;

final class StoragePreflightTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(StoragePreflight::class); // loads StorageRefused from the same file
        $this->dir = sys_get_temp_dir() . '/preflight-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testDefaultModeCreatesPhotoAndSessionDirectories(): void
    {
        $data = $this->dir . '/data';
        $originals = $this->dir . '/originals';
        (new StoragePreflight())->prepare($data, $originals, $this->dir . '/public');
        foreach ([$data, $data . '/images', $data . '/thumbs', $data . '/sessions', $originals, $originals . '/map'] as $dir) {
            $this->assertDirectoryExists($dir);
        }
        $this->assertFileExists($originals . '/.htaccess');
    }

    public function testStorageModeSkipsPhotoDirectoriesAndKeepsSessions(): void
    {
        $data = $this->dir . '/data';
        $originals = $this->dir . '/originals';
        (new StoragePreflight())->prepare($data, $originals, $this->dir . '/public', 'r2');
        $this->assertDirectoryExists($data);
        $this->assertDirectoryExists($data . '/sessions');
        $this->assertDirectoryDoesNotExist($data . '/images');
        $this->assertDirectoryDoesNotExist($data . '/thumbs');
        $this->assertDirectoryDoesNotExist($originals);
        $this->assertDirectoryDoesNotExist($originals . '/map');
        $this->assertFileDoesNotExist($originals . '/.htaccess');
    }

    public function testOriginalsInsideTheWebRootAreStillRefusedInStorageMode(): void
    {
        $this->expectException(StorageRefused::class);
        (new StoragePreflight())->prepare($this->dir . '/data', $this->dir . '/public/originals', $this->dir . '/public', 'R2');
    }
}
