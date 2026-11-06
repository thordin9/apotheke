<?php
declare(strict_types=1);
namespace Apotheke\Core\Storage;
use PHPUnit\Framework\TestCase;
final class LeaseStorageTest extends TestCase
{
    public function testAStableLeaseOutlivesManifestReplacement(): void
    {
        $dir = sys_get_temp_dir() . '/coder2-pr3b-lease-' . bin2hex(random_bytes(8));
        $storage = new LocalStorage($dir); self::$dirs[] = $dir;
        $this->assertTrue($storage->putIfMissing('state.json', 'one'));
        $this->assertSame('two', $storage->withLease('state.json', function () use ($storage, $dir, &$during): string {
            $during = (new LocalStorage($dir))->withLease('state.json', fn (): string => 'other', false);
            $storage->write('state.json', 'two');
            return $storage->read('state.json');
        }));
        $this->assertFalse($during);
        $this->assertSame('two', $storage->read('state.json'));
    }

    public function testSeparateProcessesShareLeaseAcrossReplacement(): void
    {
        $dir = sys_get_temp_dir() . '/coder2-pr3b-process-lease-' . bin2hex(random_bytes(8));
        $storage = new LocalStorage($dir); self::$dirs[] = $dir;
        $storage->write('state.json', 'one');
        $procs = [];
        for ($n = 1; $n <= 2; $n++) {
            $procs[$n] = proc_open([PHP_BINARY, __DIR__ . '/_lease-race.php', $dir, (string)$n], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$n]);
            $this->assertIsResource($procs[$n]);
        }
        for ($i = 0; count(glob($dir . '/ready-*')) < 2 && $i < 5000; $i++) usleep(1000);
        $this->assertCount(2, glob($dir . '/ready-*'));
        touch($dir . '/gate');
        $results = [];
        foreach ($procs as $n => $p) {
            $results[$n] = stream_get_contents($pipes[$n][1]) . stream_get_contents($pipes[$n][2]);
            $this->assertSame(0, proc_close($p), $results[$n]);
        }
        sort($results);
        $this->assertSame(['false', 'true'], $results);
        $this->assertContains($storage->read('state.json'), ['1', '2']);
    }

    /** @var list<string> */
    private static array $dirs = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$dirs as $dir) exec('rm -rf ' . escapeshellarg($dir));
    }
}
