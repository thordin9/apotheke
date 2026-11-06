<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

use PHPUnit\Framework\TestCase;

final class ObjectLeaseTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    /** @var list<resource> */
    private array $processes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/{,*/,*/*/}{.*,*}', GLOB_BRACE) ?: [] as $path) {
                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }
            foreach (['uploads/restore/leases', 'uploads/restore', 'uploads', '.locks'] as $part) {
                @rmdir($dir . '/' . $part);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testASecondAcquireLosesWhileTheLeaseIsLive(): void
    {
        $lease = new ObjectLease(new LocalStorage($this->temp()));
        $first = $lease->acquire('uploads/restore/leases/one', 1_000, 30);
        try {
            $lease->acquire('uploads/restore/leases/one', 1_010, 30);
            $this->fail('A live lease must refuse a second holder.');
        } catch (LeaseDenied) {
            $this->assertTrue($lease->holds('uploads/restore/leases/one', $first, 1_010));
        }
    }

    public function testAnExpiredLeaseCanBeTakenAndTheOldTokenCannotReleaseIt(): void
    {
        $storage = new LocalStorage($this->temp());
        $lease = new ObjectLease($storage);
        $stale = $lease->acquire('uploads/restore/leases/one', 1_000, 10);
        $next = $lease->acquire('uploads/restore/leases/one', 1_010, 10);
        $lease->release('uploads/restore/leases/one', $stale);
        $this->assertFalse($lease->holds('uploads/restore/leases/one', $stale, 1_011));
        $this->assertTrue($lease->holds('uploads/restore/leases/one', $next, 1_011));
        $this->assertNotSame($stale, $next);
    }

    public function testALostCompareAndSwapDoesNotReplaceTheNewerLease(): void
    {
        $dir = $this->temp();
        $inner = new LocalStorage($dir);
        $inner->putIfMissing('uploads/restore/leases/one', json_encode(['token' => 'holder', 'expires' => 1], JSON_THROW_ON_ERROR));
        $storage = new class ($inner) implements Storage, VersionedStorage {
            public function __construct(private readonly LocalStorage $inner)
            {
            }

            public function read(string $key): ?string
            {
                return $this->inner->read($key);
            }

            public function write(string $key, string $bytes): void
            {
                $this->inner->write($key, $bytes);
            }

            public function writeStream(string $key, mixed $stream): void
            {
                $this->inner->writeStream($key, $stream);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(string $key): bool
            {
                return $this->inner->exists($key);
            }

            public function modifiedAt(string $key): ?int
            {
                return $this->inner->modifiedAt($key);
            }

            public function version(string $key): ?array
            {
                return $this->inner->version($key);
            }

            public function putIfMissing(string $key, string $bytes): bool
            {
                return $this->inner->putIfMissing($key, $bytes);
            }

            public function putIfVersion(string $key, string $etag, string $bytes): bool
            {
                $current = $this->inner->version($key);
                if ($current !== null) {
                    $this->inner->putIfVersion($key, $current[1], json_encode(['token' => 'newer', 'expires' => 9_000], JSON_THROW_ON_ERROR));
                }

                return $this->inner->putIfVersion($key, $etag, $bytes);
            }
        };
        $lease = new ObjectLease($storage);
        try {
            $lease->acquire('uploads/restore/leases/one', 5_000, 10);
            $this->fail('A stale compare-and-swap must not take the lease.');
        } catch (LeaseDenied) {
            $kept = $inner->version('uploads/restore/leases/one');
            $this->assertSame('newer', json_decode((string)$kept[0], true)['token']);
        }
    }

    public function testReleasingAfterAFailureLeavesTheLeaseFree(): void
    {
        $storage = new LocalStorage($this->temp());
        $lease = new ObjectLease($storage);
        $token = $lease->acquire('restores/unit', 50, 10);
        try {
            throw new \RuntimeException('side effect failed');
        } catch (\RuntimeException) {
            $lease->release('restores/unit', $token);
        }
        $this->assertFalse($lease->holds('restores/unit', $token, 51));
        $this->assertNotSame($token, $lease->acquire('restores/unit', 51, 10));
    }

    public function testProductionTransportKeepsTheEtagFromTheObjectItRead(): void
    {
        $port = $this->server();
        $storage = new R2Storage(new R2Bridge('http://127.0.0.1:' . $port));
        $lease = new ObjectLease($storage);
        $token = $lease->acquire('uploads/restore/leases/http', 100, 30);
        $this->assertTrue($lease->holds('uploads/restore/leases/http', $token, 100));
        file_get_contents('http://127.0.0.1:' . $port . '/arm');
        $version = $storage->version('uploads/restore/manifests/http.json');
        $this->assertNull($version);
        $this->assertTrue($storage->putIfMissing('uploads/restore/manifests/http.json', '{"status":"ready"}'));
        file_get_contents('http://127.0.0.1:' . $port . '/arm');
        $version = $storage->version('uploads/restore/manifests/http.json');
        $this->assertSame('{"status":"ready"}', $version[0]);
        $this->assertFalse($storage->putIfVersion('uploads/restore/manifests/http.json', $version[1], '{"status":"replaced-by-us"}'));
        $this->assertSame('replaced-after-read', $storage->version('uploads/restore/manifests/http.json')[0]);
        try {
            $lease->acquire('uploads/restore/leases/http', 110, 30);
            $this->fail('The production lease is still held.');
        } catch (LeaseDenied) {
            $this->assertTrue($lease->holds('uploads/restore/leases/http', $token, 110));
        }
    }

    private function server(): int
    {
        $pipes = [];
        $process = proc_open(
            ['node', __DIR__ . '/_restore-lease-server.mjs'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $this->processes[] = $process;
        $port = (int)fgets($pipes[1]);
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    private function temp(): string
    {
        $dir = sys_get_temp_dir() . '/apotheke-lease-' . bin2hex(random_bytes(4));
        mkdir($dir, 0770);
        $this->dirs[] = $dir;

        return $dir;
    }
}
