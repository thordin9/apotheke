<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * A user and password in DB_DSN reach PostgreSQL the way PDO and libpq read
 * them, on both connections /healthz opens: the migration runner's PDO and
 * CI4's pg_connect. Each case boots the shipped router as a throwaway role.
 */
final class Ci4DsnCredentialsTest extends TestCase
{
    private ?PDO $admin = null;
    private string $role = '';
    private string $password = '';
    private string $schema = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        try {
            $this->admin = test_pg_admin();
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
        $this->role = 'apo_dsn_' . bin2hex(random_bytes(4));
        $this->password = 'dsnpw' . bin2hex(random_bytes(6));
        $this->schema = 'd_' . bin2hex(random_bytes(4));
        $this->admin->exec('CREATE ROLE ' . $this->role . " LOGIN PASSWORD '" . $this->password . "'");
        $this->admin->exec('CREATE SCHEMA ' . $this->schema . ' AUTHORIZATION ' . $this->role);
    }

    protected function tearDown(): void
    {
        if ($this->admin instanceof PDO) {
            $this->admin->exec('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->admin->exec('DROP ROLE IF EXISTS ' . $this->role);
        }
    }

    /** QA #53 L-D: trailing whitespace and quotes are not part of the password. */
    public function testDsnPasswordShapesBootLikeLibpq(): void
    {
        $this->boot([
            'trailing space before ;' => ['user=%u;password=%p ;connect_timeout=5', null],
            'trailing space at the end' => ['user=%u;password=%p ', null],
            'quoted' => ["user=%u;password='%p'", null],
        ]);
    }

    /**
     * Boots once per case. In the DSN tail and the DB_PASSWORD value, %u is the
     * role and %p its password. Every case must reach /healthz 200 without the
     * password showing in the body or the log.
     *
     * @param array<string,array{0:string,1:?string,2?:?string}> $cases name => [DSN tail, DB_PASSWORD (null: unset), DB_USER (null: unset)]
     */
    private function boot(array $cases): void
    {
        $pg = test_pg_params();
        $fill = fn (?string $value): ?string => $value === null ? null : str_replace(['%u', '%p'], [$this->role, $this->password], $value);
        foreach ($cases as $name => $case) {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;', $pg['host'], $pg['port'], $pg['name']) . $fill($case[0]);
            $dir = tmpdir('ci4-dsn-boot');
            $server = new Server($dir, [
                'CI_ENVIRONMENT' => 'production',
                'APOTHEKE_TEST_DB' => null,
                'DB_DRIVER' => 'pgsql',
                'DB_DSN' => $dsn,
                'DB_HOST' => null,
                'DB_PORT' => null,
                'DB_NAME' => null,
                'DB_USER' => $fill($case[2] ?? null),
                'DB_PASSWORD' => $fill($case[1]),
                'DB_SCHEMA' => $this->schema,
            ], 'ci4', dirname(__DIR__, 2) . '/ci4/public/router.php');
            try {
                $response = $server->client()->get('/healthz');
                $log = is_file($server->log) ? (string)file_get_contents($server->log) : '';
                $this->assertSame(200, $response['status'], $name . ': ' . $response['body'] . $log);
                $this->assertSame('ok', $response['body'], $name);
                $this->assertStringNotContainsString($this->password, $response['body'] . $log, $name);
            } finally {
                $server->stop();
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
    }
}
