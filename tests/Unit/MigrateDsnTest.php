<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * bin/migrate.php prints only the driver, host, port, dbname, and DB_SCHEMA.
 * The user and the password are never on that line. A DSN that does not parse
 * is `<unparsed dsn>` and nothing more. The line is printed before connecting.
 */
final class MigrateDsnTest extends TestCase
{
    /** @return array<string, array{0:?string,1:string,2:list<string>,3:string,4?:string}> */
    public static function passwordLines(): array
    {
        $where = 'host=127.0.0.1 port=1 dbname=keepdb (schema app)';
        return [
            'lower-case key' => [
                'pgsql:host=127.0.0.1;port=1;password=SecretLower99;dbname=keepdb;sslmode=keepmode',
                'SecretEnv99',
                ['SecretLower99', 'SecretEnv99'],
                $where,
            ],
            'upper-case key' => [
                'pgsql:HOST=127.0.0.1;PORT=1;PASSWORD=SecretUpper99;DBNAME=keepdb',
                'SecretEnv99',
                ['SecretUpper99', 'SecretEnv99'],
                $where,
            ],
            'mixed-case key' => [
                'pgsql:Host=127.0.0.1;Port=1;Password=SecretMixed99;DbName=keepdb',
                'SecretEnv99',
                ['SecretMixed99', 'SecretEnv99'],
                $where,
            ],
            'quoted value with spaces' => [
                "pgsql:host=127.0.0.1;port=1;password='alpha SecretSpace99' dbname=keepdb sslmode=keepmode",
                'SecretEnv99',
                ['SecretSpace99', 'SecretEnv99', 'alpha'],
                $where,
            ],
            'quoted value with escaped quote' => [
                "pgsql:host=127.0.0.1;port=1;password='it\\'s a;SecretQuote99';dbname=keepdb;sslmode=keepmode",
                'SecretEnv99',
                ['SecretQuote99', 'SecretEnv99'],
                $where,
            ],
            'password is not the last key' => [
                'pgsql:host=127.0.0.1;port=1;password=SecretMid99;dbname=keepdb;sslmode=keepmode',
                'SecretEnv99',
                ['SecretMid99', 'SecretEnv99'],
                $where,
            ],
            'DB_PASSWORD only' => [
                null,
                'SecretEnvOnly99',
                ['SecretEnvOnly99'],
                $where,
            ],
            'space after equals' => [
                'pgsql:host=127.0.0.1;port=1;password= SecretAfterEq99;dbname=keepdb',
                'SecretEnv99',
                ['SecretAfterEq99', 'SecretEnv99'],
                $where,
            ],
            'space before equals' => [
                'pgsql:host=127.0.0.1;port=1;password =SecretBeforeEq99;dbname=keepdb',
                'SecretEnv99',
                ['SecretBeforeEq99', 'SecretEnv99'],
                $where,
            ],
            'unquoted escaped space in a password' => [
                "pgsql:host=127.0.0.1;port=1;password=SecretLead\\ Hidden99;dbname=keepdb",
                'SecretEnv99',
                ['SecretLead', 'Hidden99', 'SecretEnv99'],
                $where,
            ],
            'quoted dbname is one value' => [
                "pgsql:host=127.0.0.1;port=1;dbname='keepdb password=SecretEmbed99'",
                'SecretEnv99',
                ['SecretEnv99'],
                'host=127.0.0.1 port=1 dbname=keepdb password=SecretEmbed99 (schema app)',
            ],
            'escaped space is part of the host value' => [
                "pgsql:host=h\\ password=SecretEscHost99;port=1;dbname=keepdb",
                'SecretEnv99',
                ['SecretEnv99'],
                'host=h password=SecretEscHost99 port=1 dbname=keepdb (schema app)',
            ],
            'password value is not copied into a field' => [
                'pgsql:password=SecretAsDb99;host=127.0.0.1;port=1',
                'SecretEnv99',
                ['SecretAsDb99', 'SecretEnv99'],
                'host=127.0.0.1 port=1 (schema app)',
            ],
            'duplicate keys keep the last allowlisted value' => [
                'pgsql:host=SecretHost99;host=127.0.0.1;port=9;port=1;dbname=SecretDb99;dbname=keepdb;password=SecretFirst99;password=SecretLater99',
                'SecretEnv99',
                ['SecretHost99', 'SecretDb99', 'SecretFirst99', 'SecretLater99', 'SecretEnv99'],
                $where,
            ],
            'uri userinfo' => [
                'postgresql://SecretUser99:SecretUriPw99@127.0.0.1:1/keepdb',
                'SecretEnv99',
                ['SecretUser99', 'SecretUriPw99', 'SecretEnv99'],
                $where,
            ],
            'uri query password' => [
                'postgresql://127.0.0.1:1/keepdb?password=SecretQuery99&user=SecretQueryUser99',
                'SecretEnv99',
                ['SecretQuery99', 'SecretQueryUser99', 'SecretEnv99'],
                $where,
            ],
            'user is never printed' => [
                'pgsql:host=127.0.0.1;port=1;user=SecretDsnUser99;password=SecretPw99;dbname=keepdb',
                'SecretEnv99',
                ['SecretDsnUser99', 'SecretPw99', 'SecretEnv99', 'SecretCfgUser99'],
                $where,
                'SecretCfgUser99',
            ],
            'unterminated quote' => [
                "pgsql:host=127.0.0.1;dbname='SecretUnterm99",
                'SecretEnv99',
                ['SecretUnterm99', '127.0.0.1', 'SecretEnv99'],
                '<unparsed dsn>',
            ],
            'missing equals' => [
                'pgsql:host=127.0.0.1;dbname;password=SecretMissingEq99',
                'SecretEnv99',
                ['SecretMissingEq99', '127.0.0.1', 'SecretEnv99'],
                '<unparsed dsn>',
            ],
            'junk dsn' => [
                'not a dsn SecretJunk99',
                'SecretEnv99',
                ['SecretJunk99', 'SecretEnv99'],
                '<unparsed dsn>',
            ],
        ];
    }

