<?php
declare(strict_types=1);

/** public_url() never builds a label link from a Host that fails the allow-list (SEC-M3). */
final class PublicUrlTest extends DatabaseTestCase
{
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['SERVER_PORT'] = 80;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        settings_cache(null, true);
        parent::tearDown();
    }

    public function testListedHostIsUsedWithoutPublicUrl(): void
    {
        $_SERVER['HTTP_HOST'] = '192.168.1.10:9091';
        $this->assertSame('http://192.168.1.10:9091/item/1', public_url('/item/1'));
        $_SERVER['HTTP_HOST'] = '[FD00::10]:9091';
        $this->assertSame('http://[fd00::10]:9091/item/1', public_url('/item/1'));
    }

    public function testUnlistedHostFallsBackToLocalhost(): void
    {
        $_SERVER['HTTP_HOST'] = 'other.example';
        $this->assertSame('http://localhost/item/1', public_url('/item/1'));
        $_SERVER['HTTP_HOST'] = 'bad host"<x>';
        $this->assertSame('http://localhost/item/1', public_url('/item/1'));
        unset($_SERVER['HTTP_HOST']);
        $this->assertSame('http://localhost/item/1', public_url('/item/1'));
    }

    public function testPublicUrlWins(): void
    {
        $pdo = db();
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute(['public_url']);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)')->execute(['public_url', json_encode('https://inv.example.org'), gmdate('Y-m-d H:i:s')]);
        settings_cache(null, true);
        $_SERVER['HTTP_HOST'] = '192.168.1.10:9091';
        $this->assertSame('https://inv.example.org/item/1', public_url('/item/1'));
    }
}
