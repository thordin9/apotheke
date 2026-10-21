<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * bin/migrate.php prints the database it is about to open. A password in
 * DB_DSN or DB_PASSWORD must not be on that line or in the connection error.
 */
final class MigrateDsnTest extends TestCase
{
    /** @return array<string, array{0:?string,1:string,2:list<string>,3:list<string>}> */
    public static function passwordLines(): array
    {
        $later = ['dbname=keepdb', 'sslmode=keepmode', '(schema app)'];
        return [
            'lower-case key' => [
                'pgsql:host=127.0.0.1;port=1;password=SecretLower99;dbname=keepdb;sslmode=keepmode',
                'SecretEnv99',
                ['SecretLower99', 'SecretEnv99'],
                $later,
            ],
            'upper-case key' => [
                'pgsql:host=127.0.0.1;port=1;PASSWORD=SecretUpper99;dbname=keepdb;sslmode=keepmode',
                'SecretEnv99',
                ['SecretUpper99', 'SecretEnv99'],
                $later,
            ],
            'mixed-case key' => [
                'pgsql:host=127.0.0.1;port=1;Password=SecretMixed99;dbname=keepdb;sslmode=keepmode',
                'SecretEnv99',
                ['SecretMixed99', 'SecretEnv99'],
                $later,
            ],
            'quoted value with spaces' => [
                "pgsql:host=127.0.0.1;port=1;password='alpha SecretSpace99' dbname=keepdb sslmode=keepmode",
                'SecretEnv99',
                ['SecretSpace99', 'SecretEnv99'],
                $later,
            ],
            'quoted value with escaped quote' => [
                "pgsql:host=127.0.0.1;port=1;password='it\\'s a;SecretQuote99';dbname=keepdb;sslmode=keepmode",
                'SecretEnv99',
                ['SecretQuote99', 'SecretEnv99'],
                $later,
            ],
            'password is not the last key' => [
                'pgsql:host=127.0.0.1;port=1;password=SecretMid99;dbname=keepdb;sslmode=keepmode',
                'SecretEnv99',
                ['SecretMid99', 'SecretEnv99'],
                ['host=127.0.0.1', 'dbname=keepdb', 'sslmode=keepmode', '(schema app)'],
            ],
            'DB_PASSWORD only' => [
                null,
                'SecretEnvOnly99',
                ['SecretEnvOnly99'],
                ['dbname=keepdb', '(schema app)'],
            ],
        ];
    }

    /** @param list<string> $secrets @param list<string> $keep */
    #[DataProvider('passwordLines')]
    public function testMigratePrintsNoPassword(?string $dsn, string $dbPassword, array $secrets, array $keep): void
    {
        $run = $this->migrate($dsn, $dbPassword);
        $all = $run['out'] . $run['err'];
        $this->assertNotSame(0, $run['code'], $all);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        foreach ($keep as $token) {
            $this->assertStringContainsString($token, $run['out']);
        }
        $this->assertStringNotContainsString('password', strtolower($run['out']));
    }

    /** @return array{out:string,err:string,code:int} */
    private function migrate(?string $dsn, string $dbPassword): array
    {
        $root = dirname(__DIR__, 2);
        $data = sys_get_temp_dir() . '/apotheke-migrate-' . bin2hex(random_bytes(4));
        mkdir($data, 0775, true);
        $env = [
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $data,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
            'DB_DRIVER' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_NAME' => 'keepdb',
            'DB_USER' => 'apotheke',
            'DB_PASSWORD' => $dbPassword,
            'DB_SCHEMA' => 'app',
        ];
        if ($dsn !== null) $env['DB_DSN'] = $dsn;
        $proc = proc_open(
            [PHP_BINARY, $root . '/bin/migrate.php', '--status'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (is_dir($data)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
            @rmdir($data);
        }
        return ['out' => (string)$out, 'err' => (string)$err, 'code' => $code];
    }
}
