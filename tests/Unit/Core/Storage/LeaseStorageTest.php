<?php
declare(strict_types=1);
namespace Apotheke\Core\Storage;
use PHPUnit\Framework\TestCase;
final class LeaseStorageTest extends TestCase
{
    public function testAStableLeaseOutlivesManifestReplacement(): void
    {
        $dir = sys_get_temp_dir() . '/coder2-pr3b-lease-' . bin2hex(random_bytes(8));
        $storage = new LocalStorage($dir);
        $this->assertTrue($storage->putIfMissing('state.json', 'one'));
        $this->assertSame('two', $storage->withLease('state.json', function () use ($storage, $dir, &$during): string {
            $during = (new LocalStorage($dir))->withLease('state.json', fn (): string => 'other', false);
            $storage->write('state.json', 'two');
            return $storage->read('state.json');
        }));
        $this->assertFalse($during);
        $this->assertSame('two', $storage->read('state.json'));
        @unlink($dir . '/state.json'); @unlink($dir . '/state.json.lock'); @rmdir($dir);
    }
}
