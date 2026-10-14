<?php
declare(strict_types=1);

/*
 * Apotheke – core: config, database, sessions, auth, CSRF, helpers.
 */

const APP_NAME   = 'Apotheke';   // product id (backups); the displayed name is app_name() (Control Panel)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/autofill.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/users.php';
define('DATA_DIR', cfg()['data_dir']);
define('IMG_DIR', DATA_DIR . '/images');
define('THUMB_DIR', DATA_DIR . '/thumbs');
define('SESS_DIR', DATA_DIR . '/sessions');

date_default_timezone_set(cfg()['timezone']);
mb_internal_encoding('UTF-8');

foreach ([DATA_DIR, IMG_DIR, THUMB_DIR, SESS_DIR] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        http_response_code(500);
        exit('Data directory ' . $dir . ' is not writable.');
    }
}

final class UserError extends RuntimeException {}

/* ---------- sessions ---------- */

/** Was this request made over HTTPS? X-Forwarded-Proto counts only with “Trust the reverse proxy” on. */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    try { $trust = (bool)setting('trust_proxy'); } catch (Throwable) { $trust = false; }
    if (!$trust) return false;
    $p = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $p === 'https';
}

/** Session cookie lifetime in seconds (setting “session_days”, 1–365). */
function session_lifetime(): int
{
    try { $d = (int)setting('session_days'); } catch (Throwable) { $d = 30; }
    return 86400 * max(1, min(365, $d ?: 30));
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $life = session_lifetime();
    ini_set('session.gc_maxlifetime', (string)$life);
    ini_set('session.use_strict_mode', '1');
    session_save_path(SESS_DIR);
    session_name('apothekesid');
    session_set_cookie_params([
        'lifetime' => $life,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => request_is_https(),
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

/* ---------- auth (user accounts, see users.php) ---------- */

function is_authed(): bool
{
    return current_user() !== null;
}

/** Sign in with a user name and password. */
function login(string $username, string $pw): bool
{
    if (!auth_enabled()) return false;
    $u = user_verify($username, $pw);
    if (!$u) return false;
    if ($u['status'] !== 'active') throw new UserError('Your account is waiting for an admin’s approval.', 403);
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now_utc(), $u['id']]);
    login_session($u['id']);
    return true;
}

/** Mark this session as signed in as user $uid (also after the user changed their own password). */
function login_session(int $uid): void
{
    $u = user_get($uid);
    if (!$u) return;
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    $_SESSION['auth_epoch'] = user_epoch($u);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    session_write_close();
    current_user(null, true);
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

/* ---------- helpers ---------- */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Current time. APOTHEKE_CLOCK (e.g. "2026-06-15 10:00:00", UTC) freezes the clock – used by the
 * visual E2E tests for deterministic screenshots; never set it in production.
 */
function app_time(): int
{
    static $fixed = null;
    if ($fixed === null) {
        $v = getenv('APOTHEKE_CLOCK');
        $fixed = is_string($v) && $v !== '' && ($t = strtotime($v . ' UTC')) !== false ? $t : 0;
    }
    return $fixed ?: time();
}

function app_now(?DateTimeZone $tz = null): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . app_time()))->setTimezone($tz ?? new DateTimeZone(date_default_timezone_get()));
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s', app_time());
}

function fmt_time(string $utc): string
{
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('j M Y, ' . setting('time_format'));
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

/** Natural-sort key: folded text with every number zero-padded ("box 2" < "box 10"); portable ORDER BY. */
function natkey(string $s): string
{
    return mb_substr((string)preg_replace_callback('/\d+/', static fn($m) => str_pad(ltrim($m[0], '0') ?: '0', 12, '0', STR_PAD_LEFT), fold(trim($s))), 0, 400);
}

function like_escape(string $s): string
{
    // '!' as escape char: a backslash inside a SQL literal confuses PDO's placeholder parser on PHP < 8.4 (pgsql)
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
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

function no_store(): void
{
    header('Cache-Control: no-store');
}

/** "1 item" / "8 items" — shared by list counts, labels, toasts and stats. */
function item_noun(int $n): string
{
    return $n . ' item' . ($n === 1 ? '' : 's');
}

/** Header / overview mini-summary: "1 item · 1 pc" / "8 items · 19 pcs". */
function stats_brief(int $items, int $qty): string
{
    return item_noun($items) . ' · ' . $qty . ' pc' . ($qty === 1 ? '' : 's');
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
    $t = (string)($_COOKIE['theme'] ?? '');
    return in_array($t, THEMES, true) ? $t : (string)setting('theme_default');
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

/** Absolute URL for $path, using PUBLIC_URL when configured (QR labels must work from a phone). */
function public_url(string $path): string
{
    $base = (string)setting('public_url');
    if ($base === '') {
        $https = request_is_https();
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host)) $host = 'localhost';
        $base = ($https ? 'https' : 'http') . '://' . $host;
    }
    return $base . $path;
}

/** The app version from the VERSION file (single source of truth, SemVer; updated by the release workflow). */
function app_version(): string
{
    static $v = null;
    return $v ??= (($t = @file_get_contents(dirname(__DIR__) . '/VERSION')) !== false && preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', trim($t)) ? trim($t) : '0.0.0');
}

