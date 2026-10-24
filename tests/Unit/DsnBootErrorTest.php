<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * startup.php's public body is one sentence. The log line is driver, host,
 * port and dbname. A password in a URI, around "=", or containing ";" is
 * not copied into either.
 */
final class DsnBootErrorTest extends TestCase
{
    public function testCanaryPasswordsStayOutOfTheBodyAndTheLog(): void
    {
        $cases = [
            'uri' => [
                'dsn' => 'postgresql://zzu:u9d44@db.example:5432/catalog',
                'log' => 'Database pgsql host=db.example port=5432 dbname=catalog',
                'absent' => ['u9d44', 'zzu', 'postgresql://'],
            ],
            // The log names the configured DB_DRIVER, not the DSN's prefix.
            'unsupported-prefix' => [
                'dsn' => 'mysql:host=db.example;port=5432;dbname=catalog;password = s9d44',
                'log' => 'Database pgsql host=db.example port=5432 dbname=catalog',
                'absent' => ['s9d44', 'password', 'mysql'],
            ],
            // Spaces around "=" are allowed, as libpq does, so the DSN is valid.
            // startup.php does not connect: boot goes on (the harness router then
            // sends an empty 200) and nothing is logged. The canary and the word
            // password stay out of the body, the log, and stderr.
            'spaces' => [
                'dsn' => 'pgsql:host = db.example;port = 5432;dbname = catalog;password = s9d44',
                'accepted' => true,
                'absent' => ['s9d44', 'password', 'could not be used'],
            ],
            // user= or password= inside another value: PDO 8.4 would drop DB_USER and
            // DB_PASSWORD, so boot stops with a message that names no values.
            'credential-text' => [
                'dsn' => "pgsql:host=db.example;port=5432;dbname=catalog;options='-c x=password=s9d44'",
                'body' => 'The database settings could not be used: DB_DSN has user= or password= inside another',
                'log' => 'Database pgsql host=db.example port=5432 dbname=catalog. The database settings could not be used: DB_DSN has',
                'absent' => ['s9d44', '-c x'],
            ],
            'semicolon' => [
                'dsn' => 'pgsql:host=db.example;port=5432;dbname=catalog;password=q9;w8zz',
                'log' => 'Database pgsql',
                'absent' => ['w8zz', 'q9', 'db.example', 'password'],
            ],
        ];
        foreach ($cases as $name => $case) {
            $hit = $this->boot($case['dsn']);
            $shown = $hit['body'] . $hit['log'] . $hit['err'];
            foreach ($case['absent'] as $needle) {
                $this->assertStringNotContainsString($needle, $shown, $name . $shown);
            }
            $this->assertStringNotContainsString($case['dsn'], $shown, $name);
            if (isset($case['accepted'])) {
                $this->assertSame(200, $hit['status'], $name . $hit['body'] . $hit['log'] . $hit['err']);
                $this->assertSame('', $hit['body'], $name);
                $this->assertSame('', $hit['log'], $name . $hit['log']);
                continue;
            }
            $this->assertSame(500, $hit['status'], $name . $hit['body'] . $hit['log'] . $hit['err']);
            if (isset($case['body'])) {
                $this->assertStringStartsWith($case['body'], $hit['body'], $name);
            } else {
                $this->assertSame('The database settings could not be used.', $hit['body'], $name);
            }
            $this->assertStringContainsString($case['log'], $hit['log'], $name . $hit['log']);
            if ($name === 'unsupported-prefix') {
                $this->assertStringNotContainsString('mysql', $hit['log'], $name);
            }
        }
    }

    /** @return array{status:int,body:string,log:string,err:string} */
    private function boot(string $dsn): array
    {
        $dir = tmpdir('dsn-boot');
        $log = $dir . '/error.log';
        $err = $dir . '/server.err';
        $router = $dir . '/router.php';
        $startup = dirname(__DIR__, 2) . '/ci4/modules/Core/Boot/startup.php';
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
            '-S', '127.0.0.1:' . $port,
            '-t', $dir,
            $router,
        ];
        $env = [
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $dir,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'DB_DRIVER' => 'pgsql',
            'DB_DSN' => $dsn,
            'DB_HOST' => 'db.example',
            'DB_PORT' => '5432',
            'DB_NAME' => 'catalog',
            'DB_USER' => 'zzu',
            'DB_PASSWORD' => 'u9d44',
        ];
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
            ];
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            rrmdir($dir);
        }
    }
}
