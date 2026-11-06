<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

use PHPUnit\Framework\TestCase;

final class StorageFactoryTest extends TestCase
{
    public function testOnlyExplicitR2SelectsRemoteStorage(): void
    {
        $config = ['data_dir' => sys_get_temp_dir(), 'storage' => ['backend' => 'local']];
        $this->assertInstanceOf(LocalStorage::class, StorageFactory::create($config));
        $this->assertInstanceOf(LocalStorage::class, StorageFactory::create(['data_dir' => sys_get_temp_dir()]));
        $this->assertInstanceOf(R2Storage::class, StorageFactory::create(['storage' => ['backend' => 'r2', 'max_write_bytes' => 123]]));
    }

    public function testApothekePairSharesOneSelectedBackend(): void
    {
        $dir = sys_get_temp_dir() . '/original-root-test';
        [$data, $originals] = StorageFactory::forApotheke(['data_dir' => $dir, 'originals_dir' => $dir . '/kept']);
        $this->assertInstanceOf(LocalStorage::class, $data);
        $this->assertSame($dir . '/kept/map/probe', $originals->pathFor('map/probe'));
        $this->assertInstanceOf(LocalStorage::class, $originals);
        [$data, $originals] = StorageFactory::forApotheke(['storage' => ['backend' => 'r2']]);
        $this->assertInstanceOf(R2Storage::class, $data);
        $this->assertInstanceOf(R2Storage::class, $originals);
        $this->expectException(\LogicException::class);
        $originals->forOriginals();
    }

    public function testR2MaximumWriteSizeCannotBeRaisedWithoutBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Object storage write limit must be between 1 and 104857600 bytes.');
        StorageFactory::create(['storage' => ['backend' => 'r2', 'max_write_bytes' => 104857601]]);
    }

    public function testKnownCeilingIsAccepted(): void
    {
        $this->assertInstanceOf(R2Storage::class, StorageFactory::create(['storage' => ['backend' => 'r2', 'max_write_bytes' => 104857600]]));
    }

    public function testUnknownBackendIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StorageFactory::create(['storage' => ['backend' => 'hybrid']]);
    }

    public function testExtensionIsOptionalUntilR2IsSelected(): void
    {
        $script = <<<'PHP'
require $argv[1];
use Apotheke\Core\Storage\StorageFactory;
try {
    if (extension_loaded('curl')) exit(3);
    echo get_class(StorageFactory::create(['data_dir' => '/tmp']));
    StorageFactory::create(['storage' => ['backend' => 'r2']]);
} catch (RuntimeException $e) {
    echo "\n" . $e->getMessage();
}
PHP;
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        exec('php -n -r ' . escapeshellarg($script) . ' ' . escapeshellarg($autoload) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status);
        $this->assertSame("Apotheke\\Core\\Storage\\LocalStorage\nR2 storage requires optional PHP extension ext-curl.", implode("\n", $output));
    }

    public function testR2ListAndAtomicCreateKeepTheirSemantics(): void
    {
        $storage = new R2Storage(new R2Bridge(transport: static function (string $method, string $url, $body): array {
            if (str_contains($url, 'action=list')) {
                return ['status' => 200, 'body' => json_encode(['ok' => true, 'objects' => ['media/a'], 'cursor' => null], JSON_THROW_ON_ERROR)];
            }
            return ['status' => 200, 'body' => '{"ok":true}'];
        }));
        $this->assertSame(['objects' => ['media/a'], 'cursor' => null], $storage->list());
        $this->assertTrue($storage->conditionalCreate('media/a', 'x'));
    }
    public function testConditionalCreateReportsAnExistingObject(): void
    {
        $storage = new R2Storage(new R2Bridge(transport: static fn (): array => ['status' => 412, 'body' => '']));
        $this->assertFalse($storage->conditionalCreate('already', 'x'));
    }

    public function testListRefusesAnUnsafeOrMalformedServerResponse(): void
    {
        $bridge = new R2Bridge(transport: static fn (): array => ['status' => 200, 'body' => json_encode(['ok' => true, 'objects' => ['../escape'], 'cursor' => null], JSON_THROW_ON_ERROR)]);
        $this->expectException(R2StorageException::class);
        $bridge->list();
    }
}
