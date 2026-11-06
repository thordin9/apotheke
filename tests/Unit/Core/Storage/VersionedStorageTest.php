<?php
declare(strict_types=1);
namespace Apotheke\Core\Storage;
use PHPUnit\Framework\TestCase;
final class VersionedStorageTest extends TestCase
{
    private array $processes = [];
    private array $pipes = [];
    private array $tempdirs = [];
    protected function tearDown(): void
    {
        foreach ($this->tempdirs as $dir) @unlink($dir . '/run');
        foreach ($this->processes as $process) if (is_resource($process)) proc_terminate($process);
        foreach ($this->pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        foreach ($this->processes as $process) if (is_resource($process)) proc_close($process);
        foreach ($this->tempdirs as $dir) {
            foreach (glob($dir . '/{,*/*}{.,}*', GLOB_BRACE) ?: [] as $path) if (is_file($path) || is_link($path)) @unlink($path);
            @rmdir($dir . '/.locks'); @rmdir($dir . '/backups'); @rmdir($dir);
        }
        parent::tearDown();
    }
    public function testObjectsExposeVersionAndRejectStaleReplacement(): void
    {
        $s = new LocalStorage($this->temp());
        $this->assertTrue($s->putIfMissing('backups/manifest.json', 'one'));
        $this->assertFalse($s->putIfMissing('backups/manifest.json', 'two'));
        $v = $s->version('backups/manifest.json');
        $this->assertTrue($s->putIfVersion('backups/manifest.json', $v[1], 'two'));
        $this->assertFalse($s->putIfVersion('backups/manifest.json', $v[1], 'three'));
        $this->assertSame('two', $s->version('backups/manifest.json')[0]);
        $this->assertNull((new LocalStorage($this->temp()))->version('not-there.json'));
        $this->assertFileDoesNotExist(dirname((string) realpath($this->tempdirs[0] . '/backups/manifest.json')) . '/manifest.json.lock');
        $this->assertDirectoryExists($this->tempdirs[0] . '/.locks');
        $this->assertSame([], glob($this->tempdirs[0] . '/backups/*.lock') ?: []);
    }
    public function testLocalStaleProcessCannotOverwriteManifest(): void
    {
        $dir = $this->temp();
        $s = new LocalStorage($dir);
        $this->assertTrue($s->putIfMissing('manifest.json', 'first'));
        $p = $this->process([PHP_BINARY, __DIR__ . '/_conditional-race.php', $dir], $pipes);
        for ($i = 0; !is_file($dir . '/done') && $i < 5000; $i++) usleep(1000);
        $this->assertFileExists($dir . '/done');
        $this->assertTrue($s->putIfVersion('manifest.json', $s->version('manifest.json')[1], 'second'));
        touch($dir . '/gate');
        $result = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p), $result);
        $this->processes = [];
        $this->assertStringContainsString('false', $result);
        $this->assertSame('second', $s->version('manifest.json')[0]);
    }
    public function testRemoteProcessesHonorExactETag(): void
    {
        $dir = $this->temp();
        $node = $this->process(['node', __DIR__ . '/_r2-race-server.mjs', '0'], $pipes);
        $port = (int)fgets($pipes[1]);
        $this->assertGreaterThan(0, $port);
        file_put_contents($dir . '/port', (string)$port);
        foreach (['first', 'second'] as $name) $status[$name] = $this->process([PHP_BINARY, __DIR__ . '/_r2-race-client.php', $dir, $name], $nopipes);
        for ($i = 0; $i < 5000 && count(glob($dir . '/ready-*')) < 2; $i++) usleep(1000);
        $this->assertCount(2, glob($dir . '/ready-*'));
        touch($dir . '/gate');
        foreach ($status as $name => $process) $this->assertSame(0, proc_close($process));
        $this->processes = [$node];
        $results = array_map(fn($name) => json_decode(file_get_contents($dir . '/result-' . $name), true), array_keys($status));
        $this->assertCount(1, array_filter(array_column($results, 0)));
        $this->assertSame($results[0][1], $results[1][1]);
        file_get_contents('http://127.0.0.1:' . $port . '/stop');
        proc_close($node); $this->processes = [];
    }
    private function process(array $command, &$pipes): mixed
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->processes[] = $process;
        array_push($this->pipes, ...$pipes);
        return $process;
    }
    private function temp(): string
    {
        $path = '/tmp/p4-storage-' . bin2hex(random_bytes(8));
        mkdir($path, 0770, true); touch($path . '/run');
        $this->tempdirs[] = $path;
        return $path;
    }
}
