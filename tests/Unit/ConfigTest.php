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
            'APP_PASSWORD' => 'fromenv', 'DB_PORT' => '6543', 'MAX_UPLOAD_MB' => '5', 'SEED_DEFAULTS' => 'false', 'DATA_DIR' => '/srv/data/',
        ]);
        $this->assertSame('fromenv', $c['app_password']);
        $this->assertSame(6543, $c['db']['port']);
        $this->assertSame(5, $c['uploads']['max_mb']);
        $this->assertFalse($c['seed_defaults']);
        $this->assertSame('/srv/data', $c['data_dir'], 'trailing slash trimmed');
        $this->assertSame('/srv/data/catalog.sqlite', $c['db']['sqlite_path'], 'sqlite path follows data_dir');
    }

    public function testEmptyEnvDoesNotWipeFileValues(): void
    {
        $c = config_load('/app', $this->write(['app_password' => 'secret']), ['APP_PASSWORD' => '', 'DB_DRIVER' => '']);
        $this->assertSame('secret', $c['app_password']);
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

    public function testExampleConfigLoads(): void
    {
        $c = config_load('/app', dirname(__DIR__, 2) . '/config.example.php', []);
        $this->assertSame('sqlite', $c['db']['driver']);
        $this->assertSame(array_keys(config_defaults('/x')), array_keys($c), 'example documents every top-level key');
    }
}
