<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/legacy-bootstrap.php';

/**
 * main's #58 behaviour on the CI4 app: a refused database connect and an
 * unwritable data directory. The CI4 side must give the same status, body and
 * allowlisted line as legacy, and never the user, the password, the DSN or
 * libpq's own text.
 */
final class Ci4DatabaseDownTest extends TestCase
{
    use SkipsOutsideCi;

    private const USER = 'zzci4user7';
    private const PASSWORD = 'zzci4pass7';
    private const WHERE = 'host=127.0.0.1 port=1 dbname=keepdb';

    public function testHealthEndpointsAnswerASanitized500WhileTheDatabaseIsDown(): void
    {
        $this->requireCi4();
        $env = $this->refusedEnv() + ['CI_ENVIRONMENT' => 'production', 'APOTHEKE_TEST_DB' => null];
        $line = db_connect_failure_message('pgsql', self::WHERE);
        foreach (['/healthz' => 'text/plain', '/health' => 'application/json'] as $path => $type) {
            $dir = tmpdir('ci4-dbdown');
            $legacyDir = tmpdir('legacy-dbdown');
            $ci4 = new Server($dir, $env, 'ci4', ROOT . '/public/router.php');
            $legacy = new Server($legacyDir, $env, 'legacy');
            $before = $this->ci4Logs();
            try {
                $got = $ci4->client()->get($path);
                $want = $legacy->client()->get($path);
                $log = (string)@file_get_contents($ci4->log);
                $blob = implode("\n", $got['raw'] ?? []) . "\n" . $got['body'] . "\n" . $log;
                $this->assertSame(500, $got['status'], $blob);
                $this->assertSame('', $got['body'], $blob);
                $this->assertSame($want['status'], $got['status'], $path);
                $this->assertSame($want['body'], $got['body'], $path);
                $this->assertStringStartsWith($type, $got['headers']['content-type'] ?? '', $blob);
                $this->assertSame($want['headers']['content-type'] ?? null, $got['headers']['content-type'] ?? null, $path);
                if ($path === '/healthz') {
                    $this->assertSame($want['headers']['x-apotheke-version'] ?? null, $got['headers']['x-apotheke-version'] ?? null);
                } else {
                    $this->assertSame('no-store', $got['headers']['cache-control'] ?? null, $blob);
                }
                $this->assertStringContainsString('] ' . $line . "\n", $log, $blob);
                $this->assertNoSecrets($blob);
                $this->assertStringNotContainsString('MigrateConnectException', $blob);
                $this->assertStringNotContainsString('Server error', $blob);
                $this->assertSame($before, $this->ci4Logs(), 'CI4 wrote to writable/logs');
            } finally {
                $ci4->stop();
                $legacy->stop();
                rrmdir($dir);
                rrmdir($legacyDir);
                @unlink($dir . '.server.log');
                @unlink($legacyDir . '.server.log');
            }
        }
    }

    /**
     * A SQLite file that cannot be opened (QA #137 L-500): the sanitized line in the server log, as legacy,
     * and no framework log entry with a stack trace in writable/logs.
     */
    public function testUnopenableSqliteFileIsTheSanitizedLineWithoutAFrameworkLog(): void
    {
        $this->requireCi4();
        $file = '/proc/no-such-ci4-dbdown-dir/catalog.sqlite';
        $env = ['DB_DRIVER' => 'sqlite', 'SQLITE_PATH' => $file, 'CI_ENVIRONMENT' => 'production', 'APOTHEKE_TEST_DB' => null];
        $line = db_connect_failure_message('sqlite', $file);
        $dir = tmpdir('ci4-sqlitedown');
        $legacyDir = tmpdir('legacy-sqlitedown');
        $ci4 = new Server($dir, $env, 'ci4', ROOT . '/public/router.php');
        $legacy = new Server($legacyDir, $env, 'legacy');
        $before = $this->ci4Logs();
        try {
            foreach (['/healthz', '/health'] as $path) {
                $got = $ci4->client()->get($path);
                $want = $legacy->client()->get($path);
                $log = (string)@file_get_contents($ci4->log);
                $blob = implode("\n", $got['raw'] ?? []) . "\n" . $got['body'] . "\n" . $log;
                $this->assertSame(500, $got['status'], $path . $blob);
                $this->assertSame($want['status'], $got['status'], $path);
                $this->assertSame($want['body'], $got['body'], $path);
                $this->assertStringContainsString('] ' . $line . "\n", $log, $blob);
                $this->assertStringNotContainsString('Stack trace', $blob);
                $this->assertStringNotContainsString('SQLite3 error', $blob);
            }
            $this->assertSame($before, $this->ci4Logs(), 'CI4 wrote to writable/logs');
        } finally {
            $ci4->stop();
            $legacy->stop();
            rrmdir($dir);
            rrmdir($legacyDir);
            @unlink($dir . '.server.log');
            @unlink($legacyDir . '.server.log');
        }
    }