    /** @param list<string> $secrets */
    #[DataProvider('passwordLines')]
    public function testMigratePrintsOnlyAllowlistedFields(?string $dsn, string $dbPassword, array $secrets, string $where, string $dbUser = 'apotheke'): void
    {
        $run = $this->migrate($dsn, $dbPassword, 'pgsql', $dbUser);
        $this->assertNotSame(0, $run['code'], $run['out'] . $run['err']);
        $this->assertSame('Database: pgsql – ' . $where . "\n", $run['out']);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $run['out'] . $run['err']);
        }
    }

    public function testSqlitePrintsTheDriverAndPath(): void
    {
        $run = $this->migrate('sqlite:password=SecretSqliteDsn99', 'SecretSqlite99', 'sqlite', 'SecretSqliteUser99');
        $path = $run['data'] . '/catalog.sqlite';
        $this->assertStringStartsWith('Database: sqlite – ' . $path . "\n", $run['out']);
        $this->assertStringNotContainsString('SecretSqliteDsn99', $run['out'] . $run['err']);
        $this->assertStringNotContainsString('SecretSqlite99', $run['out'] . $run['err']);
        $this->assertStringNotContainsString('SecretSqliteUser99', $run['out']);
    }

    /** @return array{out:string,err:string,code:int,data:string} */
    private function migrate(?string $dsn, string $dbPassword, string $driver = 'pgsql', string $dbUser = 'apotheke'): array
    {
        $root = dirname(__DIR__, 2);
        $data = sys_get_temp_dir() . '/apotheke-migrate-' . bin2hex(random_bytes(4));
        mkdir($data, 0775, true);
        $env = [
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $data,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
            'DB_DRIVER' => $driver,
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_NAME' => 'keepdb',
            'DB_USER' => $dbUser,
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
        return ['out' => (string)$out, 'err' => (string)$err, 'code' => $code, 'data' => $data];
    }
}
