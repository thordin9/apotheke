<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\RestoreObjectStore;
use Apotheke\Backup\RestoreUnit;
use Apotheke\Backup\RestoreUploads;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Storage\LeaseDenied;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\ObjectLease;
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
use Apotheke\Core\Storage\Storage;
use Apotheke\Core\Storage\VersionedStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RestoreObjectStoreTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private int $now = 1_700_000_000;

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
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $file) {
                $file->isDir() ? @rmdir((string)$file) : @unlink((string)$file);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testPayloadManifestAndLeaseShareOneStorage(): void
    {
        $root = $this->temp();
        $other = $this->temp();
        $storage = new LocalStorage($root);
        [$id, $manifest] = $this->store($storage)->create(4, null);
        $this->assertSame('uploading', $manifest['status']);
        $this->assertNull($storage->version(RestoreObjectStore::leaseKey($id)));
        $this->assertNotNull($storage->version(RestoreObjectStore::manifestKey($id)));
        $this->assertSame([], glob($other . '/*') ?: []);
        $this->expectException(\InvalidArgumentException::class);
        new RestoreUploads($other, 4, 100, static fn (): string => 'user:1', objects: new LocalStorage($root));
    }

    public function testTheLeaseIsHeldBeforeAChunkAndAStaleManifestIsNotWritten(): void
    {
        $inner = new LocalStorage($this->temp());
        $storage = $this->recording($inner);
        $store = $this->store($storage);
        [$id] = $store->create(8, null);
        $chunk = $this->file('abcd');
        $store->append($id, 0, $chunk, 4);
        $writeAt = $this->op($storage->ops, 'write');
        $leaseAt = $this->opBefore($storage->ops, 'uploads/restore/leases/' . $id, $writeAt);
        $this->assertLessThan($writeAt, $leaseAt);
        $cas = $this->last($storage->ops, 'cas', RestoreObjectStore::manifestKey($id));
        $version = $this->lastBefore($storage->ops, 'version', RestoreObjectStore::manifestKey($id), $cas['at']);
        $this->assertSame($version['etag'], $cas['etag']);

        $storage->staleManifest = true;
        try {
            $store->append($id, 1, $chunk, 4);
            $this->fail('A stale manifest compare-and-swap must fail.');
        } catch (UserError $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertNull($inner->read(RestoreObjectStore::chunkKey($id, 1)));
        $this->assertSame(4, $store->get($id)['received']);
        $this->assertFalse((new ObjectLease($inner))->holds(RestoreObjectStore::leaseKey($id), 'x', $this->now));
    }

    public function testAFailedChunkWriteIsRemovedAndTheLeaseIsReleased(): void
    {
        $inner = new LocalStorage($this->temp());
        $storage = $this->recording($inner);
        $storage->failChunk = true;
        $store = $this->store($storage);
        [$id] = $store->create(4, null);
        try {
            $store->append($id, 0, $this->file('abcd'), 4);
            $this->fail('The chunk failure must surface.');
        } catch (RuntimeException $e) {
            $this->assertSame('chunk failed', $e->getMessage());
        }
        $storage->failChunk = false;
        $this->assertNull($inner->read(RestoreObjectStore::chunkKey($id, 0)));
        $this->assertSame(0, $store->get($id)['received']);
        $again = $store->append($id, 0, $this->file('abcd'), 4);
        $this->assertSame(4, $again['received']);
    }

    public function testAnExpiredApplyingUploadBecomesInterruptedAndALiveLeaseDoesNot(): void
    {
        $storage = new LocalStorage($this->temp());
        $store = $this->store($storage, 30);
        [$id] = $store->create(3, null);
        $store->append($id, 0, $this->file('abc'), 3);
        $store->finish($id, static fn (string $zip, array $manifest): array => ['bytes' => file_get_contents($zip), 'status' => $manifest['status']]);
        $version = $storage->version(RestoreObjectStore::manifestKey($id));
        $manifest = json_decode($version[0], true);
        $manifest['status'] = 'applying';
        $manifest['fingerprint'] = hash('sha256', 'abc');
        $this->assertTrue($storage->putIfVersion(RestoreObjectStore::manifestKey($id), $version[1], json_encode($manifest)));
        $held = (new ObjectLease($storage))->acquire(RestoreObjectStore::leaseKey($id), $this->now, 30);
        try {
            $store->get($id);
            $this->fail('A live lease must block the upload.');
        } catch (UserError $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertSame('applying', json_decode((string)$storage->read(RestoreObjectStore::manifestKey($id)), true)['status']);
        (new ObjectLease($storage))->release(RestoreObjectStore::leaseKey($id), $held);
        $this->now += 31;
        $stopped = $store->get($id);
        $this->assertSame('interrupted', $stopped['status']);
        $calls = 0;
        try {
            $store->confirm($id, static function () use (&$calls): array {
                $calls++;

                return [];
            });
            $this->fail('An interrupted upload must not restore again.');
        } catch (UserError $e) {
            $this->assertSame(RestoreUploads::INTERRUPTED, $e->getMessage());
        }
        $this->assertSame(0, $calls);
    }

    public function testConfirmRestoresOnceFromTheStoredChunks(): void
    {
        $storage = new LocalStorage($this->temp());
        $store = $this->store($storage);
        [$id] = $store->create(3, hash('sha256', 'xyz'));
        $store->append($id, 0, $this->file('abc'), 3);
        try {
            $store->finish($id, static fn (): array => []);
            $this->fail('A checksum mismatch must remove the upload.');
        } catch (UserError $e) {
            $this->assertSame(422, $e->getCode());
        }
        $this->assertNull($storage->version(RestoreObjectStore::manifestKey($id)));
        [$id] = $store->create(3, null);
        $store->append($id, 0, $this->file('abc'), 3);
        try {
            $store->confirm($id, static fn (): array => []);
            $this->fail('Confirm before finish must refuse.');
        } catch (UserError $e) {
            $this->assertSame(409, $e->getCode());
        }
        $store->finish($id, static fn (string $zip): array => ['bytes' => file_get_contents($zip)]);
        $calls = 0;
        $restore = function (string $zip) use (&$calls): array {
            $calls++;
            $this->assertSame('abc', file_get_contents($zip));

            return ['tables' => ['items' => 1], 'photos' => 0, 'warnings' => [], 'token_cleared' => false];
        };
        $this->assertSame(1, $store->confirm($id, $restore)['tables']['items']);
        $this->assertSame(1, $store->confirm($id, $restore)['tables']['items']);
        $this->assertSame(1, $calls);
        $this->assertNull($storage->read(RestoreObjectStore::chunkKey($id, 0)));
    }

    public function testACatalogUnitRunsOnlyAfterItsLeaseAndCleansUpOnFailure(): void
    {
        $storage = new LocalStorage($this->temp());
        $lease = new ObjectLease($storage);
        $unit = new RestoreUnit($lease);
        $ran = false;
        $holder = $lease->acquire(RestoreUnit::KEY, 10, 30);
        try {
            $unit->run(static function () use (&$ran): string {
                $ran = true;

                return 'no';
            }, 10);
            $this->fail('Contention must skip the side effect.');
        } catch (LeaseDenied) {
            $this->assertFalse($ran);
        }
        $lease->release(RestoreUnit::KEY, $holder);
        try {
            $unit->run(static function (): never {
                throw new RuntimeException('restore failed');
            }, 10);
            $this->fail('The failure must surface.');
        } catch (RuntimeException) {
            $this->assertFalse($lease->holds(RestoreUnit::KEY, $holder, 11));
        }
        $this->assertSame('done', $unit->run(static fn (): string => 'done', 12));
    }

    public function testRemoteUploadsDoNotWriteALocalManifestOrLock(): void
    {
        $port = $this->server();
        $data = $this->temp();
        $storage = new R2Storage(new R2Bridge('http://127.0.0.1:' . $port));
        $uploads = new RestoreUploads($data, 4, 100, static fn (): string => 'user:1', objects: $storage);
        [$id, $manifest] = $uploads->create(4, null);
        $this->assertSame('uploading', $manifest['status']);
        $this->assertFileDoesNotExist($data . '/uploads/restore/' . $id . '/manifest.json');
        $this->assertFileDoesNotExist($data . '/uploads/restore/create.lock');
        $uploads->append($id, 0, $this->file('abcd'), 4);
        $finished = $uploads->finish($id, static fn (string $zip): array => ['bytes' => file_get_contents($zip)]);
        $this->assertSame('abcd', $finished['bytes']);
        $this->assertSame([], glob($data . '/uploads/**') ?: []);
    }

    private function store(Storage&VersionedStorage $storage, int $ttl = 3600): RestoreObjectStore
    {
        return new RestoreObjectStore($storage, 4, 100, static fn (): string => 'user:1', 8, null, null, $ttl, fn (): int => $this->now);
    }

    /** @param list<array{op:string,key:string,etag:?string}> $ops */
    private function op(array $ops, string $name): int
    {
        foreach ($ops as $i => $op) {
            if ($op['op'] === $name && str_contains($op['key'], '/chunks/')) {
                return $i;
            }
        }
        $this->fail('Missing ' . $name);

        return 0;
    }

    /** @param list<array{op:string,key:string,etag:?string}> $ops */
    private function opBefore(array $ops, string $key, int $before): int
    {
        $found = null;
        foreach ($ops as $i => $op) {
            if ($i >= $before) {
                break;
            }
            if ($op['key'] === $key && in_array($op['op'], ['create', 'cas', 'version'], true)) {
                $found = $i;
            }
        }
        $this->assertNotNull($found, 'The lease was not taken before the chunk.');

        return (int)$found;
    }

    /**
     * @param list<array{op:string,key:string,etag:?string}> $ops
     * @return array{at:int,etag:?string}
     */
    private function last(array $ops, string $name, string $key): array
    {
        $found = null;
        foreach ($ops as $i => $op) {
            if ($op['op'] === $name && $op['key'] === $key) {
                $found = ['at' => $i, 'etag' => $op['etag']];
            }
        }
        $this->assertNotNull($found);

        return $found;
    }

    /**
     * @param list<array{op:string,key:string,etag:?string}> $ops
     * @return array{at:int,etag:?string}
     */
    private function lastBefore(array $ops, string $name, string $key, int $before): array
    {
        $found = null;
        foreach ($ops as $i => $op) {
            if ($i >= $before) {
                break;
            }
            if ($op['op'] === $name && $op['key'] === $key) {
                $found = ['at' => $i, 'etag' => $op['etag']];
            }
        }
        $this->assertNotNull($found);

        return $found;
    }

    private function recording(LocalStorage $inner): Storage&VersionedStorage
    {
        return new class ($inner) implements Storage, VersionedStorage {
            /** @var list<array{op:string,key:string,etag:?string}> */
            public array $ops = [];

            public bool $staleManifest = false;

            public bool $failChunk = false;

            public function __construct(private readonly LocalStorage $inner)
            {
            }

            public function read(string $key): ?string
            {
                return $this->inner->read($key);
            }

            public function write(string $key, string $bytes): void
            {
                $this->ops[] = ['op' => 'write', 'key' => $key, 'etag' => null];
                $this->inner->write($key, $bytes);
                if ($this->failChunk && str_contains($key, '/chunks/')) {
                    throw new RuntimeException('chunk failed');
                }
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
                $version = $this->inner->version($key);
                $this->ops[] = ['op' => 'version', 'key' => $key, 'etag' => $version[1] ?? null];

                return $version;
            }

            public function putIfMissing(string $key, string $bytes): bool
            {
                $this->ops[] = ['op' => 'create', 'key' => $key, 'etag' => null];

                return $this->inner->putIfMissing($key, $bytes);
            }

            public function putIfVersion(string $key, string $etag, string $bytes): bool
            {
                if ($this->staleManifest && str_contains($key, '/manifests/')) {
                    $this->staleManifest = false;
                    $current = $this->inner->version($key);
                    if ($current !== null) {
                        $this->inner->putIfVersion($key, $current[1], $current[0] . "\n");
                    }
                }
                $this->ops[] = ['op' => 'cas', 'key' => $key, 'etag' => $etag];

                return $this->inner->putIfVersion($key, $etag, $bytes);
            }
        };
    }

    private function file(string $bytes): string
    {
        $path = $this->temp() . '/chunk';
        file_put_contents($path, $bytes);

        return $path;
    }

    private function temp(): string
    {
        $dir = sys_get_temp_dir() . '/apotheke-restore-store-' . bin2hex(random_bytes(4));
        mkdir($dir, 0770);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function server(): int
    {
        $pipes = [];
        $process = proc_open(
            ['node', dirname(__DIR__) . '/Core/Storage/_restore-lease-server.mjs'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $this->processes[] = $process;
        $port = (int)fgets($pipes[1]);
        $this->assertGreaterThan(0, $port);

        return $port;
    }
}
