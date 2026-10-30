<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * php spark jobs:work as a process (N-1244, L-1241): with pcntl, SIGTERM is a clean stop (exit 0); without it (the
 * fallback when the extension is missing) the worker still runs and SIGTERM ends it the default way (143 in a shell).
 */
final class Ci4JobsWorkTest extends TestCase
{
    private const NO_PCNTL = ['-d', 'disable_functions=pcntl_signal,pcntl_async_signals,pcntl_signal_get_handler'];

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('jobs-work');
        $run = self::php([ROOT . '/bin/migrate.php']);
        if ($run['code'] !== 0) {
            throw new RuntimeException('migrate: ' . $run['out'] . $run['err']);
        }
    }

    public static function tearDownAfterClass(): void
    {
        rrmdir(self::$dir);
    }

    public function testOnceRunsWithAndWithoutPcntl(): void
    {
        foreach (['pcntl' => [], 'no pcntl' => self::NO_PCNTL] as $case => $ini) {
            $r = self::php([...$ini, ROOT . '/spark', 'jobs:work', '--once']);
            $this->assertSame(0, $r['code'], $case . ': ' . $r['out'] . $r['err']);
            $this->assertSame('', $r['err'], $case);
        }
    }

    public function testSigtermIsACleanStopWithPcntl(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('this PHP has no pcntl');
        }
        $status = $this->terminate([]);
        $this->assertFalse($status['signaled'], 'the worker handled SIGTERM');
        $this->assertSame(0, $status['exitcode']);
    }

    public function testSigtermEndsTheWorkerWithoutPcntl(): void
    {
        $status = $this->terminate(self::NO_PCNTL);
        $this->assertTrue($status['signaled'], 'no handler: the default action');
        $this->assertSame(15, $status['termsig'], 'SIGTERM: exit 143 (128 + 15) in a shell');
    }

    /**
     * Starts a waiting worker, sends SIGTERM once it waits, and returns its final proc_get_status().
     *
     * @param list<string> $ini
     * @return array<string,mixed>
     */
    private function terminate(array $ini): array
    {
        $proc = proc_open([PHP_BINARY, ...$ini, ROOT . '/spark', 'jobs:work', '--sleep=100', '--max-seconds=20'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, ROOT, self::env());
        $this->assertIsResource($proc);
        stream_set_blocking($pipes[1], false);
        $out = '';
        for ($i = 0; $i < 100 && !str_contains($out, 'CodeIgniter'); $i++) {
            usleep(50000);
            $out .= (string)stream_get_contents($pipes[1]);
        }
        usleep(500000);   // past boot: the handler is in place and the worker waits
        $this->assertTrue(proc_get_status($proc)['running'], 'still waiting for a job');
        proc_terminate($proc, 15);
        $start = microtime(true);
        do {
            usleep(20000);
            $status = proc_get_status($proc);
        } while ($status['running'] && microtime(true) - $start < 5);
        fclose($pipes[1]);
        proc_close($proc);
        $this->assertFalse($status['running'], 'stopped within 5 s of SIGTERM');

        return $status;
    }

    /**
     * @param list<string> $args
     * @return array{code:int,out:string,err:string}
     */
    private static function php(array $args): array
    {
        $proc = proc_open([PHP_BINARY, ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, self::env());
        if (!is_resource($proc)) {
            throw new RuntimeException('proc_open');
        }
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($proc), 'out' => $out, 'err' => $err];
    }

    /** @return array<string,string> */
    private static function env(): array
    {
        return ['PATH' => (string)getenv('PATH'), 'DATA_DIR' => self::$dir, 'DB_DRIVER' => 'sqlite', 'SQLITE_PATH' => self::$dir . '/catalog.sqlite',
            'APOTHEKE_CONFIG' => '/nonexistent', 'SEED_DEFAULTS' => '0', 'APP_PASSWORD' => '', 'ALLOW_NO_LOGIN' => '1', 'TZ' => 'UTC'];
    }
}
