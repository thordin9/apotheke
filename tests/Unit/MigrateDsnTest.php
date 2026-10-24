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

    public function testCi4MigratePrintsOnlyAllowlistedFields(): void
    {
        if (PHP_VERSION_ID < 80400 || !is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php')) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        foreach (self::passwordLines() as $name => $row) {
            $dsn = $row[0];
            $dbPassword = $row[1];
            $secrets = $row[2];
            $where = $row[3];
            $dbUser = $row[4] ?? 'apotheke';
            $run = $this->migrate($dsn, $dbPassword, 'pgsql', $dbUser, [], [], true);
            $this->assertNotSame(0, $run['code'], $name . $run['out'] . $run['err']);
            $this->assertSame('Database: pgsql – ' . $where . "\n", $run['out'], $name . $run['err']);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $run['out'] . $run['err'], $name);
            }
        }
    }

    public function testConnectFailureHidesUserAndPasswordWhenExceptionArgsAreVisible(): void
    {
        $user = 'zzuser9';
        $password = 'zzpass9';
        $legacy = $this->migrate(
            'pgsql:host=127.0.0.1;port=1;dbname=keepdb;user=zzuser9;password=zzpass9',
            $password,
            'pgsql',
            $user,
            ['zend.exception_ignore_args' => '0']
        );
        $this->assertConnectFailureIsAllowlisted($legacy, $user, $password);
        if (PHP_VERSION_ID < 80400 || !is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php')) {
            return;
        }
        $ci4 = $this->migrate(
            'pgsql:host=127.0.0.1;port=1;dbname=keepdb;user=zzuser9;password=zzpass9',
            $password,
            'pgsql',
            $user,
            ['zend.exception_ignore_args' => '0'],
            [],
            true
        );
        $this->assertConnectFailureIsAllowlisted($ci4, $user, $password);
    }

    /** @param array{out:string,err:string,code:int,data:string} $run */
    private function assertConnectFailureIsAllowlisted(array $run, string $user, string $password): void
    {
        $text = $run['out'] . $run['err'];
        $this->assertNotSame(0, $run['code'], $text);
        $this->assertStringContainsString('host=127.0.0.1', $text);
        $this->assertStringContainsString('port=1', $text);
        $this->assertStringContainsString('dbname=keepdb', $text);
        $this->assertStringNotContainsString($user, $text);
        $this->assertStringNotContainsString($password, $text);
        $this->assertStringNotContainsString('PDO->__construct', $text);
    }

    /**
     * A refused PostgreSQL connection must not reveal the login.
     * libpq names the user ("password authentication failed for user …").
     * With zend.exception_ignore_args off, the trace includes the DSN and the
     * user. On PHP 8.2 and newer the password argument is SensitiveParameterValue;
     * the password text appears only when it is embedded in the DSN and
     * zend.exception_string_param_max_len is raised. This test turns that limit
     * up and checks stdout, stderr, and the error log.
     */
    public function testConnectFailureOmitsUserPasswordAndDsn(): void
    {
        $user = 'canaryuser';
        $password = 'canarypass';
        $log = tempnam(sys_get_temp_dir(), 'apotheke-mig-');
        $this->assertNotFalse($log);
        $dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=keepdb;user=' . $user . ';password=' . $password;
        $where = 'host=127.0.0.1 port=5432 dbname=keepdb (schema app)';
        try {
            $run = $this->migrate($dsn, $password, 'pgsql', $user, [
                'zend.exception_ignore_args' => '0',
                'zend.exception_string_param_max_len' => '1000000',
                'log_errors' => '1',
                'display_errors' => 'stderr',
                'error_log' => $log,
            ], [
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '5432',
                'DB_NAME' => 'keepdb',
            ]);
            $logged = is_file($log) ? (string)file_get_contents($log) : '';
            $blob = $run['out'] . "\n" . $run['err'] . "\n" . $logged;
            $this->assertStringNotContainsString($user, $run['out']);
            $this->assertStringNotContainsString($user, $run['err']);
            $this->assertStringNotContainsString($user, $logged);
            $this->assertStringNotContainsString($password, $run['out']);
            $this->assertStringNotContainsString($password, $run['err']);
            $this->assertStringNotContainsString($password, $logged);
            $this->assertStringNotContainsString($dsn, $blob);
            $this->assertStringNotContainsString('password authentication failed', $blob);
            $this->assertStringNotContainsString('PDOException', $blob);
            $this->assertStringNotContainsString('Stack trace', $blob);
            $this->assertSame(1, $run['code'], $blob);
            $this->assertSame('Database: pgsql – ' . $where . "\n", $run['out']);
            $this->assertSame('Could not connect to the database (pgsql – ' . $where . ").\n", $run['err']);
        } finally {
            @unlink((string)$log);
        }
    }

    /** SQLite open errors stay the raw engine message. PostgreSQL is the path that names a user. */
    public function testSqliteOpenErrorStaysUnchanged(): void
    {
        $run = $this->migrate(null, 'canarypass', 'sqlite', 'canaryuser', [], [
            'SQLITE_PATH' => '/proc/no-such-migrate-dir/catalog.sqlite',
        ]);
        $blob = $run['out'] . "\n" . $run['err'];
        $this->assertNotSame(0, $run['code']);
        $this->assertStringContainsString('unable to open database file', $blob);
        $this->assertStringNotContainsString('Could not connect to the database', $blob);
    }

    /**
     * @param array<string,string> $ini
     * @param array<string,string> $envExtra
     * @param bool $ci4 run bin/migrate.php with APOTHEKE_APP=ci4
     * @return array{out:string,err:string,code:int,data:string}
     */
    private function migrate(?string $dsn, string $dbPassword, string $driver = 'pgsql', string $dbUser = 'apotheke', array $ini = [], array $envExtra = [], bool $ci4 = false): array
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
        if ($ci4) $env['APOTHEKE_APP'] = 'ci4';
        $env = array_merge($env, $envExtra);
        $cmd = [PHP_BINARY];
        foreach ($ini as $key => $value) {
            $cmd[] = '-d';
            $cmd[] = $key . '=' . $value;
        }
        $cmd[] = $root . '/bin/migrate.php';
        $cmd[] = '--status';
        $proc = proc_open(
            $cmd,
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
