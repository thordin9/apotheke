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

    public function testIdleDefaultIsSevenDaysAndDoesNotChangeTheAbsoluteLimit(): void
    {
        $this->assertSame(168, setting('session_idle_hours'));
        $this->assertSame(168 * 3600, session_idle_seconds());
        $this->assertSame(30 * 86400, session_lifetime());
        settings_store(['session_idle_hours' => 2, 'session_days' => 10]);
        settings_cache(null, true);
        $this->assertSame(2 * 3600, session_idle_seconds());
        $this->assertSame(10 * 86400, session_lifetime(), 'the absolute stay-signed-in limit is unchanged');
    }

    public function testIdleValidationIsAPositiveIntegerUpTo8760(): void
    {
        $this->assertSame(1, setting_validate('session_idle_hours', '1'));
        $this->assertSame(8760, setting_validate('session_idle_hours', 8760));
        $this->assertSame(168, setting_validate('session_idle_hours', '168'));
        foreach (['0', '-1', '8761', '1.5', '', '12hours'] as $bad) {
            try {
                setting_validate('session_idle_hours', $bad);
                $this->fail('accepted ' . var_export($bad, true));
            } catch (UserError $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testIdleExpiryAndActivityStamp(): void
    {
        $saved = $GLOBALS['_SESSION'] ?? [];
        try {
            $_SESSION = [];
            $this->assertFalse(session_idle_expired());
            session_touch();
            $this->assertArrayNotHasKey('last_activity', $_SESSION, 'an anonymous session has no activity stamp');

            $_SESSION = ['uid' => 1];
            $this->assertFalse(session_idle_expired(), 'a sign-in with no stamp yet is not idle');
            $now = app_time();
            session_touch();
            $this->assertGreaterThanOrEqual($now, $_SESSION['last_activity']);
            $this->assertLessThanOrEqual($now + 2, $_SESSION['last_activity']);

            $_SESSION['last_activity'] = app_time() - 30;
            $this->assertFalse(session_idle_expired());
            settings_store(['session_idle_hours' => 2]);
            settings_cache(null, true);
            $_SESSION['last_activity'] = app_time() - 3600;
            $this->assertFalse(session_idle_expired(), 'under the configured idle limit');
            $_SESSION['last_activity'] = app_time() - (2 * 3600) - 30;
            $this->assertTrue(session_idle_expired(), 'longer than the configured idle limit');
        } finally {
            $_SESSION = $saved;
            settings_cache(null, true);
        }
    }

    public function testMigration0027SeedsIdleHoursAndKeepsAnExplicitValue(): void
    {
        $run = require migrations_dir() . '/0027_session_idle_hours.php';
        $this->pdo->exec("DELETE FROM settings WHERE name = 'session_idle_hours'");
        settings_cache(null, true);
        $run($this->pdo, $this->driver);
        settings_cache(null, true);
        $this->assertSame(168, setting('session_idle_hours'));
        $this->assertSame('168', (string)$this->pdo->query("SELECT value FROM settings WHERE name = 'session_idle_hours'")->fetchColumn());
        settings_store(['session_idle_hours' => 24]);
        $run($this->pdo, $this->driver);
        settings_cache(null, true);
        $this->assertSame(24, setting('session_idle_hours'), 'a second run does not replace an explicit value');
        $this->assertArrayHasKey('0027', migrations_applied($this->pdo, $this->driver));
    }
}
