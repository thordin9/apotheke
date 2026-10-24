<?php

declare(strict_types=1);

namespace Apotheke\Core\Config;

use Apotheke\Core\Http\ProxyList;
use RuntimeException;

// Loaded before Composer's autoloader (Core/Boot/startup.php, root tests).
require_once dirname(__DIR__) . '/Http/ProxyList.php';

/**
 * defaults < config.php < environment, the same map and normalisation as src/config.php.
 */
final class ApothekeConfig
{
    /** @param array<string,mixed> $values */
    private function __construct(private array $values)
    {
    }

    /** @var array<string,string> */
    public const ENV_MAP = [
        'DATA_DIR' => 'data_dir',
        'TZ' => 'timezone',
        'APP_PASSWORD' => 'app_password',
        'DB_DRIVER' => 'db.driver',
        'DB_DSN' => 'db.dsn',
        'DB_HOST' => 'db.host',
        'DB_PORT' => 'db.port',
        'DB_NAME' => 'db.name',
        'DB_USER' => 'db.user',
        'DB_PASSWORD' => 'db.password',
        'DB_SCHEMA' => 'db.schema',
        'SQLITE_PATH' => 'db.sqlite_path',
        'MAX_UPLOAD_MB' => 'uploads.max_mb',
        'IMAGE_FULL_MAX' => 'uploads.full_max',
        'IMAGE_THUMB_MAX' => 'uploads.thumb_max',
        'PAGE_SIZE' => 'page_size',
        'PUBLIC_URL' => 'public_url',
        'ALLOWED_HOSTS' => 'allowed_hosts',
        'CURRENCY' => 'currency',
        'EXPIRY_SOON_DAYS' => 'expiry_soon_days',
        'SEED_DEFAULTS' => 'seed_defaults',
        'ORIGINALS_DIR' => 'originals_dir',
        'TRUSTED_PROXIES' => 'trusted_proxies',
    ];

    public static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @param array<string,string|false|null> $env */
    public static function load(string $root, ?string $file, array $env): self
    {
        $cfg = self::defaults($root);
        if ($file !== null && $file !== '' && is_file($file)) {
            $user = (static fn (string $f) => require $f)($file);
            if (!is_array($user)) {
                throw new RuntimeException("Config file $file must return an array.");
            }
            $cfg = array_replace_recursive($cfg, $user);
        }
        foreach (self::ENV_MAP as $var => $path) {
            $v = $env[$var] ?? false;
            if ($v === false || $v === '') {
                continue;
            }
            self::setPath($cfg, $path, (string)$v);
        }
        $cfg['data_dir'] = rtrim((string)$cfg['data_dir'], '/');
        $cfg['originals_dir'] = rtrim((string)($cfg['originals_dir'] ?? ''), '/');
        $cfg['db']['driver'] = strtolower((string)$cfg['db']['driver']);
        if (!in_array($cfg['db']['driver'], ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported db.driver "' . $cfg['db']['driver'] . '" (use sqlite or pgsql).');
        }
        if ((string)$cfg['db']['sqlite_path'] === '') {
            $cfg['db']['sqlite_path'] = $cfg['data_dir'] . '/catalog.sqlite';
        }
        $cfg['db']['port'] = (int)$cfg['db']['port'];
        foreach (['max_mb', 'full_max', 'thumb_max'] as $k) {
            $cfg['uploads'][$k] = max(1, (int)$cfg['uploads'][$k]);
        }
        $cfg['page_size'] = max(1, (int)$cfg['page_size']);
        $cfg['public_url'] = rtrim((string)$cfg['public_url'], '/');
        $cfg['allowed_hosts'] = trim((string)($cfg['allowed_hosts'] ?? ''));
        $cfg['currency'] = mb_substr(trim((string)$cfg['currency']), 0, 8) ?: 'EUR';
        $cfg['expiry_soon_days'] = min(3650, max(1, (int)$cfg['expiry_soon_days']));
        $cfg['seed_defaults'] = filter_var($cfg['seed_defaults'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $cfg['app_password'] = (string)$cfg['app_password'];
        $cfg['timezone'] = (string)$cfg['timezone'] ?: 'UTC';
        $cfg['trusted_proxies'] = (new ProxyList())->parse($cfg['trusted_proxies'] ?? []);

        return new self($cfg);
    }

    public static function fromEnvironment(): self
    {
        $root = self::repoRoot();
        $file = getenv('APOTHEKE_CONFIG');
        if ($file === false || $file === '') {
            $file = $root . '/config.php';
        }

        return self::load($root, $file, getenv());
    }

    /** @return array<string,mixed> */
    public function values(): array
    {
        return $this->values;
    }

    public function dataDir(): string
    {
        return (string)$this->values['data_dir'];
    }

    /** Empty stays under data/, a relative path is from the repository root, an absolute path is kept. */
    public function originalsDir(): string
    {
        $dir = trim((string)($this->values['originals_dir'] ?? ''));
        if ($dir === '') {
            return $this->dataDir() . '/originals';
        }
        if ($dir[0] !== '/') {
            $dir = self::repoRoot() . '/' . $dir;
        }

        return rtrim($dir, '/');
    }

    /**
     * TRUSTED_PROXIES: addresses and blocks allowed to set X-Forwarded-For when
     * trust_proxy is on (SEC-M2).
     *
     * @return list<string>
     */
    public function trustedProxies(): array
    {
        $list = $this->values['trusted_proxies'] ?? [];

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** @return array<string,mixed> */
    public function database(): array
    {
        $db = $this->values['db'];

        return is_array($db) ? $db : [];
    }

    public function publicUrl(): string
    {
        return (string)$this->values['public_url'];
    }

    /** ALLOWED_HOSTS: extra host names besides PUBLIC_URL's (SEC-M3). */
    public function allowedHosts(): string
    {
        return (string)$this->values['allowed_hosts'];
    }

    /** @return array<string,mixed> */
    private static function defaults(string $root): array
    {
        return [
            'data_dir' => $root . '/data',
            'timezone' => 'UTC',
            'app_password' => '',
            'db' => [
                'driver' => 'sqlite',
                'sqlite_path' => '',
                'dsn' => '',
                'host' => '127.0.0.1',
                'port' => 5432,
                'name' => 'apotheke',
                'user' => 'apotheke',
                'password' => '',
                'schema' => '',
            ],
            'uploads' => [
                'max_mb' => 20,
                'full_max' => 1600,
                'thumb_max' => 480,
            ],
            'page_size' => 60,
            'currency' => 'EUR',
            'expiry_soon_days' => 30,
            'public_url' => '',
            'allowed_hosts' => '',
            'seed_defaults' => true,
            'originals_dir' => '',
            'trusted_proxies' => [],
        ];
    }

    /** @param array<string,mixed> $cfg */
    private static function setPath(array &$cfg, string $path, mixed $value): void
    {
        $ref = &$cfg;
        foreach (explode('.', $path) as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) {
                $ref[$k] = $ref[$k] ?? null;
            }
            $ref = &$ref[$k];
        }
        $ref = $value;
    }
}
