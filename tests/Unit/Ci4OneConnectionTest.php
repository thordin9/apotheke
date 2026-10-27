<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * One database login per request (QA #134 L-472). Legacy db() opens one PDO
 * connection and migrates on it; CI4 checks the schema on its own connection
 * and opens the migration runner's connection only when a migration is
 * missing. The count comes from PostgreSQL itself: pg_stat_database.sessions
 * of a throwaway database that only the app under test connects to.
 */
final class Ci4OneConnectionTest extends TestCase
{
    use SkipsOutsideCi;

    private const REQUESTS = ['/healthz', '/health', '/healthz', '/health', '/healthz'];

    private ?PDO $admin = null;
    private string $database = '';

    protected function setUp(): void
    {
        try {
            $this->admin = test_pg_admin();
        } catch (PDOException) {
            $this->skipOutsideCi('PostgreSQL is not reachable', 'start the PostgreSQL service.');
        }
        $this->database = 'apo_conn_' . bin2hex(random_bytes(4));
        $this->admin->exec('CREATE DATABASE ' . test_pg_ident($this->database));
    }

    protected function tearDown(): void
    {
        if ($this->admin instanceof PDO && $this->database !== '') {
            $this->admin->exec('DROP DATABASE IF EXISTS ' . test_pg_ident($this->database) . ' WITH (FORCE)');
        }
    }

    public function testEachRequestLogsInOnceOnLegacyAndCi4(): void
    {
        $counts = ['legacy' => $this->connectsPerRequest('legacy')];
        if (PHP_VERSION_ID >= 80400 && is_file(ROOT . '/ci4/vendor/autoload.php')) {
            $counts['ci4'] = $this->connectsPerRequest('ci4');
        }
        foreach ($counts as $app => [$first, $after]) {
            // The first request migrates the empty database: CI4's runner opens its own connection then.
            $this->assertSame($app === 'ci4' ? 2 : 1, $first, $app . ': first request (migrates)');
            $this->assertSame(count(self::REQUESTS), $after, $app . ': ' . count(self::REQUESTS) . ' requests on a current schema');
        }
        if (!isset($counts['ci4'])) {
            $this->markTestIncomplete('CI4 vendor and PHP 8.4 are required for the CI4 half');
        }
    }

    /** @return array{0:int,1:int} logins for the first request, then for self::REQUESTS together */
    private function connectsPerRequest(string $app): array
    {
        $pg = test_pg_params();
        $schema = $app . '_' . bin2hex(random_bytes(3));
        $dir = tmpdir('ci4-oneconn-' . $app);
        $server = new Server($dir, [
            'CI_ENVIRONMENT' => 'production',
            'APOTHEKE_TEST_DB' => null,
            'DB_DRIVER' => 'pgsql',
            'DB_DSN' => null,
            'DB_HOST' => $pg['host'],
            'DB_PORT' => $pg['port'],
            'DB_NAME' => $this->database,
            'DB_USER' => $pg['user'],
            'DB_PASSWORD' => $pg['password'],
            'DB_SCHEMA' => $schema,
        ], $app, $app === 'ci4' ? ROOT . '/ci4/public/router.php' : null);
        try {
            $start = $this->sessions(0);
            $got = $server->client()->get('/healthz');
            $this->assertSame(200, $got['status'], $app . ': ' . $got['body'] . (string)@file_get_contents($server->log));
            $migrated = $this->sessions($start + 1);
            foreach (self::REQUESTS as $path) {
                $got = $server->client()->get($path);
                $this->assertSame(200, $got['status'], $app . ' ' . $path);
            }
            $after = $this->sessions($migrated + count(self::REQUESTS));

            return [$migrated - $start, $after - $migrated];
        } finally {
            $server->stop();
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /**
     * Sessions started on the throwaway database so far. A backend reports its
     * session when it exits, a moment after the request ends, so this waits
     * until the count reaches $atLeast and then holds still for 300 ms.
     */
    private function sessions(int $atLeast): int
    {
        $read = function (): int {
            $this->admin->query('SELECT pg_stat_clear_snapshot()');
            $st = $this->admin->prepare('SELECT COALESCE(sessions, 0) FROM pg_stat_database WHERE datname = ?');
            $st->execute([$this->database]);

            return (int)$st->fetchColumn();
        };
        $deadline = microtime(true) + 10;
        $last = $read();
        $stableSince = microtime(true);
        while (microtime(true) < $deadline) {
            usleep(50000);
            $now = $read();
            if ($now !== $last) {
                $last = $now;
                $stableSince = microtime(true);
            } elseif ($now >= $atLeast && microtime(true) - $stableSince >= 0.3) {
                return $now;
            }
        }

        return $last;
    }
}
