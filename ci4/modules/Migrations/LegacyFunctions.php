<?php

declare(strict_types=1);

/**
 * Globals the 25 migration files and the runner call. Kept after cutover.
 * Migration-only: the web side and the CLI use the Settings module, never
 * these functions or their statics.
 * settings_schema(), setting_default() and setting_validate() are thin
 * delegates to Apotheke\Settings\SettingsSchema, the single source.
 * Each function is defined only when the legacy app has not already defined it.
 */

require_once dirname(__DIR__) . '/Core/Exceptions/UserError.php';
require_once dirname(__DIR__) . '/Core/Libraries/Text.php';
require_once dirname(__DIR__) . '/Settings/SettingsSchema.php';
require_once dirname(__DIR__) . '/Auth/Accounts.php';

if (!class_exists('UserError', false)) {
    class UserError extends RuntimeException
    {
    }
}

if (!defined('LEGACY_SCHEMA_VERSION')) {
    define('LEGACY_SCHEMA_VERSION', 3);
}
if (!defined('ACCENT_DEFAULT')) {
    define('ACCENT_DEFAULT', Apotheke\Settings\SettingsSchema::ACCENT_DEFAULT);
}
if (!defined('AUTOFILL_SIDE_MIN')) {
    define('AUTOFILL_SIDE_MIN', Apotheke\Settings\SettingsSchema::AUTOFILL_SIDE_MIN);
}
if (!defined('AUTOFILL_IMAGE_BYTES_MIN')) {
    define('AUTOFILL_IMAGE_BYTES_MIN', Apotheke\Settings\SettingsSchema::AUTOFILL_IMAGE_BYTES_MIN);
}
if (!defined('AUTOFILL_IMAGE_BYTES_MAX')) {
    define('AUTOFILL_IMAGE_BYTES_MAX', Apotheke\Settings\SettingsSchema::AUTOFILL_IMAGE_BYTES_MAX);
}
if (!defined('SORTS')) {
    define('SORTS', Apotheke\Settings\SettingsSchema::SORTS);
}
if (!defined('ATTACH_TYPE_SETS')) {
    define('ATTACH_TYPE_SETS', Apotheke\Settings\SettingsSchema::ATTACH_TYPE_SETS);
}
if (!defined('MONEY_FORMATS')) {
    define('MONEY_FORMATS', Apotheke\Settings\SettingsSchema::MONEY_FORMATS);
}
if (!defined('DATE_FORMATS')) {
    define('DATE_FORMATS', Apotheke\Settings\SettingsSchema::DATE_FORMATS);
}
if (!defined('TIME_FORMATS')) {
    define('TIME_FORMATS', Apotheke\Settings\SettingsSchema::TIME_FORMATS);
}
if (!defined('ROLE_DEFAULTS')) {
    define('ROLE_DEFAULTS', [
        'admin' => ['Admin', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels', 'settings', 'users', 'backup']],
        'editor' => ['Editor', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels']],
        'viewer' => ['Viewer', ['view', 'export', 'labels']],
        'guest' => ['Guest', ['view']],
    ]);
}
if (!defined('SEED_LOCATIONS')) {
    define('SEED_LOCATIONS', ['Box 1', 'Living room', 'Kitchen', 'Garage', 'Basement', 'Attic']);
}
if (!defined('SEED_CATEGORIES')) {
    define('SEED_CATEGORIES', ['General', 'Tools', 'Electronics', 'Kitchen', 'Documents', 'Hobby', 'Clothing', 'Shoes', 'Books', 'Lighting']);
}
if (!defined('SEED_DEFAULT_LOCATION')) {
    define('SEED_DEFAULT_LOCATION', 'Box 1');
}
if (!defined('SEED_DEFAULT_CATEGORY')) {
    define('SEED_DEFAULT_CATEGORY', 'General');
}

function apotheke_migration_cfg(array $cfg): void
{
    $GLOBALS['apotheke_migration_cfg'] = $cfg;
}

if (!function_exists('cfg')) {
    function cfg(): array
    {
        $cfg = $GLOBALS['apotheke_migration_cfg'] ?? null;
        if (!is_array($cfg)) {
            throw new RuntimeException('Migration config is not set.');
        }

        return $cfg;
    }
}
if (!function_exists('app_time')) {
    function app_time(): int
    {
        static $fixed = null;
        if ($fixed === null) {
            $v = getenv('APOTHEKE_CLOCK');
            $fixed = is_string($v) && $v !== '' && ($t = strtotime($v . ' UTC')) !== false ? $t : 0;
        }

        return $fixed ?: time();
    }
}
if (!function_exists('now_utc')) {
    function now_utc(): string
    {
        return gmdate('Y-m-d H:i:s', app_time());
    }
}
if (!function_exists('fold')) {
    function fold(string $s): string
    {
        static $map = [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'ϊ' => 'ι', 'ΐ' => 'ι', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ς' => 'σ',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ];

        return strtr(mb_strtolower($s), $map);
    }
}
if (!function_exists('clean_line')) {
    function clean_line(string $s, int $max): string
    {
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));

        return mb_substr($s, 0, $max);
    }
}
if (!function_exists('natkey')) {
    function natkey(string $s): string
    {
        return mb_substr((string)preg_replace_callback('/\d+/', static fn($m) => str_pad(ltrim($m[0], '0') ?: '0', 12, '0', STR_PAD_LEFT), fold(trim($s))), 0, 400);
    }
}
if (!function_exists('tags_parse')) {
    function tags_parse(string|array $in): array
    {
        $parts = is_array($in) ? $in : preg_split('/[,;\n]+/u', $in);
        $out = [];
        foreach ($parts ?: [] as $p) {
            $p = ltrim(clean_line((string)$p, 40), '#');
            if ($p === '') {
                continue;
            }
            $out[fold($p)] ??= $p;
        }
        if (count($out) > 20) {
            throw new UserError('An item can have at most 20 tags.');
        }

        return array_values($out);
    }
}
if (!function_exists('db_insert')) {
    function db_insert(PDO $pdo, string $sql, array $args): int
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $st = $pdo->prepare($sql . ' RETURNING id');
            $st->execute($args);

            return (int)$st->fetchColumn();
        }
        $pdo->prepare($sql)->execute($args);

        return (int)$pdo->lastInsertId();
    }
}
if (!function_exists('settings_schema')) {
    function settings_schema(): array
    {
        return (new Apotheke\Settings\SettingsSchema())->definitions();
    }
}
if (!function_exists('setting_default')) {
    function setting_default(string $key): mixed
    {
        return (new Apotheke\Settings\SettingsSchema())->default($key, static fn (): array => cfg());
    }
}
if (!function_exists('setting_validate')) {
    function setting_validate(string $key, mixed $raw): mixed
    {
        try {
            return (new Apotheke\Settings\SettingsSchema())->validate($key, $raw);
        } catch (Apotheke\Core\Exceptions\UserError $e) {
            // Migrations catch the global UserError, like legacy.
            throw new UserError($e->getMessage(), $e->getCode());
        }
    }
}
if (!function_exists('settings_cache')) {
    /**
     * Kept for the migrations that clear it (0013 via settings_seed, 0024, 0025).
     * Nothing in a migration reads settings back, so there is no cache and no
     * static to go stale in a long-lived worker: clearing is a no-op.
     */
    function settings_cache(?array $set = null, bool $clear = false): ?array
    {
        return $clear ? null : $set;
    }
}
if (!function_exists('settings_seed')) {
    function settings_seed(PDO $pdo): void
    {
        $have = $pdo->query('SELECT name FROM settings')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('password_hash', $have, true)) {
            // the hash is seeded from APP_PASSWORD: refuse a short one, as legacy app_password_require() (hardening, 4a)
            $problem = Apotheke\Auth\Accounts::appPasswordProblem((string)(cfg()['app_password'] ?? ''));
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }
        }
        $ins = $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)');
        foreach (settings_schema() as $k => $_) {
            if (in_array($k, $have, true)) {
                continue;
            }
            $ins->execute([$k, json_encode(setting_default($k), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_utc()]);
        }
        settings_cache(null, true);
    }
}
if (!function_exists('setting_get')) {
    function setting_get(PDO $pdo, string $name): ?string
    {
        $st = $pdo->prepare('SELECT value FROM app_settings WHERE name = ?');
        $st->execute([$name]);
        $v = $st->fetchColumn();

        return $v === false ? null : (string)$v;
    }
}
if (!function_exists('setting_set')) {
    function setting_set(PDO $pdo, string $name, string $value): void
    {
        $pdo->prepare('DELETE FROM app_settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO app_settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
    }
}
if (!function_exists('seed_defaults')) {
    function seed_defaults(PDO $pdo, bool $force = false): int
    {
        if (!$force && setting_get($pdo, 'seeded_defaults') !== null) {
            return 0;
        }
        $n = 0;
        foreach (['locations' => [SEED_LOCATIONS, SEED_DEFAULT_LOCATION], 'categories' => [SEED_CATEGORIES, SEED_DEFAULT_CATEGORY]] as $table => [$names, $default]) {
            $find = $pdo->prepare("SELECT id FROM $table WHERE name_key = ?");
            foreach ($names as $name) {
                $find->execute([fold($name)]);
                if ($find->fetchColumn() !== false) {
                    continue;
                }
                db_insert($pdo, "INSERT INTO $table (name, name_key, sort_key, created_at) VALUES (?,?,?,?)", [$name, fold($name), natkey($name), now_utc()]);
                $n++;
            }
            if (!(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE is_default = 1")->fetchColumn()) {
                $pdo->prepare("UPDATE $table SET is_default = 1 WHERE name_key = ?")->execute([fold($default)]);
            }
        }
        setting_set($pdo, 'seeded_defaults', now_utc());

        return $n;
    }
}
if (!function_exists('legacy_migrate_to_v2')) {
    function legacy_migrate_to_v2(PDO $pdo, int $v): void
    {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS locations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL,
                name_key   TEXT    NOT NULL UNIQUE,   -- fold(name): case/accent-insensitive uniqueness
                is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
                created_at TEXT    NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_locations_one_default ON locations(is_default) WHERE is_default = 1;
            CREATE TABLE items_v2 (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL,
                location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL,
                quantity    INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0),
                notes       TEXT    NOT NULL DEFAULT '',
                image       TEXT,
                search      TEXT    NOT NULL DEFAULT '',  -- fold(name + notes)
                created_at  TEXT    NOT NULL,
                updated_at  TEXT    NOT NULL
            );
        SQL);
        if ($v === 1) {
            $ins = $pdo->prepare('INSERT OR IGNORE INTO locations (name, name_key, created_at) VALUES (?,?,?)');
            $ids = [];
            $rows = $pdo->query('SELECT id, name, location, quantity, notes, image, created_at, updated_at FROM items ORDER BY id')->fetchAll();
            foreach ($rows as $r) {
                $name = clean_line((string)$r['location'], 120);
                if ($name === '') {
                    continue;
                }
                $key = fold($name);
                if (!isset($ids[$key])) {
                    $ins->execute([$name, $key, $r['created_at']]);
                    $st = $pdo->prepare('SELECT id FROM locations WHERE name_key = ?');
                    $st->execute([$key]);
                    $ids[$key] = (int)$st->fetchColumn();
                    $st->closeCursor();
                }
            }
            $copy = $pdo->prepare('INSERT INTO items_v2 (id, name, location_id, quantity, notes, image, search, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $r) {
                $name = clean_line((string)$r['location'], 120);
                $copy->execute([$r['id'], $r['name'], $name === '' ? null : $ids[fold($name)], $r['quantity'], $r['notes'], $r['image'],
                    fold($r['name'] . "\n" . $r['notes']), $r['created_at'], $r['updated_at']]);
            }
            $pdo->exec('DROP TABLE items');
        } else {
            $pdo->exec('DROP TABLE IF EXISTS items');
        }
        $pdo->exec(<<<SQL
            ALTER TABLE items_v2 RENAME TO items;
            CREATE INDEX idx_items_location ON items(location_id);
            CREATE INDEX idx_items_name     ON items(name COLLATE NOCASE);
            CREATE INDEX idx_items_updated  ON items(updated_at);
        SQL);
    }
}
if (!function_exists('legacy_migrate_to_v3')) {
    function legacy_migrate_to_v3(PDO $pdo): void
    {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS categories (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL,
                name_key   TEXT    NOT NULL UNIQUE,   -- fold(name): case/accent-insensitive uniqueness
                is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
                created_at TEXT    NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_categories_one_default ON categories(is_default) WHERE is_default = 1;
            ALTER TABLE items ADD COLUMN category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL;
            CREATE INDEX idx_items_category ON items(category_id);
        SQL);
    }
}
