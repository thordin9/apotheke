<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\R2Storage;
use Apotheke\Core\Storage\Storage;
use Apotheke\Migrations\BootMigration;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use InvalidArgumentException;
use ReflectionProperty;

/** P4-5: photos, originals, attachments, imports and brand files follow StorageFactory. */
final class CatalogStorageSelectionTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/catalog-storage-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        BootMigration::forget();
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        BootMigration::forget();
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testDefaultModeKeepsLocalDiskAndAttachmentDirectoryMode(): void
    {
        $this->withConfig([]);
        $this->assertInstanceOf(LocalStorage::class, $this->filesOf(Services::servedImages(false)));
        $images = Services::imageStore(false);
        $this->assertInstanceOf(LocalStorage::class, $this->filesOf($this->prop($images, 'served')));
        $originals = $this->filesOf($this->prop($images, 'originals'));
        $this->assertInstanceOf(LocalStorage::class, $originals);
        $this->assertSame(0640, $this->prop($originals, 'fileMode'));
        $attachments = $this->filesOf(Services::attachments(false));
        $this->assertInstanceOf(LocalStorage::class, $attachments);
        $this->assertSame(0770, $this->prop($attachments, 'dirMode'));
        $this->assertInstanceOf(LocalStorage::class, $this->filesOf(Services::itemRepository(false)));
        $this->assertInstanceOf(LocalStorage::class, $this->filesOf(Services::imports(false)));
        $this->assertInstanceOf(LocalStorage::class, $this->prop($this->prop(Services::autofill(false), 'temps'), 'storage'));
        $brand = $this->filesOf(Services::brandStore(false));
        $this->assertInstanceOf(LocalStorage::class, $brand);
        $this->assertSame(0775, $this->prop($brand, 'dirMode'));
    }

    public function testStorageModeSelectsR2ForEveryCatalogFactory(): void
    {
        $this->withConfig(['STORAGE_BACKEND' => 'r2', 'DB_DRIVER' => 'd1']);
        $this->assertInstanceOf(R2Storage::class, $this->filesOf(Services::servedImages(false)));
        $images = Services::imageStore(false);
        $this->assertInstanceOf(R2Storage::class, $this->filesOf($this->prop($images, 'served')));
        $this->assertInstanceOf(R2Storage::class, $this->filesOf($this->prop($images, 'originals')));
        $this->assertInstanceOf(R2Storage::class, $this->filesOf(Services::attachments(false)));
        $this->assertInstanceOf(R2Storage::class, $this->filesOf(Services::itemRepository(false)));
        $this->assertInstanceOf(R2Storage::class, $this->filesOf(Services::imports(false)));
        $this->assertInstanceOf(R2Storage::class, $this->prop($this->prop(Services::autofill(false), 'temps'), 'storage'));
        $this->assertInstanceOf(R2Storage::class, $this->filesOf(Services::brandStore(false)));
    }

    public function testUnusableR2SelectionIsAnErrorNotADiskFallback(): void
    {
        $file = $this->dir . '/bad-r2.php';
        file_put_contents($file, "<?php return ['storage' => ['backend' => 'r2', 'r2_base_url' => 'http://r2.internal/path']];\n");
        Services::reset(true);
        Services::injectMock('apothekeConfig', ApothekeConfig::load('/app', $file, [
            'DATA_DIR' => $this->dir,
            'SQLITE_PATH' => $this->dir . '/catalog.sqlite',
            'DB_DRIVER' => 'd1',
        ]));
        try {
            Services::servedImages(false);
            $this->fail('an unusable R2 selection must not construct local disk storage');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('origin-only', $e->getMessage());
        }
        try {
            Services::brandStore(false);
            $this->fail('an unusable R2 selection must not construct local brand storage');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('origin-only', $e->getMessage());
        }
    }

    /** @param array<string,string> $env */
    private function withConfig(array $env): void
    {
        Services::reset(true);
        $config = ApothekeConfig::load('/app', null, $env + [
            'DATA_DIR' => $this->dir,
            'SQLITE_PATH' => $this->dir . '/catalog.sqlite',
        ]);
        Services::injectMock('apothekeConfig', $config);
        if (($config->database()['driver'] ?? '') === 'd1') {
            // Mark the object migrated for this process, so the test needs no bridge.
            $key = (new \ReflectionMethod(BootMigration::class, 'key'))->invoke(null, $config->database());
            $done = new \ReflectionProperty(BootMigration::class, 'done');
            $done->setValue(null, [$key => true]);
        }
    }

    private function filesOf(object $holder): Storage
    {
        return $this->prop($holder, 'files');
    }

    private function prop(object $holder, string $name): mixed
    {
        return (new ReflectionProperty($holder, $name))->getValue($holder);
    }
}
