<?php
declare(strict_types=1);

/*
 * Apotheke – configuration.
 * Precedence (low → high): built-in defaults < config.php (or $APOTHEKE_CONFIG) < environment variables.
 * Empty environment variables are ignored, so `APP_PASSWORD: ${APP_PASSWORD:-}` in compose files never
 * wipes a value from config.php.
 */

/** Environment variable → config path. */
const CONFIG_ENV_MAP = [
    'DATA_DIR'        => 'data_dir',
    'TZ'              => 'timezone',
    'APP_PASSWORD'    => 'app_password',
    'DB_DRIVER'       => 'db.driver',
    'DB_DSN'          => 'db.dsn',
    'DB_HOST'         => 'db.host',
    'DB_PORT'         => 'db.port',
    'DB_NAME'         => 'db.name',
    'DB_USER'         => 'db.user',
    'DB_PASSWORD'     => 'db.password',
    'DB_SCHEMA'       => 'db.schema',
    'SQLITE_PATH'     => 'db.sqlite_path',
    'MAX_UPLOAD_MB'   => 'uploads.max_mb',
    'IMAGE_FULL_MAX'  => 'uploads.full_max',
    'IMAGE_THUMB_MAX' => 'uploads.thumb_max',
    'PAGE_SIZE'       => 'page_size',
    'PUBLIC_URL'      => 'public_url',
    'CURRENCY'        => 'currency',
    'EXPIRY_SOON_DAYS' => 'expiry_soon_days',
    'SEED_DEFAULTS'   => 'seed_defaults',
];

function config_defaults(string $root): array
{
    return [
        'data_dir'      => $root . '/data',
        'timezone'      => 'UTC',
        'app_password'  => '',
        'db' => [
            'driver'      => 'sqlite',      // sqlite | pgsql
            'sqlite_path' => '',            // default: <data_dir>/catalog.sqlite
            'dsn'         => '',            // full PDO DSN (overrides host/port/name for pgsql)
            'host'        => '127.0.0.1',
            'port'        => 5432,
            'name'        => 'apotheke',
            'user'        => 'apotheke',
            'password'    => '',
            'schema'      => '',            // pgsql: optional schema (search_path); created if missing
        ],
        'uploads' => [
            'max_mb'    => 20,
            'full_max'  => 1600,
            'thumb_max' => 480,
        ],
        'page_size'     => 60,
        'currency'      => 'EUR',            // shown next to item values (ISO code or symbol)
        'expiry_soon_days' => 30,          // "Expiring soon" = expires within this many days
        'public_url'    => '',             // base URL printed in QR labels; default: from the request
        'seed_defaults' => true,         // starter locations/categories on a brand-new database
    ];
}

/**
 * Load configuration. Pure function (no globals) so it can be unit-tested.
 * @param array<string,string|false> $env
 */
function config_load(string $root, ?string $file, array $env): array
{
    $cfg = config_defaults($root);
    if ($file !== null && $file !== '' && is_file($file)) {
        $user = (static fn(string $f) => require $f)($file);
        if (!is_array($user)) throw new RuntimeException("Config file $file must return an array.");
        $cfg = array_replace_recursive($cfg, $user);
    }
    foreach (CONFIG_ENV_MAP as $var => $path) {
        $v = $env[$var] ?? false;
        if ($v === false || $v === null || $v === '') continue;
        config_set($cfg, $path, (string)$v);
    }
    // normalise
    $cfg['data_dir'] = rtrim((string)$cfg['data_dir'], '/');
    $cfg['db']['driver'] = strtolower((string)$cfg['db']['driver']);
    if (!in_array($cfg['db']['driver'], ['sqlite', 'pgsql'], true)) {
        throw new RuntimeException('Unsupported db.driver "' . $cfg['db']['driver'] . '" (use sqlite or pgsql).');
    }
    if ((string)$cfg['db']['sqlite_path'] === '') $cfg['db']['sqlite_path'] = $cfg['data_dir'] . '/catalog.sqlite';
    $cfg['db']['port'] = (int)$cfg['db']['port'];
    foreach (['max_mb', 'full_max', 'thumb_max'] as $k) $cfg['uploads'][$k] = max(1, (int)$cfg['uploads'][$k]);
    $cfg['page_size'] = max(1, (int)$cfg['page_size']);
    $cfg['public_url'] = rtrim((string)$cfg['public_url'], '/');
    $cfg['currency'] = mb_substr(trim((string)$cfg['currency']), 0, 8) ?: 'EUR';
    $cfg['expiry_soon_days'] = min(3650, max(1, (int)$cfg['expiry_soon_days']));
    $cfg['seed_defaults'] = filter_var($cfg['seed_defaults'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    $cfg['app_password'] = (string)$cfg['app_password'];
    $cfg['timezone'] = (string)$cfg['timezone'] ?: 'UTC';
    return $cfg;
}

function config_set(array &$cfg, string $path, mixed $value): void
{
    $ref = &$cfg;
    foreach (explode('.', $path) as $k) {
        if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = $ref[$k] ?? null;
        $ref = &$ref[$k];
    }
    $ref = $value;
}

/** PDO DSN for the configured database. */
function config_dsn(array $db): string
{
    if ($db['driver'] === 'sqlite') return 'sqlite:' . $db['sqlite_path'];
    if ((string)$db['dsn'] !== '') return (string)$db['dsn'];
    return sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'], $db['port'], $db['name']);
}

/** The process-wide configuration (read once per request from getenv()). */
function cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $root = dirname(__DIR__);
        $file = getenv('APOTHEKE_CONFIG') ?: $root . '/config.php';
        $cfg = config_load($root, $file, getenv());
    }
    return $cfg;
}
