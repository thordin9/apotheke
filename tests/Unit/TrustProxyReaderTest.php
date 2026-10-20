<?php

declare(strict_types=1);

use Apotheke\Core\Http\TrustProxyReader;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Config/DsnTranslator.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Http/TrustProxyReader.php';

final class TrustProxyReaderTest extends TestCase
{
    public function testMissingFileAndMissingTableAreNotTrusted(): void
    {
        $this->assertFalse((new TrustProxyReader(['DBDriver' => 'SQLite3', 'database' => '/no/such.sqlite']))->trusted());
        $path = sys_get_temp_dir() . '/apo-trust-' . bin2hex(random_bytes(3)) . '.sqlite';
        $db = new SQLite3($path);
        $db->close();
        $this->assertFalse((new TrustProxyReader(['DBDriver' => 'SQLite3', 'database' => $path]))->trusted());
        @unlink($path);
    }

    public function testJsonTrueIsTrusted(): void
    {
        $path = sys_get_temp_dir() . '/apo-trust-' . bin2hex(random_bytes(3)) . '.sqlite';
        $db = new SQLite3($path);
        $db->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $db->exec("INSERT INTO settings (name, value, updated_at) VALUES ('trust_proxy', 'true', '2026-01-01')");
        $db->close();
        $this->assertTrue((new TrustProxyReader(['DBDriver' => 'SQLite3', 'database' => $path]))->trusted());
        @unlink($path);
    }

    public function testPostgresReaderLeavesTheOpenConnectionUsable(): void
    {
        if (!function_exists('pg_connect') || test_driver() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL leg only');
        }
        $env = test_db_env(sys_get_temp_dir());
        $schema = 't_reader_' . bin2hex(random_bytes(4));
        $pdo = new PDO(
            "pgsql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']}",
            $env['DB_USER'],
            $env['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo->exec('CREATE TABLE "' . $schema . '".settings (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO \"$schema\".settings (name, value, updated_at) VALUES ('trust_proxy', 'true', '2026-01-01')");
        $shared = false;
        try {
            $group = (new Apotheke\Core\Config\DsnTranslator())->translate([
                'driver' => 'pgsql',
                'host' => $env['DB_HOST'],
                'port' => (int)$env['DB_PORT'],
                'name' => $env['DB_NAME'],
                'user' => $env['DB_USER'],
                'password' => $env['DB_PASSWORD'],
                'schema' => $schema,
            ]);
            $shared = pg_connect((string)$group['DSN']);
            $this->assertNotFalse($shared);
            $this->assertNotFalse(pg_query($shared, 'SET search_path TO public'));
            $before = pg_fetch_result(pg_query($shared, 'SHOW search_path'), 0, 0);
            $this->assertTrue((new TrustProxyReader($group))->trusted());
            try {
                $status = pg_connection_status($shared);
                $after = pg_fetch_result(pg_query($shared, 'SHOW search_path'), 0, 0);
                $alive = pg_query($shared, 'SELECT 1');
            } catch (Throwable $e) {
                $this->fail('the open connection was closed by TrustProxyReader: ' . $e->getMessage());
            }
            $this->assertSame(PGSQL_CONNECTION_OK, $status);
            $this->assertSame($before, $after);
            $this->assertNotFalse($alive);
        } finally {
            if ($shared !== false) {
                try {
                    pg_close($shared);
                } catch (Throwable) {
                }
            }
            $pdo->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        }
    }
}
