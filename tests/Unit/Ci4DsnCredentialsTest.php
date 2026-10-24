<?php

declare(strict_types=1);

use Apotheke\Migrations\PdoMigrationRunner;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Migrations/PdoMigrationRunner.php';

/**
 * DB_DSN on both connections /healthz opens (the migration runner's PDO and
 * CI4's pg_connect), booting the shipped router against PostgreSQL. Two
 * throwaway roles: "env" owns DB_SCHEMA, "dsn" has no rights on it, so a 200
 * means both connections logged in as the env role.
 */
final class Ci4DsnCredentialsTest extends TestCase
{
    private ?PDO $admin = null;
    /** @var array{env:array{user:string,password:string},dsn:array{user:string,password:string}} */
    private array $roles;
    private string $schema = '';

    protected function setUp(): void
    {
        try {
            $this->admin = test_pg_admin();
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
        $tag = bin2hex(random_bytes(4));
        $this->roles = [
            'env' => ['user' => 'apo_env_' . $tag, 'password' => 'envpw' . bin2hex(random_bytes(6))],
            'dsn' => ['user' => 'apo_dsn_' . $tag, 'password' => 'dsnpw' . bin2hex(random_bytes(6))],
        ];
        foreach ($this->roles as $role) {
            $this->admin->exec('CREATE ROLE ' . $role['user'] . " LOGIN PASSWORD '" . $role['password'] . "'");
        }
        $this->schema = 'd_' . $tag;
        $this->admin->exec('CREATE SCHEMA ' . $this->schema . ' AUTHORIZATION ' . $this->roles['env']['user']);
    }

    protected function tearDown(): void
    {
        if ($this->admin instanceof PDO) {
            $this->admin->exec('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            foreach ($this->roles as $role) {
                $this->admin->exec('DROP ROLE IF EXISTS ' . $role['user']);
            }
        }
    }

    /** QA #53 L-D: trailing whitespace and quotes are not part of a value. */
    public function testDsnValueShapesBootLikeLibpq(): void
    {
        $name = test_pg_params()['name'];
        foreach ([
            'trailing space before ;' => 'dbname=' . $name . ' ;connect_timeout=5',
            'trailing space at the end' => 'dbname = ' . $name . ' ',
            'quoted' => "dbname='" . $name . "'",
        ] as $label => $tail) {
            $hit = $this->boot($tail, '%eu', '%ep');
            $this->assertSame(200, $hit['status'], $label . ': ' . $hit['body'] . $hit['log']);
            $this->assertSame('ok', $hit['body'], $label);
        }
    }

    /**
     * Golden cases from legacy db_connect() on PHP 8.3.36 (the shipped image)
     * against PostgreSQL 17: DB_USER and DB_PASSWORD win over the DSN's user and
     * password, the default user and an empty password included. CI4 gives the
     * same result on PHP 8.3 and 8.4.
     */
    public function testCredentialsFollowLegacyOnPhp83(): void
    {
        $name = test_pg_params()['name'];
        $dsnCreds = 'dbname=' . $name . ';user=%du;password=%dp';
        $cases = [
            // DB_USER defaults to "apotheke" and DB_PASSWORD to empty; they still win.
            'a DSN credentials only' => [$dsnCreds, null, null, 500],
            'b DB_USER and DB_PASSWORD only' => ['dbname=' . $name, '%eu', '%ep', 200],
            'c both set, differing' => [$dsnCreds, '%eu', '%ep', 200],
            'd both set, empty DB_PASSWORD' => ['dbname=' . $name . ';user = %du;password = %dp', '%eu', '', 500],
        ];
        foreach ($cases as $label => [$tail, $user, $password, $status]) {
            $hit = $this->boot($tail, $user, $password);
            $this->assertSame($status, $hit['status'], $label . ': ' . $hit['body'] . $hit['log']);
            if ($status === 200) {
                $this->assertSame('ok', $hit['body'], $label);
            } else {
                $this->assertStringContainsString('Could not connect to the database', $hit['log'], $label);
            }
            foreach ($this->roles as $role) {
                $this->assertStringNotContainsString($role['password'], $hit['body'] . $hit['log'], $label);
            }
        }
    }

    /**
     * The same golden cases on the migration runner alone. It needs no CI4
     * vendor, so the legacy legs run it on PHP 8.3 as well as 8.4, and the
     * logged-in role is read back with current_user.
     */
    public function testRunnerFollowsLegacyOnPhp83OnEveryPhpVersion(): void
    {
        $pg = test_pg_params();
        $base = sprintf('pgsql:host=%s;port=%s;dbname=%s', $pg['host'], $pg['port'], $pg['name']);
        $env = $this->roles['env'];
        $dsn = $this->roles['dsn'];
        $withDsn = $base . ';user=' . $dsn['user'] . ';password=' . $dsn['password'];
        $cases = [
            'a DSN credentials only' => [$withDsn, 'apotheke', '', null],
            'b DB_USER and DB_PASSWORD only' => [$base, $env['user'], $env['password'], $env['user']],
            'c both set, differing' => [$withDsn, $env['user'], $env['password'], $env['user']],
            'd both set, empty DB_PASSWORD' => [$base . ';user = ' . $dsn['user'] . ';password = ' . $dsn['password'], $env['user'], '', null],
        ];
        $log = tempnam(sys_get_temp_dir(), 'ci4-cred-log-');
        $previous = ini_set('error_log', $log);
        $pgpass = getenv('PGPASSWORD');
        putenv('PGPASSWORD');
        putenv('PGPASSFILE=/nonexistent');
        try {
            $runner = new PdoMigrationRunner(dirname(__DIR__, 2) . '/migrations');
            foreach ($cases as $label => [$dsnString, $user, $password, $expected]) {
                $db = ['driver' => 'pgsql', 'dsn' => $dsnString, 'user' => $user, 'password' => $password, 'schema' => ''];
                try {
                    $pdo = $runner->connect($db);
                    $this->assertSame($expected, $pdo->query('SELECT current_user')->fetchColumn(), $label);
                    $runner->close($pdo);
                } catch (Apotheke\Migrations\MigrateConnectException $e) {
                    $this->assertNull($expected, $label . ' did not connect: ' . $e->getMessage());
                }
            }
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            putenv($pgpass === false ? 'PGPASSWORD' : 'PGPASSWORD=' . $pgpass);
            putenv('PGPASSFILE');
        }
        $logged = (string)file_get_contents($log);
        unlink($log);
        $this->assertSame(3, substr_count($logged, PdoMigrationRunner::CREDENTIALS_DIFFER), $logged);
        foreach ($this->roles as $role) {
            $this->assertStringNotContainsString($role['password'], $logged);
            $this->assertStringNotContainsString($role['user'], $logged);
        }
    }

    /**
     * The risk: on PHP 8.4, PDO drops the user and password passed to it when the
     * DSN mentions "user=" or "password=" anywhere, here inside application_name.
     * PHP 8.3 (the shipped image) still appends them.
     */
    public function testPdoOnPhp84DropsPassedCredentialsWhenTheDsnMentionsThem(): void
    {
        $pg = test_pg_params();
        $env = $this->roles['env'];
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s;application_name='a user=b password=c'", $pg['host'], $pg['port'], $pg['name']);
        $pgpass = getenv('PGPASSWORD');
        putenv('PGPASSWORD');
        putenv('PGPASSFILE=/nonexistent');
        try {
            $pdo = new PDO($dsn, $env['user'], $env['password']);
            $connected = (string)$pdo->query('SELECT current_user')->fetchColumn();
        } catch (PDOException) {
            $connected = null;
        } finally {
            putenv($pgpass === false ? 'PGPASSWORD' : 'PGPASSWORD=' . $pgpass);
            putenv('PGPASSFILE');
        }
        if (PHP_VERSION_ID >= 80400) {
            $this->assertNull($connected, 'PDO 8.4 used the passed credentials; the guard in forPdo() may be unnecessary now');
        } else {
            $this->assertSame($env['user'], $connected);
        }
    }

    /** CI4 does not depend on PDO adding the credentials: the runner and /healthz log in as DB_USER. */
    public function testValuesMentioningUserOrPasswordDoNotDropTheCredentials(): void
    {
        $pg = test_pg_params();
        $env = $this->roles['env'];
        $tails = [
            'application_name' => "application_name='a user=b password=c'",
            'options' => "options='-c search_path=user=x'",
            'sslpassword keyword' => 'sslpassword=placeholder',
        ];
        $runner = new PdoMigrationRunner(dirname(__DIR__, 2) . '/migrations');
        $pgpass = getenv('PGPASSWORD');
        putenv('PGPASSWORD');
        putenv('PGPASSFILE=/nonexistent');
        try {
            foreach ($tails as $label => $tail) {
                $db = [
                    'driver' => 'pgsql',
                    'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s;', $pg['host'], $pg['port'], $pg['name']) . $tail,
                    'user' => $env['user'],
                    'password' => $env['password'],
                    'schema' => '',
                ];
                $pdo = $runner->connect($db);
                $this->assertSame($env['user'], $pdo->query('SELECT current_user')->fetchColumn(), $label);
                $runner->close($pdo);
            }
        } finally {
            putenv($pgpass === false ? 'PGPASSWORD' : 'PGPASSWORD=' . $pgpass);
            putenv('PGPASSFILE');
        }
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            return;
        }
        foreach ($tails as $label => $tail) {
            $hit = $this->boot('dbname=' . $pg['name'] . ';' . $tail, '%eu', '%ep');
            $this->assertSame(200, $hit['status'], $label . ': ' . $hit['body'] . $hit['log']);
        }
    }

    /** One warning, with no user names or passwords, when DB_DSN's credentials differ from the ones used. */
    public function testDifferingCredentialsLogOneWarningWithoutValues(): void
    {
        $name = test_pg_params()['name'];
        $differ = $this->boot('dbname=' . $name . ';user=%du;password=%dp', '%eu', '%ep');
        $this->assertSame(200, $differ['status'], $differ['body'] . $differ['log']);
        $this->assertSame(1, substr_count($differ['log'], PdoMigrationRunner::CREDENTIALS_DIFFER), $differ['log']);
        foreach ($this->roles as $role) {
            $this->assertStringNotContainsString($role['password'], $differ['log']);
            $this->assertStringNotContainsString($role['user'], $differ['log']);
        }

        $same = $this->boot('dbname=' . $name . ';user=%eu;password=%ep', '%eu', '%ep');
        $this->assertSame(200, $same['status'], $same['body'] . $same['log']);
        $this->assertStringNotContainsString(PdoMigrationRunner::CREDENTIALS_DIFFER, $same['log']);
    }

    /**
     * One request to /healthz. In the DSN tail and the env values, %eu/%ep are
     * the env role's user and password and %du/%dp the dsn role's. Null unsets
     * DB_USER or DB_PASSWORD; libpq's own fallbacks (PGPASSWORD, ~/.pgpass) are off.
     *
     * @return array{status:int,body:string,log:string}
     */
    private function boot(string $tail, ?string $user, ?string $password): array
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $pg = test_pg_params();
        $fill = fn (?string $value): ?string => $value === null ? null : strtr($value, [
            '%eu' => $this->roles['env']['user'],
            '%ep' => $this->roles['env']['password'],
            '%du' => $this->roles['dsn']['user'],
            '%dp' => $this->roles['dsn']['password'],
        ]);
        $dir = tmpdir('ci4-dsn-boot');
        $server = new Server($dir, [
            'CI_ENVIRONMENT' => 'production',
            'APOTHEKE_TEST_DB' => null,
            'DB_DRIVER' => 'pgsql',
            'DB_DSN' => sprintf('pgsql:host=%s;port=%s;', $pg['host'], $pg['port']) . $fill($tail),
            'DB_HOST' => null,
            'DB_PORT' => null,
            'DB_NAME' => null,
            'DB_USER' => $fill($user),
            'DB_PASSWORD' => $fill($password),
            'DB_SCHEMA' => $this->schema,
            'PGPASSWORD' => null,
            'PGUSER' => null,
            'PGPASSFILE' => '/nonexistent',
        ], 'ci4', dirname(__DIR__, 2) . '/ci4/public/router.php');
        try {
            $response = $server->client()->get('/healthz');

            return [
                'status' => $response['status'],
                'body' => $response['body'],
                'log' => is_file($server->log) ? (string)file_get_contents($server->log) : '',
            ];
        } finally {
            $server->stop();
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }
}
