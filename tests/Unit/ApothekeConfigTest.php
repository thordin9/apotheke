<?php

declare(strict_types=1);

use Apotheke\Core\Config\ApothekeConfig;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Config/ApothekeConfig.php';

final class ApothekeConfigTest extends TestCase
{
    /** @var array<string,mixed> */
    private const FROM_MAIN = ['allowed_hosts' => ''];

    public function testLoadMatchesTheLegacyLoader(): void
    {
        $root = sys_get_temp_dir() . '/apo-cfg-' . bin2hex(random_bytes(3));
        mkdir($root);
        $file = $root . '/config.php';
        file_put_contents($file, "<?php\nreturn ['currency' => 'CHF', 'db' => ['driver' => 'pgsql', 'host' => 'db.internal']];\n");
        $env = ['PAGE_SIZE' => '12', 'DB_PASSWORD' => 'pw', 'ORIGINALS_DIR' => 'camera'];
        $legacy = config_load($root, $file, $env);
        $ours = ApothekeConfig::load($root, $file, $env)->values();
        // Keys main added for SEC-M3/M2, which this branch's legacy tree gets with the next main sync.
        foreach (self::FROM_MAIN as $key => $default) {
            if (!array_key_exists($key, $legacy)) {
                $this->assertSame($default, $ours[$key], $key);
                unset($ours[$key]);
            }
        }
        $this->assertSame($legacy, $ours);
        $this->assertSame(dirname(__DIR__, 2) . '/camera', ApothekeConfig::load($root, $file, $env)->originalsDir());
    }

    public function testEmptyOriginalsStayUnderData(): void
    {
        $root = sys_get_temp_dir() . '/apo-cfg-' . bin2hex(random_bytes(3));
        $config = ApothekeConfig::load($root, null, ['DATA_DIR' => '/tmp/apo-data']);
        $this->assertSame('/tmp/apo-data/originals', $config->originalsDir());
        $this->assertSame('/tmp/apo-data/catalog.sqlite', $config->database()['sqlite_path']);
    }

    public function testAbsoluteOriginalsAreKept(): void
    {
        $config = ApothekeConfig::load('/repo', null, ['ORIGINALS_DIR' => '/mnt/photos']);
        $this->assertSame('/mnt/photos', $config->originalsDir());
    }

    public function testBadDriverMatchesTheLegacyMessage(): void
    {
        $legacy = null;
        $ours = null;
        try {
            config_load('/repo', null, ['DB_DRIVER' => 'mysql']);
        } catch (RuntimeException $e) {
            $legacy = $e->getMessage();
        }
        try {
            ApothekeConfig::load('/repo', null, ['DB_DRIVER' => 'mysql']);
        } catch (RuntimeException $e) {
            $ours = $e->getMessage();
        }
        $this->assertSame($legacy, $ours);
        $this->assertSame('Unsupported db.driver "mysql" (use sqlite or pgsql).', $ours);
    }

    public function testAllowedHostsComesFromTheEnvironmentTrimmed(): void
    {
        $root = sys_get_temp_dir() . '/apo-cfg-' . bin2hex(random_bytes(3));
        $config = ApothekeConfig::load($root, null, ['ALLOWED_HOSTS' => '  apotheke.lan, shelf.lan  ']);
        $this->assertSame('apotheke.lan, shelf.lan', $config->allowedHosts());
        $this->assertSame('', ApothekeConfig::load($root, null, [])->allowedHosts());
    }
}
