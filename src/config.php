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
    'ORIGINALS_DIR'   => 'originals_dir',
    'TRUSTED_PROXIES' => 'trusted_proxies',
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
            'schema'      => '',            // pgsql: optional search_path; CREATE SCHEMA only when missing
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
        'originals_dir' => '',           // camera uploads; empty = <data_dir>/originals (outside public/)
        'trusted_proxies' => [],         // addresses allowed to set X-Forwarded-For when trust_proxy is on
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
    $cfg['originals_dir'] = rtrim((string)($cfg['originals_dir'] ?? ''), '/');
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
    $cfg['trusted_proxies'] = config_proxy_list($cfg['trusted_proxies'] ?? []);
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

/**
 * What bin/migrate.php may print. SQLite is the driver plus the file path.
 * Anything else is host, port, dbname, and DB_SCHEMA. A DSN that does not parse
 * is `<unparsed dsn>` and nothing more. User and password are never included.
 */
function config_migrate_where(array $db): string
{
    if ($db['driver'] === 'sqlite') return (string)$db['sqlite_path'];
    $fields = config_dsn_public_fields(config_dsn($db));
    if ($fields === null) return '<unparsed dsn>';
    $parts = [];
    foreach (['host', 'port', 'dbname'] as $key) {
        if (($fields[$key] ?? '') !== '') $parts[] = $key . '=' . $fields[$key];
    }
    $where = implode(' ', $parts);
    if ((string)$db['schema'] !== '') $where .= ($where !== '' ? ' ' : '') . '(schema ' . $db['schema'] . ')';
    return $where;
}

/**
 * host, port, and dbname from a DSN. Null when the string does not parse.
 * Keyword values follow libpq: spaces may surround "=", single quotes may hold
 * spaces and \', \\, and an unquoted backslash escapes the next character
 * (including a space). The last duplicate of an allowlisted key wins.
 * @return array{host?:string,port?:string,dbname?:string}|null
 */
function config_dsn_public_fields(string $dsn): ?array
{
    if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $dsn) === 1) {
        $url = parse_url($dsn);
        if (!is_array($url)) return null;
        $fields = [];
        if (isset($url['host']) && $url['host'] !== '') $fields['host'] = $url['host'];
        if (isset($url['port'])) $fields['port'] = (string)$url['port'];
        $path = (string)($url['path'] ?? '');
        if ($path !== '' && $path !== '/') $fields['dbname'] = ltrim($path, '/');
        return $fields === [] ? null : $fields;
    }
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*:(.*)$/s', $dsn, $parts) !== 1) return null;
    $tokens = config_dsn_keywords($parts[1]);
    if ($tokens === null) return null;
    $fields = [];
    foreach (['host', 'port', 'dbname'] as $key) {
        if (isset($tokens[$key])) $fields[$key] = $tokens[$key];
    }
    return $fields;
}

/** @return array<string,string>|null keyword → decoded value, or null if tokenising fails */
function config_dsn_keywords(string $rest): ?array
{
    $len = strlen($rest);
    $i = 0;
    $out = [];
    while ($i < $len) {
        while ($i < $len && config_dsn_is_sep($rest[$i])) $i++;
        if ($i >= $len) break;
        $start = $i;
        while ($i < $len && $rest[$i] !== '=' && !config_dsn_is_sep($rest[$i])) $i++;
        $key = substr($rest, $start, $i - $start);
        if ($key === '') return null;
        while ($i < $len && ctype_space($rest[$i])) $i++;
        if ($i >= $len || $rest[$i] !== '=') return null;
        $i++;
        while ($i < $len && ctype_space($rest[$i])) $i++;
        if ($i < $len && $rest[$i] === "'") {
            $i++;
            $value = '';
            $closed = false;
            while ($i < $len) {
                if ($rest[$i] === '\\') {
                    if ($i + 1 >= $len) return null;
                    $value .= $rest[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($rest[$i] === "'") {
                    $i++;
                    $closed = true;
                    break;
                }
                $value .= $rest[$i];
                $i++;
            }
            if (!$closed) return null;
        } else {
            $value = '';
            while ($i < $len && !config_dsn_is_sep($rest[$i])) {
                if ($rest[$i] === '\\') {
                    if ($i + 1 >= $len) return null;
                    $value .= $rest[$i + 1];
                    $i += 2;
                    continue;
                }
                $value .= $rest[$i];
                $i++;
            }
        }
        $out[strtolower($key)] = $value;
    }
    return $out;
}

function config_dsn_is_sep(string $ch): bool
{
    return $ch === ';' || ctype_space($ch);
}

/** @return list<string> IP addresses and CIDR blocks. Anything else is dropped. */
function config_proxy_list(mixed $raw): array
{
    if (is_string($raw)) $raw = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $item) {
        if (!is_string($item) && !is_int($item)) continue;
        $item = trim((string)$item);
        if ($item === '' || !config_proxy_rule_ok($item)) continue;
        $out[] = $item;
    }
    return array_values(array_unique($out));
}

function config_proxy_rule_ok(string $rule): bool
{
    if (!str_contains($rule, '/')) return config_ip_normalize($rule) !== null;
    [$net, $bits] = explode('/', $rule, 2);
    if ($bits === '' || !ctype_digit($bits)) return false;
    $ip = config_ip_normalize($net);
    if ($ip === null) return false;
    return (int)$bits <= (str_contains($ip, ':') ? 128 : 32);
}

/** Canonical IPv4 or IPv6 text, or null when $ip is not an address. */
function config_ip_normalize(string $ip): ?string
{
    $ip = trim($ip);
    if ($ip !== '' && $ip[0] === '[' && str_ends_with($ip, ']')) $ip = substr($ip, 1, -1);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $ip;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) return null;
    $packed = inet_pton($ip);
    return $packed === false ? null : inet_ntop($packed);
}

function config_ip_matches(string $ip, string $rule): bool
{
    $ip = config_ip_normalize($ip);
    if ($ip === null) return false;
    if (!str_contains($rule, '/')) {
        $ruleIp = config_ip_normalize($rule);
        return $ruleIp !== null && $ip === $ruleIp;
    }
    [$net, $bits] = explode('/', $rule, 2);
    if ($bits === '' || !ctype_digit($bits)) return false;
    $netIp = config_ip_normalize($net);
    if ($netIp === null) return false;
    $ipBin = inet_pton($ip);
    $netBin = inet_pton($netIp);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $bitsN = (int)$bits;
    $max = strlen($ipBin) * 8;
    if ($bitsN > $max) return false;
    $bytes = intdiv($bitsN, 8);
    $rest = $bitsN % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    if ($rest === 0) return true;
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

function config_proxy_allows(string $ip, array $proxies): bool
{
    foreach ($proxies as $rule) if (is_string($rule) && config_ip_matches($ip, $rule)) return true;
    return false;
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
