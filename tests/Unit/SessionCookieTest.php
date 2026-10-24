<?php
declare(strict_types=1);

/** HTTPS detection for the Secure cookie flag and the configurable session lifetime. */
final class SessionCookieTest extends DatabaseTestCase
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

    private function set(string $k, string $v): void
    {
        $pdo = db();
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$k]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)')->execute([$k, $v, gmdate('Y-m-d H:i:s')]);
        settings_cache(null, true);
    }

    public function testDirectHttps(): void
    {
        $this->assertFalse(request_is_https());
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(request_is_https());
        $_SERVER['HTTPS'] = 'off';
        $this->assertFalse(request_is_https());
    }

    public function testForwardedProtoNeedsTrustedProxy(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertFalse(request_is_https(), 'spoofable header ignored by default');
        $this->set('trust_proxy', '1');
        $this->assertTrue(request_is_https());
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'HTTPS, http';
        $this->assertTrue(request_is_https(), 'first hop counts, case-insensitive');
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $this->assertFalse(request_is_https());
    }

    public function testHttpsPublicUrlMarksCookieSecureOnItsHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'inv.example.org';
        $this->assertFalse(session_cookie_secure(), 'no PUBLIC_URL: plain HTTP stays plain');
        $this->set('public_url', json_encode('http://inv.example.org'));
        $this->assertFalse(session_cookie_secure(), 'http:// PUBLIC_URL');
        $this->set('public_url', json_encode('https://inv.example.org'));
        $this->assertTrue(session_cookie_secure(), 'https:// PUBLIC_URL and its host: Secure without trust_proxy');
        $_SERVER['HTTP_HOST'] = 'INV.example.org:443';
        $this->assertTrue(session_cookie_secure(), 'case and port do not matter');
        $_SERVER['HTTP_HOST'] = '192.168.1.10:9091';
        $this->assertFalse(session_cookie_secure(), 'opened directly by address over HTTP: sign-in keeps working');
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(session_cookie_secure(), 'direct HTTPS is always Secure');
    }

    public function testLifetime(): void
    {
        $this->assertSame(30 * 86400, session_lifetime());
        $this->set('session_days', '90');
        $this->assertSame(90 * 86400, session_lifetime());
        $this->set('session_days', '9999');
        $this->assertLessThanOrEqual(365 * 86400, session_lifetime(), 'out-of-range values never exceed a year');
    }
}
