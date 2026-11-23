#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Deterministic schema dump of the PHP migration runner — the G0 oracle.
 *
 *   php v4/test/fixtures/php-schema-dump.php > v4/test/fixtures/php-v4.0.0-schema.json
 *
 * It migrates a throwaway SQLite database with the app's own PdoMigrationRunner — the same 35 files
 * under migrations/ — and writes every object in sqlite_master in creation order, with whitespace
 * normalised the way test/helpers/schema.ts normalises it, so two runs on the same PHP version
 * produce identical bytes.
 *
 * The JSON is used two ways by test/schema-parity.spec.ts:
 *   - objects[].sql is the comparison: v4 must contain every one of them unchanged, and may add only
 *     the allowlist (_write_log and its created_at index, D10);
 *   - replay (objects in order, skipping the FTS5 shadow tables, then schema_migrations rows) builds
 *     "a v3.72 database" so the v4 runner can be run on top of a PHP-produced schema.
 *
 * Runs with the repo root as cwd; PHP >= 8.4; no composer/vendor, no CI4 boot.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$root = dirname(__DIR__, 3);
$tmp = sys_get_temp_dir() . '/apotheke-dump-' . bin2hex(random_bytes(6));
@mkdir($tmp, 0o700, true);
$dbFile = $tmp . '/catalog.sqlite';

// Config the seeder needs: a password long enough that settings_seed() does not refuse.
putenv('DATA_DIR=' . $tmp);
putenv('SQLITE_PATH=' . $dbFile);
putenv('DB_DRIVER=sqlite');
putenv('APP_PASSWORD=correct horse battery staple 2026');
putenv('TZ=UTC');
putenv('APOTHEKE_CLOCK=2026-11-23T12:00:00Z');

require_once $root . '/modules/Core/Config/config-functions.php';
require_once $root . '/modules/Migrations/LegacyFunctions.php';
require_once $root . '/modules/Migrations/FreshInstallRoles.php';
require_once $root . '/modules/Migrations/PdoMigrationRunner.php';

$runner = new Apotheke\Migrations\PdoMigrationRunner($root . '/migrations');
$pdo = $runner->connect(['driver' => 'sqlite', 'sqlite_path' => $dbFile]);
$applied = $runner->migrate($pdo, 'sqlite', ['data_dir' => $tmp]);
fwrite(STDERR, 'applied ' . count($applied) . " migrations\n");

/**
 * Same rule as normaliseSql() in test/helpers/schema.ts, in the same order: `--` comments go first
 * (collapsing newlines before stripping them would swallow the rest of the statement), then
 * whitespace runs collapse and `(),` lose their padding. Without the comment step the comparison
 * would stop at the first inline comment, and several tables have one.
 */
function normalise(?string $sql): ?string
{
    if ($sql === null) {
        return null;
    }
    $sql = (string)preg_replace('/--[^\n]*/', '', $sql);
    $sql = (string)preg_replace('/\s+/', ' ', trim($sql));

    return (string)preg_replace('/\s*([(),])\s*/', '$1', $sql);
}

$objects = [];
// rowid order is creation order, which is what makes `replay` possible without a dependency sort.
foreach ($pdo->query("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY rowid") as $r) {
    $sql = $r['sql'];
    $objects[] = [
        'type' => $r['type'],
        'name' => $r['name'],
        'table' => $r['tbl_name'],
        'sql' => normalise($sql),
        // The raw text, for rebuilding "a v3.72 database": normalised SQL has no comments and is not
        // re-parseable as written.
        'replay_sql' => $r['sql'],
        // A virtual table's shadow tables are created by SQLite, not by the migration; replaying
        // their DDL by hand would collide with the virtual table. They are still compared.
        'replay' => $sql !== null && !str_starts_with(ltrim($sql), "CREATE TABLE '"),
    ];
}

$versions = [];
foreach ($pdo->query('SELECT version, name FROM schema_migrations ORDER BY version') as $r) {
    $versions[] = ['version' => $r['version'], 'name' => $r['name']];
}

/*
 * The rows a fresh PHP install ends with, for the seed-parity test (S0.3): `settings_seed()` at
 * 0013/0022/0023, 0018's adopt path (which turns settings.password_hash into the account "admin"
 * and clears the key), 0024–0027's row writes, and FreshInstallRoles::statements().
 *
 * The bcrypt digest is random per run, so `password_hash` in `users` is recorded as a marker; the
 * test verifies the real hash against `seed_config.app_password`. Everything else is compared
 * byte for byte, with the clock pinned by APOTHEKE_CLOCK above.
 */
$settings = [];
foreach ($pdo->query('SELECT name, value, updated_at FROM settings ORDER BY name') as $r) {
    $settings[] = ['name' => $r['name'], 'value' => $r['value'], 'updated_at' => $r['updated_at']];
}

$roles = [];
foreach ($pdo->query('SELECT role_key, name, perms, builtin, created_at FROM roles ORDER BY role_key') as $r) {
    $roles[] = [
        'role_key' => $r['role_key'],
        'name' => $r['name'],
        'perms' => $r['perms'],
        'builtin' => (int)$r['builtin'],
        'created_at' => $r['created_at'],
    ];
}

$users = [];
foreach ($pdo->query('SELECT username, username_key, role, created_at FROM users ORDER BY id') as $r) {
    $users[] = [
        'username' => $r['username'],
        'username_key' => $r['username_key'],
        'role' => $r['role'],
        'created_at' => $r['created_at'],
        'password_hash' => '<bcrypt>',
    ];
}

$out = [
    'generated_by' => 'v4/test/fixtures/php-schema-dump.php',
    'php_version' => PHP_VERSION,
    'sqlite_version' => (string)$pdo->query('SELECT sqlite_version()')->fetchColumn(),
    'migrations_applied' => count($versions),
    'schema_migrations' => $versions,
    'objects' => $objects,
    'seed_config' => [
        'app_password' => 'correct horse battery staple 2026',
        'clock' => '2026-11-23T12:00:00Z',
        'timezone' => 'UTC',
    ],
    'seed_rows' => [
        'settings' => $settings,
        'roles' => $roles,
        'users' => $users,
    ],
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

$pdo = null;
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);
