<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/modules/Core/Config/config-functions.php';

use Apotheke\Core\Config\ApothekeConfig;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/Core/Config/ApothekeConfig.php';

final class ApothekeConfigTest extends TestCase
{
    /** @var array<string,mixed> */
    private const FROM_MAIN = ['allowed_hosts' => '', 'trusted_proxies' => []];

    /** Keys only the CI4 tree has (API plan §2.2, §2.4); the legacy loader never gets them. */
    private const CI4_ONLY = ['cors_allowed_origins' => '', 'real_ip_header' => '', 'proxy_shared_secret' => ''];

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
        foreach (self::CI4_ONLY as $key => $default) {
            $this->assertSame($default, $ours[$key], $key);
            unset($ours[$key]);
        }
        // The chunked restore sizes (CI4 only).
        $this->assertSame(['restore_chunk_kb' => 8192, 'restore_max_mb' => 520], array_intersect_key($ours['uploads'], ['restore_chunk_kb' => 0, 'restore_max_mb' => 0]));
        unset($ours['uploads']['restore_chunk_kb'], $ours['uploads']['restore_max_mb']);
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

    /** RESTORE_CHUNK_KB is clamped to 64–16384 (under PHP's 20 MB upload limit), RESTORE_MAX_MB to 1–4096. */
    public function testTheChunkedRestoreSizesAreClamped(): void
    {
        foreach ([[[], 8192, 520], [['RESTORE_CHUNK_KB' => '1', 'RESTORE_MAX_MB' => '0'], 64, 1], [['RESTORE_CHUNK_KB' => '64', 'RESTORE_MAX_MB' => '1'], 64, 1],
            [['RESTORE_CHUNK_KB' => '16384', 'RESTORE_MAX_MB' => '4096'], 16384, 4096], [['RESTORE_CHUNK_KB' => '99999', 'RESTORE_MAX_MB' => '99999'], 16384, 4096]] as [$env, $chunk, $max]) {
            $u = ApothekeConfig::load('/repo', null, $env)->values()['uploads'];
            $this->assertSame([$chunk, $max], [$u['restore_chunk_kb'], $u['restore_max_mb']], (string)json_encode($env));
        }
    }

    /** SIGN_IN_FAIL_MS maps to sign_in_fail_ms, clamped to 400–10000 as on legacy ('abc' reads as 0, so 400). */
    public function testSignInFailMsMatchesTheLegacyLoader(): void
    {
        $this->assertSame('sign_in_fail_ms', ApothekeConfig::ENV_MAP['SIGN_IN_FAIL_MS']);
        foreach (['' => 1000, '1500' => 1500, '0' => 400, '99999' => 10000, 'abc' => 400] as $raw => $want) {
            $env = $raw === '' ? [] : ['SIGN_IN_FAIL_MS' => (string)$raw];
            $this->assertSame($want, config_load('/repo', null, $env)['sign_in_fail_ms'], 'legacy ' . $raw);
            $this->assertSame($want, ApothekeConfig::load('/repo', null, $env)->values()['sign_in_fail_ms'], 'ci4 ' . $raw);
        }
    }

    public function testTheApiEdgeSettings(): void
    {
        $none = ApothekeConfig::load('/repo', null, []);
        $this->assertSame([], $none->corsAllowedOrigins());
        $this->assertSame('', $none->realIpHeader());
        $this->assertSame('', $none->proxySharedSecret());
        $set = ApothekeConfig::load('/repo', null, [
            'CORS_ALLOWED_ORIGINS' => ' https://app.example.test, https://b.example.test ,,',
            'REAL_IP_HEADER' => ' CF-Connecting-IP ',
            'PROXY_SHARED_SECRET' => 'shared-1',
        ]);
        $this->assertSame(['https://app.example.test', 'https://b.example.test'], $set->corsAllowedOrigins());
        $this->assertSame('CF-Connecting-IP', $set->realIpHeader());
        $this->assertSame('shared-1', $set->proxySharedSecret());
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

    /** SEC-M2: main's config_proxy_list() keeps addresses and blocks only. */
    public function testTrustedProxiesKeepAddressesAndBlocks(): void
    {
        $root = sys_get_temp_dir() . '/apo-cfg-' . bin2hex(random_bytes(3));
        $config = ApothekeConfig::load($root, null, ['TRUSTED_PROXIES' => ' 127.0.0.1, 192.0.2.0/24 [::1] fd00::/8 not-an-ip 10.0.0.0/33 10.0.0.0/ 127.0.0.1']);
        $this->assertSame(['127.0.0.1', '192.0.2.0/24', '[::1]', 'fd00::/8'], $config->trustedProxies());
        $this->assertSame([], ApothekeConfig::load($root, null, [])->trustedProxies());
        $file = $root . '-proxies.php';
        file_put_contents($file, "<?php\nreturn ['trusted_proxies' => ['198.51.100.7', 5, 'x', '2001:db8::/32']];\n");
        try {
            $this->assertSame(['198.51.100.7', '2001:db8::/32'], ApothekeConfig::load($root, $file, [])->trustedProxies());
        } finally {
            unlink($file);
        }
    }
}