    /** @return array<string,int|false> CI4's framework log files and their sizes */
    private function ci4Logs(): array
    {
        clearstatcache();
        $sizes = [];
        foreach (glob(ROOT . '/writable/logs/*.log') ?: [] as $file) {
            $sizes[basename($file)] = filesize($file);
        }

        return $sizes;
    }

    /**
     * CI4's own Postgre connect, the request's only login, is refused (wrong password).
     * This is the only test that catches a missing catch in Services::apothekeDb, so in
     * CI it fails rather than skips when PostgreSQL is down or accepts any password.
     */
    public function testCi4PostgreConnectRefusalIsTheSanitizedLine(): void
    {
        $this->requireCi4();
        $pg = test_pg_params();
        try {
            test_pg_admin();
        } catch (PDOException) {
            $this->skipOutsideCi('PostgreSQL is not reachable', 'give the PostgreSQL service password auth (scram-sha-256 or md5).');
        }
        $wrong = 'wrong-ci4-' . bin2hex(random_bytes(4));
        try {
            new PDO('pgsql:host=' . $pg['host'] . ';port=' . $pg['port'] . ';dbname=' . $pg['name'], $pg['user'], $wrong);
            $this->skipOutsideCi('PostgreSQL accepts any password (trust auth)', 'give the PostgreSQL service password auth (scram-sha-256 or md5).');
        } catch (PDOException) {
        }
        $dir = tmpdir('ci4-ci4connect');
        $schema = test_schema($dir);
        $server = new Server($dir, [
            'APOTHEKE_TEST_DB' => null,
            'DB_DRIVER' => 'pgsql',
            'DB_HOST' => $pg['host'],
            'DB_PORT' => $pg['port'],
            'DB_NAME' => $pg['name'],
            'DB_USER' => $pg['user'],
            'DB_PASSWORD' => $pg['password'],
            'DB_SCHEMA' => $schema,
            'APOTHEKE_TEST_CI4_WRONG_PASSWORD' => $wrong,
        ], 'ci4');
        try {
            $got = $server->client()->get('/healthz');
            $log = (string)@file_get_contents($server->log);
            $blob = implode("\n", $got['raw'] ?? []) . "\n" . $got['body'];
            $where = 'host=' . $pg['host'] . ' port=' . $pg['port'] . ' dbname=' . $pg['name'] . ' (schema ' . $schema . ')';
            $this->assertSame(500, $got['status'], $blob . "\n" . $log);
            $this->assertSame('', $got['body'], $log);
            $this->assertStringContainsString('] ' . db_connect_failure_message('pgsql', $where) . "\n", $log);
            $this->assertStringNotContainsString('Server error', $blob);
            $this->assertStringNotContainsString($wrong, $blob . "\n" . $log);
            $this->assertStringNotContainsString($pg['password'], $blob . "\n" . $log);
            $this->assertStringNotContainsString('password authentication', $blob);
            $this->assertStringNotContainsString('DatabaseException', $blob . "\n" . $log);
        } finally {
            $server->stop();
            try {
                test_pg_admin()->exec('DROP SCHEMA IF EXISTS ' . test_pg_ident($schema) . ' CASCADE');
            } catch (PDOException) {
            }
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /** bin/migrate.php and spark exit 1 with legacy's line, through the command's int return. */
    public function testCliConnectFailureMatchesLegacyAndExitsThroughTheCommand(): void
    {
        $this->requireCi4();
        $legacy = $this->run_(['bin/migrate.php'], $this->refusedEnv() + ['APOTHEKE_APP' => 'legacy']);
        $ci4 = $this->run_(['bin/migrate.php'], $this->refusedEnv() + ['APOTHEKE_APP' => 'ci4']);
        $spark = $this->run_(['spark', 'apotheke:migrate', '--no-header'], $this->refusedEnv());
        $err = db_connect_failure_message('pgsql', self::WHERE) . "\n";
        foreach (['legacy' => $legacy, 'ci4' => $ci4, 'spark' => $spark] as $name => $run) {
            $blob = $run['out'] . "\n" . $run['err'];
            $this->assertSame(1, $run['code'], $name . $blob);
            $this->assertSame('Database: pgsql – ' . self::WHERE . "\n", $run['out'], $name . $blob);
            $this->assertSame($err, $run['err'], $name);
            $this->assertNoSecrets($blob);
        }
        $command = (string)file_get_contents(ROOT . '/modules/Migrations/Commands/Migrate.php');
        $this->assertDoesNotMatchRegularExpression('/\bexit\s*\(/', $command);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/modules', FilesystemIterator::SKIP_DOTS)) as $file) {
            $this->assertStringNotContainsString('set_exception_handler', (string)file_get_contents((string)$file), (string)$file);
        }
    }

    public function testUnwritableDataDirectoryExits1LikeLegacy(): void
    {
        $this->requireCi4();
        $bad = '/dev/null/apotheke-unwritable';
        $msg = 'Data directory ' . $bad . ' is not writable.';
        $env = ['DATA_DIR' => $bad, 'DB_DRIVER' => 'sqlite'];
        $legacy = $this->run_(['bin/migrate.php'], $env + ['APOTHEKE_APP' => 'legacy']);
        $ci4 = $this->run_(['bin/migrate.php'], $env + ['APOTHEKE_APP' => 'ci4']);
        $spark = $this->run_(['spark', 'apotheke:migrate', '--no-header'], $env);
        foreach (['legacy' => $legacy, 'ci4' => $ci4, 'spark' => $spark] as $name => $run) {
            $this->assertSame(1, $run['code'], $name . $run['out'] . $run['err']);
            $this->assertSame('', $run['out'], $name);
            $this->assertSame($msg . "\n", $run['err'], $name);
        }

        $dir = tmpdir('ci4-unwritable');
        $legacyDir = tmpdir('legacy-unwritable');
        $web = ['DATA_DIR' => $bad, 'DB_DRIVER' => 'sqlite', 'CI_ENVIRONMENT' => 'production', 'APOTHEKE_TEST_DB' => null];
        $ci4Server = new Server($dir, $web, 'ci4', ROOT . '/public/router.php');
        $legacyServer = new Server($legacyDir, $web, 'legacy');
        try {
            $got = $ci4Server->client()->get('/healthz');
            $want = $legacyServer->client()->get('/healthz');
            $this->assertSame(500, $got['status'], (string)@file_get_contents($ci4Server->log));
            $this->assertSame($msg, $got['body']);
            $this->assertSame($want['status'], $got['status']);
            $this->assertSame($want['body'], $got['body']);
        } finally {
            $ci4Server->stop();
            $legacyServer->stop();
            rrmdir($dir);
            rrmdir($legacyDir);
            @unlink($dir . '.server.log');
            @unlink($legacyDir . '.server.log');
        }
    }

    private function requireCi4(): void
    {
        if (PHP_VERSION_ID < 80400 || !is_file(ROOT . '/vendor/autoload.php')) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
    }

    private function assertNoSecrets(string $blob): void
    {
        $this->assertStringNotContainsString(self::USER, $blob);
        $this->assertStringNotContainsString(self::PASSWORD, $blob);
        $this->assertStringNotContainsString('password authentication', $blob);
        $this->assertStringNotContainsString('Connection refused', $blob);
        $this->assertStringNotContainsString('SQLSTATE', $blob);
        $this->assertStringNotContainsString('Stack trace', $blob);
        $this->assertStringNotContainsString('PDOException', $blob);
    }

    /** @return array<string,string> */
    private function refusedEnv(): array
    {
        return [
            'DB_DRIVER' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_NAME' => 'keepdb',
            'DB_USER' => self::USER,
            'DB_PASSWORD' => self::PASSWORD,
            'DB_SCHEMA' => '',
        ];
    }

    /**
     * @param list<string> $argv script relative to the repo root, then its arguments
     * @param array<string,string> $extra
     * @return array{out:string,err:string,code:int}
     */
    private function run_(array $argv, array $extra): array
    {
        $data = tmpdir('ci4-dbdown-cli');
        $env = array_merge([
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $data,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
            'CI_ENVIRONMENT' => 'production',
        ], $extra);
        $argv[0] = ROOT . '/' . $argv[0];
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', ...$argv], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, $env);
        $this->assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        rrmdir($data);

        return ['out' => $out, 'err' => $err, 'code' => $code];
    }
}
