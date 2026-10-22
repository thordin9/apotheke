<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refused PostgreSQL connection must not reveal the login.
 * bin/seed.php, bin/backup.php, bin/restore.php and bin/settings.php exit 1
 * with the driver, host, port and dbname only. /healthz keeps its HTTP
 * response and logs that same line. The database user and libpq's
 * "password authentication …" text stay out of stdout, stderr and the log.
 */
final class DbConnectErrorTest extends TestCase
{
    private const USER = 'apotheke_test_user';
    private const PASSWORD = 'canarypass';

    /** @return array<string, array{0:string,1:list<string>}> */
    public static function cliTools(): array
    {
        $tmp = sys_get_temp_dir();
        return [
            'seed' => ['bin/seed.php', []],
            'backup' => ['bin/backup.php', [$tmp . '/apotheke-backup-unused.zip']],
            'restore' => ['bin/restore.php', [$tmp . '/apotheke-restore-unused.zip', '--dry-run']],
            'settings' => ['bin/settings.php', ['list']],
        ];
    }

    /** @param list<string> $args */
    #[DataProvider('cliTools')]
    public function testCliConnectFailureOmitsTheUserAndExits1(string $script, array $args): void
    {
        $run = $this->spawn($script, $args, $this->refusedEnv());
        $blob = $run['out'] . "\n" . $run['err'] . "\n" . $run['log'];
        $this->assertSame(1, $run['code'], $blob);
        $this->assertStringNotContainsString(self::USER, $blob);
        $this->assertStringNotContainsString(self::PASSWORD, $blob);
        $this->assertStringNotContainsString('password authentication', $blob);
        $this->assertStringNotContainsString('PDOException', $blob);
        $this->assertStringNotContainsString('Stack trace', $blob);
        $this->assertSame('', $run['out']);
        $this->assertSame($this->connectMessage(), $run['err']);
    }

    public function testHealthzConnectFailureLogsOnlyTheSanitizedLine(): void
    {
        $root = dirname(__DIR__, 2);
        $data = sys_get_temp_dir() . '/apotheke-healthz-' . bin2hex(random_bytes(4));
        mkdir($data, 0775, true);
        $log = $data . '/php-error.log';
        $serverLog = $data . '/server.log';
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($sock);
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=0',
            '-d', 'log_errors=1',
            '-d', 'error_log=' . $log,
            '-d', 'zend.exception_ignore_args=0',
            '-d', 'zend.exception_string_param_max_len=1000000',
            '-S', '127.0.0.1:' . $port,
            '-t', $root . '/public',
            $root . '/public/index.php',
        ];
        $env = [
            'PATH' => (string)getenv('PATH'),
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
        ] + $this->refusedEnv($data);
        $proc = proc_open($cmd, [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        try {
            $up = false;
            for ($i = 0; $i < 50; $i++) {
                $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($c) { fclose($c); $up = true; break; }
                usleep(50000);
            }
            $this->assertTrue($up, 'php -S did not listen');
            $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
            $body = (string)@file_get_contents('http://127.0.0.1:' . $port . '/healthz', false, $ctx);
            $headers = implode("\n", $http_response_header ?? []);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) $status = (int)$m[1];
            }
            $logged = is_file($log) ? (string)file_get_contents($log) : '';
            $server = is_file($serverLog) ? (string)file_get_contents($serverLog) : '';
            $blob = $headers . "\n" . $body . "\n" . $logged . "\n" . $server;
            $this->assertSame(500, $status, $blob);
            $this->assertSame('', $body, $blob);
            $this->assertStringNotContainsString(self::USER, $blob);
            $this->assertStringNotContainsString(self::PASSWORD, $blob);
            $this->assertStringNotContainsString('password authentication', $blob);
            $this->assertStringNotContainsString('Connection refused', $blob);
            $this->assertStringNotContainsString('SQLSTATE', $blob);
            $this->assertStringNotContainsString('Stack trace', $blob);
            $this->assertStringNotContainsString('PDOException', $blob);
            $message = rtrim($this->connectMessage(), "\n");
            $this->assertMatchesRegularExpression(
                '/^\[[^\]]+\] ' . preg_quote($message, '/') . "\n$/",
                $logged,
                $blob
            );
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            $this->removeTree($data);
        }
    }

    public function testDataDirectoryNotWritableExits1(): void
    {
        $dir = '/dev/null/apotheke-unwritable';
        $run = $this->spawn('bin/migrate.php', [], [
            'DATA_DIR' => $dir,
            'DB_DRIVER' => 'sqlite',
        ]);
        $blob = $run['out'] . "\n" . $run['err'] . "\n" . $run['log'];
        $this->assertSame(1, $run['code'], $blob);
        $this->assertSame('Data directory ' . $dir . ' is not writable.', $run['out']);
        $this->assertSame('', $run['err']);
    }

    /** SQLite open errors stay the engine message. They do not name a login. */
    public function testSqliteOpenErrorStaysUnchanged(): void
    {
        $run = $this->spawn('bin/seed.php', [], [
            'DB_DRIVER' => 'sqlite',
            'SQLITE_PATH' => '/proc/no-such-seed-dir/catalog.sqlite',
        ]);
        $blob = $run['out'] . "\n" . $run['err'] . "\n" . $run['log'];
        $this->assertNotSame(0, $run['code'], $blob);
        $this->assertStringContainsString('unable to open database file', $blob);
        $this->assertStringNotContainsString('Could not connect to the database', $blob);
        $this->assertStringNotContainsString(self::USER, $blob);
    }

    private function connectMessage(): string
    {
        return "Could not connect to the database (pgsql – host=127.0.0.1 port=1 dbname=keepdb).\n";
    }

    /** @return array<string,string> */
    private function refusedEnv(?string $dataDir = null): array
    {
        return [
            'DB_DRIVER' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_NAME' => 'keepdb',
            'DB_USER' => self::USER,
            'DB_PASSWORD' => self::PASSWORD,
            'DB_SCHEMA' => '',
            'DATA_DIR' => $dataDir ?? '',
        ];
    }

    /**
     * @param list<string> $args
     * @param array<string,string> $envExtra
     * @return array{out:string,err:string,code:int,log:string}
     */
    private function spawn(string $script, array $args, array $envExtra): array
    {
        $root = dirname(__DIR__, 2);
        $data = sys_get_temp_dir() . '/apotheke-dbfail-' . bin2hex(random_bytes(4));
        mkdir($data, 0775, true);
        $log = $data . '/php-error.log';
        $env = [
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $data,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
            'DB_DRIVER' => 'sqlite',
        ];
        foreach ($envExtra as $key => $value) {
            if ($key === 'DATA_DIR' && $value === '') continue;
            $env[$key] = $value;
        }
        $cmd = [
            PHP_BINARY,
            '-d', 'zend.exception_ignore_args=0',
            '-d', 'zend.exception_string_param_max_len=1000000',
            '-d', 'log_errors=1',
            '-d', 'display_errors=stderr',
            '-d', 'error_log=' . $log,
            $root . '/' . $script,
        ];
        foreach ($args as $arg) $cmd[] = $arg;
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $logged = is_file($log) ? (string)file_get_contents($log) : '';
        $this->removeTree($data);
        return ['out' => $out, 'err' => $err, 'code' => $code, 'log' => $logged];
    }

    private function removeTree(string $data): void
    {
        if (!is_dir($data)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
        @rmdir($data);
    }
}
