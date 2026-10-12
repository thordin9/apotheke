<?php
declare(strict_types=1);

/*
 * Apotheke – core: config, database, sessions, auth, CSRF, helpers.
 */

const APP_NAME   = 'Apotheke';
const APP_TAGLINE = 'the storehouse for your stuff';
const MAX_UPLOAD = 20 * 1024 * 1024;   // bytes, server-side hard limit
const FULL_MAX   = 1600;               // px, longest side of stored image
const THUMB_MAX  = 480;                // px, longest side of thumbnail
const PAGE_SIZE  = 60;

define('DATA_DIR', rtrim((string)(getenv('DATA_DIR') ?: dirname(__DIR__) . '/data'), '/'));
define('IMG_DIR', DATA_DIR . '/images');
define('THUMB_DIR', DATA_DIR . '/thumbs');
define('SESS_DIR', DATA_DIR . '/sessions');

date_default_timezone_set((string)(getenv('TZ') ?: 'UTC'));
mb_internal_encoding('UTF-8');

foreach ([DATA_DIR, IMG_DIR, THUMB_DIR, SESS_DIR] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        http_response_code(500);
        exit('Data directory ' . $dir . ' is not writable.');
    }
}

final class UserError extends RuntimeException {}

/* ---------- sessions ---------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $life = 60 * 60 * 24 * 180;
    ini_set('session.gc_maxlifetime', (string)$life);
    ini_set('session.use_strict_mode', '1');
    session_save_path(SESS_DIR);
    session_name('apothekesid');
    session_set_cookie_params([
        'lifetime' => $life,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

/** Open session, make sure a CSRF token exists, then release the lock so parallel requests (images!) never queue. */
function init_session(): void
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    session_write_close();
}

/* ---------- auth (optional, APP_PASSWORD) ---------- */

function app_password(): string
{
    return (string)(getenv('APP_PASSWORD') ?: '');
}

function is_authed(): bool
{
    return app_password() === '' || !empty($_SESSION['auth']);
}

function login(string $pw): bool
{
    if (app_password() === '' || !hash_equals(app_password(), $pw)) return false;
    start_session();
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    session_write_close();
    return true;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_regenerate_id(true);
    session_write_close();
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    return (string)($_SESSION['csrf'] ?? '');
}

function check_csrf(): void
{
    $sent = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (csrf_token() === '' || !hash_equals(csrf_token(), $sent)) {
        throw new UserError('Your session expired. Reload the page and try again.', 419);
    }
}

/* ---------- database ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('sqlite:' . DATA_DIR . '/catalog.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON;');
    migrate($pdo);
    return $pdo;
}

const SCHEMA_VERSION = 3;

function migrate(PDO $pdo): void
{
    // Natural, case/accent-insensitive ordering: "box 2" < "box 10".
    $pdo->sqliteCreateCollation('NATSORT', static fn(string $a, string $b): int => strnatcmp(fold($a), fold($b)));

    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= SCHEMA_VERSION) return;

    if ($v >= 1) {
        // Safety net: snapshot the existing database before restructuring it.
        if (!glob(DATA_DIR . '/catalog-v' . $v . '-backup-*.sqlite')) {
            $pdo->exec('VACUUM INTO ' . $pdo->quote(DATA_DIR . '/catalog-v' . $v . '-backup-' . gmdate('Ymd-His') . '.sqlite'));
        }
    }

    $pdo->exec('BEGIN IMMEDIATE');   // take the write lock up front so concurrent first requests serialise
    try {
        // Re-check inside the write transaction (another request may have migrated meanwhile).
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v < 2) migrate_to_v2($pdo, $v);
        if ($v < 3) migrate_to_v3($pdo);
        $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** v0/v1 → v2: managed locations table; items.location (text) → items.location_id. */
function migrate_to_v2(PDO $pdo, int $v): void
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
        // v1 stored free-text locations on items: turn them into rows, dedupe case-insensitively, link items.
        $ins = $pdo->prepare('INSERT OR IGNORE INTO locations (name, name_key, created_at) VALUES (?,?,?)');
        $ids = [];
        $rows = $pdo->query('SELECT id, name, location, quantity, notes, image, created_at, updated_at FROM items ORDER BY id')->fetchAll();
        foreach ($rows as $r) {
            $name = clean_line((string)$r['location'], 120);
            if ($name === '') continue;
            $key = fold($name);
            if (!isset($ids[$key])) {
                $ins->execute([$name, $key, $r['created_at']]);
                $st = $pdo->prepare('SELECT id FROM locations WHERE name_key = ?');
                $st->execute([$key]);
                $ids[$key] = (int)$st->fetchColumn();
                $st->closeCursor();   // an open read cursor would block DROP TABLE below
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
        PRAGMA user_version = 2;
    SQL);
}

/** v2 → v3: managed categories table + items.category_id. */
function migrate_to_v3(PDO $pdo): void
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

/* ---------- helpers ---------- */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function fmt_time(string $utc): string
{
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('j M Y, H:i');
    } catch (Throwable) {
        return $utc;
    }
}

/** Case- and accent-insensitive folding (Latin + Greek) used for search. */
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

function clean_line(string $s, int $max): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, $max);
}

function like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

function wants_json(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $to): never
{
    if (wants_json()) json_out(['ok' => true, 'redirect' => $to]);
    header('Location: ' . $to, true, 303);
    exit;
}

function asset(string $file): string
{
    $path = dirname(__DIR__) . '/public/assets/' . $file;
    $v = is_file($path) ? substr(md5((string)filemtime($path) . filesize($path)), 0, 8) : '0';
    return '/assets/' . $file . '?v=' . $v;
}

/* ---------- theme preference (cookie, so the server renders the right theme before first paint) ---------- */

const THEMES = ['auto', 'light', 'dark'];

function theme_pref(): string
{
    $t = (string)($_COOKIE['theme'] ?? 'auto');
    return in_array($t, THEMES, true) ? $t : 'auto';
}

function set_theme_cookie(string $theme): string
{
    $theme = in_array($theme, THEMES, true) ? $theme : 'auto';
    setcookie('theme', $theme, ['expires' => time() + 60 * 60 * 24 * 400, 'path' => '/', 'samesite' => 'Lax']);
    $_COOKIE['theme'] = $theme;
    return $theme;
}

/** Only same-site, path-absolute redirect targets ("/…", never "//host" or "/\host"). */
function safe_back(string $back): string
{
    return preg_match('#^/(?![/\\\\])[^\s]*$#D', $back) ? $back : '/';
}
