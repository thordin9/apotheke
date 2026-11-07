<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

use Apotheke\Backup\RestoreUnit;
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

    public function testALostReleaseIsRetriedUntilThisHolderIsCleared(): void
    {
        $inner = new LocalStorage($this->temp());
        $storage = new class ($inner) implements Storage, VersionedStorage {
            public int $drops = 1;

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
                if ($this->drops > 0 && str_contains($bytes, '"token":""')) {
                    $this->drops--;

                    return false;
                }

                return $this->inner->putIfVersion($key, $etag, $bytes);
            }
        };
        $lease = new ObjectLease($storage);
        $token = $lease->acquire('uploads/restore/leases/one', 10, 5);
        $lease->release('uploads/restore/leases/one', $token);
        $this->assertFalse($lease->holds('uploads/restore/leases/one', $token, 11));
        $body = json_decode((string)$inner->version('uploads/restore/leases/one')[0], true);
        $this->assertSame('', $body['token']);
    }

    public function testRenewalKeepsTheHolderPastTheOriginalLifetime(): void
    {
        $storage = new LocalStorage($this->temp());
        $lease = new ObjectLease($storage);
        $now = 1_000;
        $ran = false;
        (new RestoreUnit($lease))->run(function (LeaseHold $hold) use ($lease, &$now, &$ran): void {
            $now = 1_040;
            $hold->checkpoint();
            try {
                $lease->acquire(RestoreUnit::KEY, $now, 10);
                $this->fail('A renewed lease must still refuse a second holder.');
            } catch (LeaseDenied) {
                $ran = true;
            }
            $wrote = false;
            $hold->exclusive(function () use (&$wrote): void {
                $wrote = true;
            });
            $this->assertTrue($wrote);
        }, 1_000, 30, static function () use (&$now): int {
            return $now;
        });
        $this->assertTrue($ran);
    }

    public function testATakeoverWhileTheCallbackRunsBlocksTheNextWrite(): void
    {
        $storage = new LocalStorage($this->temp());
        $lease = new ObjectLease($storage);
        $now = 1_000;
        (new RestoreUnit($lease))->run(function (LeaseHold $hold) use ($lease, $storage, &$now): void {
            $now = 1_040;
            $lease->acquire(RestoreUnit::KEY, $now, 30);
            $wrote = false;
            try {
                $hold->exclusive(function () use ($storage, &$wrote): void {
                    $wrote = true;
                    $storage->write('restores/payload', 'stale');
                });
                $this->fail('The stale holder must not start the write.');
            } catch (LeaseDenied) {
                $this->assertFalse($wrote);
            }
            $this->assertNull($storage->read('restores/payload'));
            try {
                $lease->renew(RestoreUnit::KEY, 'nope', $now, 30);
                $this->fail('A different token must not renew the lease.');
            } catch (LeaseDenied) {
            }
        }, 1_000, 30, static function () use (&$now): int {
            return $now;
        });
    }

    public function testProductionTransportRenewsAndThenRefusesAStaleWriter(): void
    {
        $port = $this->server();
        $dir = $this->temp();
        $url = 'http://127.0.0.1:' . $port;
        $renew = $this->proc($this->script($url, $dir, 'renew'));
        $challenger = $this->proc($this->script($url, $dir, 'challenge'));
        $renewedText = stream_get_contents($renew[1]) . stream_get_contents($renew[2]);
        $challengedText = stream_get_contents($challenger[1]) . stream_get_contents($challenger[2]);
        $renewCode = proc_close($renew[0]);
        $challengeCode = proc_close($challenger[0]);
        $renewedText .= is_file($dir . '/renew-error') ? file_get_contents($dir . '/renew-error') : '';
        $this->assertSame(0, $renewCode, $renewedText);
        $this->assertSame(0, $challengeCode, $challengedText);
        $this->assertFileDoesNotExist($dir . '/took');
        $this->assertFileExists($dir . '/denied');
        $storage = new R2Storage(new R2Bridge($url));
        $this->assertSame('A', $storage->read('restores/winner'));

        file_get_contents($url . '/reset');
        $other = $this->temp();
        $stale = $this->proc($this->script($url, $other, 'stale'));
        $taker = $this->proc($this->script($url, $other, 'take'));
        $staleText = stream_get_contents($stale[1]) . stream_get_contents($stale[2]);
        $takerText = stream_get_contents($taker[1]) . stream_get_contents($taker[2]);
        $this->assertSame(0, proc_close($stale[0]), $staleText);
        $this->assertSame(0, proc_close($taker[0]), $takerText);
        $this->assertFileExists($other . '/refused');
        $this->assertFileDoesNotExist($other . '/mutated');
        $this->assertSame('B', $storage->read('restores/winner'));
    }

    public function testAHeldConditionalCreateDoesNotReplaceTheNewerObject(): void
    {
        $port = $this->server();
        $url = 'http://127.0.0.1:' . $port;
        $dir = $this->temp();
        file_get_contents($url . '/hold-next');
        $writer = $this->proc($this->putScript($url, $dir));
        $until = microtime(true) + 5;
        do {
            $holding = file_get_contents($url . '/holding');
            if ($holding === '1') {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        $this->assertSame('1', $holding);
        $storage = new R2Storage(new R2Bridge($url));
        $this->assertTrue($storage->putIfMissing('restores/winner', 'NEW'));
        file_get_contents($url . '/release-holds');
        $text = stream_get_contents($writer[1]) . stream_get_contents($writer[2]);
        $this->assertSame(0, proc_close($writer[0]), $text);
        $this->assertSame('rejected', trim((string)file_get_contents($dir . '/result')));
        $this->assertSame('NEW', $storage->read('restores/winner'));
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

    /** @return array{0:resource,1:resource,2:resource} */
    private function proc(string $code): array
    {
        $path = $this->temp() . '/run.php';
        file_put_contents($path, $code);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $path],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $this->processes[] = $process;

        return [$process, $pipes[1], $pipes[2]];
    }

    private function putScript(string $url, string $dir): string
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';

        return <<<PHP
<?php
require '{$autoload}';
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
\$storage = new R2Storage(new R2Bridge('{$url}'));
\$ok = \$storage->putIfMissing('restores/winner', 'OLD');
file_put_contents('{$dir}/result', \$ok ? 'landed' : 'rejected');
PHP;
    }

    private function script(string $url, string $dir, string $role): string
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $key = var_export(RestoreUnit::KEY, true);

        return <<<PHP
<?php
require '{$autoload}';
use Apotheke\Backup\RestoreUnit;
use Apotheke\Core\Storage\LeaseDenied;
use Apotheke\Core\Storage\LeaseHold;
use Apotheke\Core\Storage\ObjectLease;
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
\$storage = new R2Storage(new R2Bridge('{$url}'));
\$dir = '{$dir}';
\$role = '{$role}';
if (\$role === 'renew') {
    try {
        (new RestoreUnit(new ObjectLease(\$storage)))->run(function (LeaseHold \$hold) use (\$storage, \$dir): void {
            \$hold->checkpoint();
            file_put_contents(\$dir . '/entered', '1');
            \$until = microtime(true) + 4.5;
            while (microtime(true) < \$until) {
                \$hold->checkpoint();
                usleep(200000);
            }
            \$hold->exclusive(function () use (\$storage): void {
                \$storage->write('restores/winner', 'A');
            });
        }, time(), 2);
    } catch (Throwable \$e) {
        \$version = \$storage->version('restores/unit');
        file_put_contents(\$dir . '/renew-error', \$e->getMessage() . "\n" . (\$version[0] ?? 'missing'));
        exit(4);
    }
    exit(0);
}
if (\$role === 'challenge') {
    \$until = microtime(true) + 5;
    while (!is_file(\$dir . '/entered') && microtime(true) < \$until) usleep(10000);
    \$lease = new ObjectLease(\$storage);
    \$denied = 0;
    \$until = microtime(true) + 4;
    while (microtime(true) < \$until) {
        try {
            \$lease->acquire({$key}, time(), 1);
            file_put_contents(\$dir . '/took', '1');
            exit(2);
        } catch (LeaseDenied) {
            \$denied++;
            usleep(100000);
        }
    }
    file_put_contents(\$dir . '/denied', (string)\$denied);
    exit(\$denied > 0 ? 0 : 3);
}
if (\$role === 'stale') {
    (new RestoreUnit(new ObjectLease(\$storage)))->run(function (LeaseHold \$hold) use (\$storage, \$dir): void {
        file_put_contents(\$dir . '/entered', '1');
        \$until = microtime(true) + 5;
        while (!is_file(\$dir . '/took') && microtime(true) < \$until) usleep(10000);
        try {
            \$hold->exclusive(function () use (\$storage): void {
                \$storage->write('restores/winner', 'stale');
                file_put_contents(\$dir . '/mutated', '1');
            });
        } catch (LeaseDenied) {
            file_put_contents(\$dir . '/refused', '1');
        }
    }, time(), 2);
    exit(0);
}
\$until = microtime(true) + 5;
while (!is_file(\$dir . '/entered') && microtime(true) < \$until) usleep(10000);
\$lease = new ObjectLease(\$storage);
\$until = microtime(true) + 5;
while (microtime(true) < \$until) {
    try {
        \$lease->acquire({$key}, time(), 30);
        \$storage->write('restores/winner', 'B');
        file_put_contents(\$dir . '/took', '1');
        exit(0);
    } catch (LeaseDenied) {
        usleep(50000);
    }
}
exit(3);
PHP;
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
