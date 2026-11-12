<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * In storage mode (the Worker sets STORAGE_BACKEND=r2) the app refuses to serve
 * unless DB_DRIVER is d1, so it cannot fall back to SQLite on the container disk.
 */
final class StorageModeBootErrorTest extends TestCase
{
    public function testStorageModeWithoutD1IsAGeneric503AndWritesNoSqliteFile(): void
    {
        foreach ([null, 'sqlite', 'pgsql'] as $driver) {
            $hit = $this->boot($driver);
            $this->assertSame(503, $hit['status'], (string)$driver . $hit['body'] . $hit['log'] . $hit['err']);
            $this->assertSame('Apotheke is not available right now.', $hit['body']);
            $this->assertStringContainsString('STORAGE_BACKEND=r2 needs DB_DRIVER=d1.', $hit['log']);
            $shown = $hit['body'] . $hit['err'];
            $this->assertStringNotContainsString('catalog.sqlite', $shown);
            $this->assertStringNotContainsString('/data', $shown);
            $this->assertStringNotContainsString('sqlite', strtolower($hit['body']));
            $this->assertStringNotContainsString('DB_DRIVER', $hit['body']);
            $this->assertFalse($hit['sqlite'], 'no catalog.sqlite under DATA_DIR');
        }
    }

    public function testLocalSqliteStillStarts(): void
    {
        $hit = $this->boot(null, storage: 'local');
        $this->assertSame(200, $hit['status'], $hit['body'] . $hit['log'] . $hit['err']);
        $this->assertSame('', $hit['body']);
    }

    /**
     * Storage mode with DB_DRIVER=d1 boots: startup.php runs before Composer's autoloader, so the
     * D1 settings it builds may not name a class it did not load itself (the server below has none).
     */
    public function testStorageModeWithD1BootsBeforeTheAutoloader(): void
    {
        $hit = $this->boot('d1');
        $this->assertSame(200, $hit['status'], $hit['body'] . $hit['log'] . $hit['err']);
        $this->assertSame('', $hit['body']);
        $this->assertStringNotContainsString('Fatal', $hit['log'] . $hit['err']);
        $this->assertFalse($hit['sqlite'], 'no catalog.sqlite under DATA_DIR');
    }

    public function testTheTranslatorsD1BaseIsTheExecutorsDefault(): void
    {
        $this->assertSame(\Apotheke\Core\Database\D1HttpExecutor::DEFAULT_BASE, \Apotheke\Core\Config\DsnTranslator::D1_BASE);
        $this->assertSame('d1:' . \Apotheke\Core\Database\D1HttpExecutor::DEFAULT_BASE, \Apotheke\Core\Config\DsnTranslator::D1_DSN);
    }

    /**
     * @return array{status:int,body:string,log:string,err:string,sqlite:bool}
     */
    private function boot(?string $driver, string $storage = 'r2'): array
    {
        $dir = tmpdir('storage-mode-boot');
        $log = $dir . '/error.log';
        $err = $dir . '/server.err';
        $router = $dir . '/router.php';
        $startup = dirname(__DIR__, 2) . '/modules/Core/Boot/startup.php';
        file_put_contents($router, "<?php\nrequire " . var_export($startup, true) . ";\n");
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($sock);
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=0',
            '-d', 'log_errors=1',
            '-d', 'error_log=' . $log,
            '-d', 'zend.exception_ignore_args=0',
            '-d', 'opcache.jit=disable',
            '-S', '127.0.0.1:' . $port,
            '-t', $dir,
            $router,
        ];
        $env = [
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $dir,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'STORAGE_BACKEND' => $storage,
        ];
        if ($driver !== null) {
            $env['DB_DRIVER'] = $driver;
        }
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $err, 'a'], 2 => ['file', $err, 'a']], $pipes, $dir, $env);
        $this->assertIsResource($proc);
        try {
            $up = false;
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
                if ($socket) {
                    fclose($socket);
                    $up = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($up, 'php -S did not start');
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
            $body = file_get_contents('http://127.0.0.1:' . $port . '/healthz', false, $context);
            $status = 0;
            foreach ($http_response_header ?? [] as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match) === 1) {
                    $status = (int)$match[1];
                }
            }

            return [
                'status' => $status,
                'body' => (string)$body,
                'log' => is_file($log) ? (string)file_get_contents($log) : '',
                'err' => is_file($err) ? (string)file_get_contents($err) : '',
                'sqlite' => is_file($dir . '/catalog.sqlite'),
            ];
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            rrmdir($dir);
        }
    }
}
