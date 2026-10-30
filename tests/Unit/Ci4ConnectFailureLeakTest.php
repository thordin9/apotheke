<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Forced connect failures (bad password, bad host, malformed DSN) on the
 * migration runner's PDO path (CLI and /healthz) and on CI4's own pg_connect
 * path. No DSN, user or password value may reach the HTTP body, the server
 * log, stderr/stdout or CI4's writable/logs. Exception arguments are kept in
 * traces (zend.exception_ignore_args=0), the setting least kind to us.
 */
final class Ci4ConnectFailureLeakTest extends TestCase
{
    use SkipsOutsideCi;

    private ?PDO $admin = null;
    private string $tag = '';
    private string $user = '';
    private string $password = '';
    private string $schema = '';
    private string $ini = '';

    protected function setUp(): void
    {
        try {
            $admin = test_pg_admin();
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
        $this->skipUnlessPgRejectsWrongPasswords();   // before $this->admin: tearDown has nothing to drop yet
        $this->admin = $admin;
        $this->tag = bin2hex(random_bytes(4));
        $this->user = 'lk_u_' . $this->tag;
        $this->password = 'lkpw_' . $this->tag;
        // Its own random name: the schema is printed on purpose, the tag must not be.
        $this->schema = 'lks_' . bin2hex(random_bytes(4));
        $this->admin->exec('CREATE ROLE ' . $this->user . " LOGIN PASSWORD '" . $this->password . "'");
        $this->admin->exec('CREATE SCHEMA ' . $this->schema . ' AUTHORIZATION ' . $this->user);
        $this->ini = tmpdir('lk-ini');
        file_put_contents($this->ini . '/zz-args.ini', "zend.exception_ignore_args=0\ndisplay_errors=0\n");
    }

    protected function tearDown(): void
    {
        if ($this->admin instanceof PDO) {
            $this->admin->exec('DROP EVENT TRIGGER IF EXISTS lk_login_' . $this->tag);
            $this->admin->exec('DROP FUNCTION IF EXISTS lk_login_' . $this->tag . '()');
            $this->admin->exec('DROP SEQUENCE IF EXISTS public.lk_seq_' . $this->tag);
            $this->admin->exec('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->admin->exec('DROP ROLE IF EXISTS ' . $this->user);
        }
        if ($this->ini !== '') {
            rrmdir($this->ini);
        }
    }

    /** @return array<string,array{dsn:string,user:string,password:string}> */
    private function cases(): array
    {
        $pg = test_pg_params();
        $base = sprintf('pgsql:host=%s;port=%s;dbname=%s', $pg['host'], $pg['port'], $pg['name']);
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($sock);
        $closed = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);

        return [
            'bad password' => [
                'dsn' => $base . ';user=lkdu_' . $this->tag . ';password=lkdp_' . $this->tag,
                'user' => $this->user,
                'password' => 'lkbad_' . $this->tag,
            ],
            'bad host' => [
                'dsn' => sprintf('pgsql:host=127.0.0.1;port=%d;dbname=%s;password=lkdp_%s', $closed, $pg['name'], $this->tag),
                'user' => $this->user,
                'password' => $this->password,
            ],
            'malformed DSN' => [
                'dsn' => $base . ";user=lkdu_" . $this->tag . ";password='lkdp_" . $this->tag,
                'user' => $this->user,
                'password' => $this->password,
            ],
        ];
    }

    /** @param array{dsn:string,user:string,password:string} $case */
    private function assertNoValues(string $label, string $shown, array $case): void
    {
        $values = [$case['dsn'], $case['user'], $case['password'], $this->password, 'lkdu_' . $this->tag, 'lkdp_' . $this->tag, 'lkbad_' . $this->tag];
        foreach (array_unique($values) as $value) {
            $this->assertStringNotContainsString($value, $shown, $label . "\n" . $shown);
        }
        // The tag catches any copy of a value, partial or reshaped (quoted, escaped, ";" as a space).
        $this->assertStringNotContainsString($this->tag, $shown, $label . "\n" . $shown);
    }

    /** The runner on its own, in a child PHP, with the exception left uncaught so its trace is printed. Every PHP version. */
    public function testRunnerFailuresPrintNoValues(): void
    {
        $script = $this->ini . '/runner.php';
        file_put_contents($script, "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/modules/Migrations/PdoMigrationRunner.php', true) . ";\n"
            . "(new Apotheke\\Migrations\\PdoMigrationRunner(" . var_export(dirname(__DIR__, 2) . '/migrations', true) . "))->connect(['driver' => 'pgsql', 'dsn' => getenv('LK_DSN'), 'user' => getenv('LK_USER'), 'password' => getenv('LK_PASSWORD'), 'schema' => '']);\n"
            . "echo 'connected';\n");
        foreach ($this->cases() as $label => $case) {
            [$code, $out] = $this->exec([PHP_BINARY, $script], ['LK_DSN' => $case['dsn'], 'LK_USER' => $case['user'], 'LK_PASSWORD' => $case['password']]);
            $this->assertNotSame(0, $code, $label . $out);
            $this->assertStringContainsString('MigrateConnectException', $out, $label . $out);
            $this->assertNoValues('runner ' . $label, $out, $case);
        }
    }

    /** apotheke:migrate (spark) and /healthz on the shipped router, PHP 8.4 with CI4 vendor. */
    public function testCliAndHttpFailuresShowNoValues(): void
    {
        $this->needCi4();
        foreach ($this->cases() as $label => $case) {
            $env = $this->appEnv($case);
            $before = $this->ci4Logs();
            [$code, $out] = $this->exec([PHP_BINARY, dirname(__DIR__, 2) . '/spark', 'apotheke:migrate'], $env);
            $this->assertNotSame(0, $code, $label . $out);
            $this->assertNoValues('cli ' . $label, $out . $this->ci4LogsSince($before), $case);

            $hit = $this->healthz($env);
            $this->assertSame(500, $hit['status'], $label . $hit['body'] . $hit['log']);
            $this->assertNoValues('http ' . $label, $hit['body'] . "\n" . $hit['log'], $case);
        }
    }

    /**
     * CI4's own pg_connect failing after the runner logged in: a login event
     * trigger changes the role's password on its first login (the runner's;
     * Config\App opens no connection of its own), so CI4's connection gets
     * libpq's "password authentication failed for user ...".
     */
    public function testCi4PgConnectFailureShowsNoValues(): void
    {
        $this->needCi4();
        if ($this->admin->query('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn() !== true) {
            $this->skipOutsideCi('a login event trigger needs a superuser', 'connect the test PostgreSQL as a superuser.');
        }
        $pg = test_pg_params();
        $fn = 'lk_login_' . $this->tag;
        $this->admin->exec('CREATE SEQUENCE public.lk_seq_' . $this->tag);
        $this->admin->exec("CREATE FUNCTION $fn() RETURNS event_trigger LANGUAGE plpgsql SECURITY DEFINER AS \$f\$
            BEGIN
              IF session_user = '{$this->user}' AND nextval('public.lk_seq_{$this->tag}') = 1 THEN
                EXECUTE 'ALTER ROLE {$this->user} PASSWORD ''lkchanged_{$this->tag}''';
              END IF;
            END \$f\$");
        $this->admin->exec("CREATE EVENT TRIGGER $fn ON login EXECUTE FUNCTION $fn()");
        $case = ['dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $pg['host'], $pg['port'], $pg['name']), 'user' => $this->user, 'password' => $this->password];
        $before = $this->ci4Logs();
        $hit = $this->healthz($this->appEnv($case));
        $logs = $this->ci4LogsSince($before);
        // One login got in (the runner); CI4's second was refused before the trigger.
        $this->assertSame(1, (int)$this->admin->query('SELECT last_value FROM public.lk_seq_' . $this->tag)->fetchColumn());
        // Like legacy db(): 500, an empty body and only the allowlisted line (#58).
        $this->assertSame(500, $hit['status'], $hit['body'] . $hit['log'] . $logs);
        $this->assertSame('', $hit['body']);
        $this->assertStringContainsString('Could not connect to the database (pgsql – ', $hit['log']);
        // With pg_connect muted, CI4 has no libpq text to log: nothing reaches writable/logs.
        $this->assertStringNotContainsString('password authentication failed', $hit['log']);
        $this->assertSame('', $logs, 'CI4 wrote to writable/logs on a refused connect');
        $this->assertNoValues('pg_connect', $hit['body'] . "\n" . $hit['log'] . "\n" . $logs, $case);
    }

    private function needCi4(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
    }

    /**
     * @param array{dsn:string,user:string,password:string} $case
     * @return array<string,?string>
     */
    private function appEnv(array $case): array
    {
        return [
            'CI_ENVIRONMENT' => 'production',
            'APOTHEKE_TEST_DB' => null,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'DB_DRIVER' => 'pgsql',
            'DB_DSN' => $case['dsn'],
            'DB_HOST' => null,
            'DB_PORT' => null,
            'DB_NAME' => null,
            'DB_USER' => $case['user'],
            'DB_PASSWORD' => $case['password'],
            'DB_SCHEMA' => $this->schema,
            'PGPASSWORD' => null,
            'PGUSER' => null,
            'PGPASSFILE' => '/nonexistent',
            'PHP_INI_SCAN_DIR' => ':' . $this->ini,
        ];
    }

    /**
     * @param list<string> $cmd
     * @param array<string,?string> $env
     * @return array{0:int,1:string}
     */
    private function exec(array $cmd, array $env): array
    {
        $dir = tmpdir('lk-run');
        $full = array_merge(getenv(), ['DATA_DIR' => $dir, 'PHP_INI_SCAN_DIR' => ':' . $this->ini, 'PGPASSFILE' => '/nonexistent'], array_filter($env, fn ($v) => $v !== null));
        foreach ($env as $key => $value) {
            if ($value === null) {
                unset($full[$key]);
            }
        }
        unset($full['PGPASSWORD'], $full['PGUSER']);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $full);
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        rrmdir($dir);

        return [$code, (string)$out];
    }

    /**
     * @param array<string,?string> $env
     * @return array{status:int,body:string,log:string}
     */
    private function healthz(array $env): array
    {
        $dir = tmpdir('lk-http');
        $server = new Server($dir, $env, 'ci4', dirname(__DIR__, 2) . '/public/router.php');
        try {
            $response = $server->client()->get('/healthz');

            return ['status' => $response['status'], 'body' => $response['body'], 'log' => is_file($server->log) ? (string)file_get_contents($server->log) : ''];
        } finally {
            $server->stop();
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /** @return array<string,int> */
    private function ci4Logs(): array
    {
        clearstatcache();
        $sizes = [];
        foreach (glob(dirname(__DIR__, 2) . '/writable/logs/*.log') ?: [] as $file) {
            $sizes[$file] = (int)filesize($file);
        }

        return $sizes;
    }

    /** @param array<string,int> $before */
    private function ci4LogsSince(array $before): string
    {
        $out = '';
        foreach ($this->ci4Logs() as $file => $size) {
            $out .= (string)file_get_contents($file, false, null, $before[$file] ?? 0);
        }

        return $out;
    }
}
