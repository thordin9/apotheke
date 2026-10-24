<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'cfg') . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function write(array $c): string
    {
        file_put_contents($this->file, '<?php return ' . var_export($c, true) . ';');
        return $this->file;
    }

    public function testDefaultsWithoutFileOrEnv(): void
    {
        $c = config_load('/app', null, []);
        $this->assertSame('/app/data', $c['data_dir']);
        $this->assertSame('sqlite', $c['db']['driver']);
        $this->assertSame('/app/data/catalog.sqlite', $c['db']['sqlite_path']);
        $this->assertSame(20, $c['uploads']['max_mb']);
        $this->assertSame(60, $c['page_size']);
        $this->assertTrue($c['seed_defaults']);
        $this->assertSame('', $c['app_password']);
        $this->assertSame('EUR', $c['currency']);
        $this->assertSame(30, $c['expiry_soon_days']);
    }

    public function testExpirySoonDaysFromEnvIsClamped(): void
    {
        $this->assertSame(7, config_load('/app', null, ['EXPIRY_SOON_DAYS' => '7'])['expiry_soon_days']);
        $this->assertSame(1, config_load('/app', null, ['EXPIRY_SOON_DAYS' => '-3'])['expiry_soon_days']);
        $this->assertSame(3650, config_load('/app', null, ['EXPIRY_SOON_DAYS' => '99999'])['expiry_soon_days']);
    }

    public function testAllowedHostsFromEnv(): void
    {
        $this->assertSame('', config_load('/app', null, [])['allowed_hosts']);
        $this->assertSame('apotheke.lan, nas', config_load('/app', null, ['ALLOWED_HOSTS' => ' apotheke.lan, nas '])['allowed_hosts']);
    }

    public function testCurrencyFromEnv(): void
    {
        $this->assertSame('CHF', config_load('/app', null, ['CURRENCY' => ' CHF '])['currency']);
    }

    public function testMissingFileIsIgnored(): void
    {
        $this->assertSame(config_load('/app', null, []), config_load('/app', '/does/not/exist.php', []));
    }

    public function testFileOverridesDefaultsAndKeepsOtherKeys(): void
    {
        $c = config_load('/app', $this->write(['db' => ['driver' => 'pgsql', 'host' => 'db', 'password' => 'pw'], 'timezone' => 'Europe/Athens']), []);
        $this->assertSame('pgsql', $c['db']['driver']);
        $this->assertSame('db', $c['db']['host']);
        $this->assertSame(5432, $c['db']['port'], 'untouched nested defaults survive');
        $this->assertSame('Europe/Athens', $c['timezone']);
        $this->assertSame('pgsql:host=db;port=5432;dbname=apotheke', config_dsn($c['db']));
    }

    public function testEnvOverridesFile(): void
    {
        $c = config_load('/app', $this->write(['app_password' => 'fromfile', 'db' => ['port' => 1111]]), [
            'APP_PASSWORD' => 'fromenv-pw', 'DB_PORT' => '6543', 'MAX_UPLOAD_MB' => '5', 'SEED_DEFAULTS' => 'false', 'DATA_DIR' => '/srv/data/',
        ]);
        $this->assertSame('fromenv-pw', $c['app_password']);
        $this->assertSame(6543, $c['db']['port']);
        $this->assertSame(5, $c['uploads']['max_mb']);
        $this->assertFalse($c['seed_defaults']);
        $this->assertSame('/srv/data', $c['data_dir'], 'trailing slash trimmed');
        $this->assertSame('/srv/data/catalog.sqlite', $c['db']['sqlite_path'], 'sqlite path follows data_dir');
    }

    public function testEmptyEnvDoesNotWipeFileValues(): void
    {
        $c = config_load('/app', $this->write(['app_password' => 's3cret-pw']), ['APP_PASSWORD' => '', 'DB_DRIVER' => '']);
        $this->assertSame('s3cret-pw', $c['app_password']);
        $this->assertSame('sqlite', $c['db']['driver']);
    }

    public function testExplicitDsnWins(): void
    {
        $c = config_load('/app', null, ['DB_DRIVER' => 'PGSQL', 'DB_DSN' => 'pgsql:host=x;dbname=y']);
        $this->assertSame('pgsql', $c['db']['driver'], 'driver normalised to lowercase');
        $this->assertSame('pgsql:host=x;dbname=y', config_dsn($c['db']));
    }

    public function testInvalidDriverRejected(): void
    {
        $this->expectException(RuntimeException::class);
        config_load('/app', null, ['DB_DRIVER' => 'mysql']);
    }

    public function testFileMustReturnArray(): void
    {
        file_put_contents($this->file, '<?php return 42;');
        $this->expectException(RuntimeException::class);
        config_load('/app', $this->file, []);
    }

    public function testTrustedProxiesFromEnvAndFile(): void
    {
        $this->assertSame([], config_load('/app', null, [])['trusted_proxies']);
        $this->assertSame(['192.0.2.1', '192.0.2.0/24'], config_load('/app', null, ['TRUSTED_PROXIES' => '192.0.2.1, 192.0.2.0/24 , not-an-ip'])['trusted_proxies']);
        $this->assertSame(['198.51.100.1'], config_load('/app', $this->write(['trusted_proxies' => ['198.51.100.1']]), ['TRUSTED_PROXIES' => ''])['trusted_proxies']);
        $this->assertSame(['203.0.113.5'], config_load('/app', $this->write(['trusted_proxies' => ['198.51.100.1']]), ['TRUSTED_PROXIES' => '203.0.113.5'])['trusted_proxies']);
        $this->assertTrue(config_ip_matches('192.0.2.15', '192.0.2.0/24'));
        $this->assertFalse(config_ip_matches('192.0.3.15', '192.0.2.0/24'));
        $this->assertTrue(config_ip_matches('192.0.2.1', '192.0.2.1'));
        $this->assertFalse(config_ip_matches('192.0.2.2', '192.0.2.1'));
    }

    public function testExampleConfigLoads(): void
    {
        $c = config_load('/app', dirname(__DIR__, 2) . '/config.example.php', []);
        $this->assertSame('sqlite', $c['db']['driver']);
        $this->assertSame(array_keys(config_defaults('/x')), array_keys($c), 'example documents every top-level key');
    }
}
