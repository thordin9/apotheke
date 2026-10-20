<?php

declare(strict_types=1);

use Apotheke\Core\Http\TrustProxyReader;
use PHPUnit\Framework\TestCase;

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
}
