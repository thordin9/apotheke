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
require_once __DIR__ . '/image-color.php';
imagick_set_resource_limits();
require_once __DIR__ . '/autofill.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/hosts.php';
define('DATA_DIR', cfg()['data_dir']);
define('IMG_DIR', DATA_DIR . '/images');
define('THUMB_DIR', DATA_DIR . '/thumbs');
$originalsDir = trim((string)(cfg()['originals_dir'] ?? ''));
if ($originalsDir === '') {
    $originalsDir = DATA_DIR . '/originals';
} elseif ($originalsDir[0] !== '/') {
    $originalsDir = dirname(__DIR__) . '/' . $originalsDir;
}
define('ORIGINALS_DIR', rtrim($originalsDir, '/')); // camera uploads; never served. Default is outside public/.
unset($originalsDir);
define('SESS_DIR', DATA_DIR . '/sessions');

date_default_timezone_set(cfg()['timezone']);
mb_internal_encoding('UTF-8');

$webRoot = dirname(__DIR__) . '/public';
if (originals_dir_in_web_root(ORIGINALS_DIR, $webRoot)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo originals_web_root_public_message();
    }
    startup_log(originals_web_root_log_message(ORIGINALS_DIR, $webRoot));
    exit(1);
}
unset($webRoot);

foreach ([DATA_DIR, IMG_DIR, THUMB_DIR, ORIGINALS_DIR, ORIGINALS_DIR . '/map', SESS_DIR] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $msg = 'Data directory ' . $dir . ' is not writable.';
        http_response_code(500);
        if (PHP_SAPI === 'cli') {
            $err = fopen('php://stderr', 'w');
            if ($err !== false) {
                fwrite($err, $msg . "\n");
                fclose($err);
            }
        } else {
            echo $msg;
        }
        exit(1);
    }
}
originals_chmod_dir(ORIGINALS_DIR);
originals_chmod_dir(ORIGINALS_DIR . '/map');
originals_htaccess_write(ORIGINALS_DIR . '/.htaccess');
originals_chmod_file(ORIGINALS_DIR . '/.htaccess');

/**
 * Deny the originals directory if it is ever placed under an Apache document root.
 * 2.4 uses mod_authz_core; 2.2 only understands Deny from all.
 */
function originals_htaccess(): string
{
    return "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
}

function originals_htaccess_write(string $path): void
{
    $want = originals_htaccess();
    if (is_file($path) && (string)@file_get_contents($path) === $want) return;
    @file_put_contents($path, $want);
    originals_chmod_file($path);
}

/** True when $dir is the web root or any directory inside it, whatever that directory is named. */
function originals_dir_in_web_root(string $dir, string $webRoot): bool
{
    $dir = path_normalize($dir);
    $web = path_normalize($webRoot);
    if ($dir === '' || $web === '') return false;
    if ($dir === $web || str_starts_with($dir, $web . '/')) return true;
    $realWeb = realpath($webRoot);
    $realDir = realpath($dir);
    if ($realWeb === false || $realDir === false) return false;
    $realWeb = path_normalize($realWeb);
    $realDir = path_normalize($realDir);
    return $realDir === $realWeb || str_starts_with($realDir, $realWeb . '/');
}

/** Collapse "." and ".." without requiring the path to exist. */
function path_normalize(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $abs = str_starts_with($path, '/');
    $parts = [];
    foreach (explode('/', $path) as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') { array_pop($parts); continue; }
        $parts[] = $p;
    }
    return ($abs ? '/' : '') . implode('/', $parts);
}

/** Shown in the browser when photo storage is inside public/. No filesystem path. */
function originals_web_root_public_message(): string
{
    return 'The catalog cannot start because photo storage is inside the public web directory. Set originals_dir to a directory outside public/.';
}

/** Server log only, including the paths an admin needs to move photo storage. */
function originals_web_root_log_message(string $dir, string $webRoot): string
{
    return 'ORIGINALS_DIR is inside the web root (originals=' . $dir . ', web=' . $webRoot . '). Set originals_dir to a directory outside public/.';
}

/**
 * Write a startup failure to the process log. php-fpm has no standard-error stream constant,
 * so this uses error_log (the image points that at the container output).
 */
function startup_log(string $message): void
{
    error_log($message);
}

/** 0750 for originals directories. Failures are ignored where the filesystem cannot chmod. */
function originals_chmod_dir(string $dir): void
{
    if (is_dir($dir)) @chmod($dir, 0750);
}

/** 0640 for a camera file, map entry or deny file. Failures are ignored. */
function originals_chmod_file(string $path): void
{
    if (is_file($path)) @chmod($path, 0640);
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

/** Absolute session cookie lifetime in seconds (setting “session_days”, 1–365). Not extended by later visits. */
function session_lifetime(): int
{
    try { $d = (int)setting('session_days'); } catch (Throwable) { $d = 30; }
    return 86400 * max(1, min(365, $d ?: 30));
}

/**
 * Idle limit in seconds (setting “session_idle_hours”, 1–8760). Default 168 hours.
 * The stored number is read directly: setting() replaces a hand-edited row outside
 * 1–8760 with the default, and this clamp is the bound on that stored number.
 * 0 or an unreadable value is 168 hours.
 */
function session_idle_seconds(): int
{
    $h = 168;
    try {
        $st = db()->prepare('SELECT value FROM settings WHERE name = ?');
        $st->execute(['session_idle_hours']);
        $col = $st->fetchColumn();
        if ($col !== false) {
            $decoded = json_decode((string)$col, true);
            if (is_int($decoded) || is_float($decoded)) $h = (int)$decoded;
            elseif (is_string($decoded) && preg_match('/^-?\d+$/D', $decoded)) $h = (int)$decoded;
        }
    } catch (Throwable) {
        $h = 168;
    }
    return 3600 * max(1, min(8760, $h ?: 168));
}

/**
 * True when this signed-in session has had no request for longer than session_idle_hours.
 * A session with no stamp yet is not idle: this request is the first one recorded.
 */
function session_idle_expired(): bool
{
    if ((int)($_SESSION['uid'] ?? 0) <= 0) return false;
    $last = (int)($_SESSION['last_activity'] ?? 0);
    if ($last <= 0) return false;
    return (app_time() - $last) > session_idle_seconds();
}

/**
 * True when this signed-in session is older than session_days.
 * A session with no sign-in time yet is not expired: this request records it.
 * Later requests do not move that time.
 */
function session_absolute_expired(): bool
{
    if ((int)($_SESSION['uid'] ?? 0) <= 0) return false;
    $at = (int)($_SESSION['signed_in_at'] ?? 0);
    if ($at <= 0) return false;
    return (app_time() - $at) > session_lifetime();
}

/** Record the sign-in time once. A session from before this check gets it on the next request. */
function session_stamp_sign_in(): void
{
    if ((int)($_SESSION['uid'] ?? 0) <= 0) return;
    if ((int)($_SESSION['signed_in_at'] ?? 0) > 0) return;
    $_SESSION['signed_in_at'] = app_time();
}

/**
 * Remember this request on a signed-in session. Stored in the session file, not in the database.
 * A stamp under a minute old is kept, so a busy page does not rewrite the file on every request.
 */
function session_touch(): void
{
    if ((int)($_SESSION['uid'] ?? 0) <= 0) return;
    $now = app_time();
    $last = (int)($_SESSION['last_activity'] ?? 0);
    if ($last > 0 && ($now - $last) < 60) return;
    $_SESSION['last_activity'] = $now;
}

/**
 * Mark the session cookie Secure? Yes over HTTPS (see request_is_https()), and also when PUBLIC_URL is https://
 * and the request uses PUBLIC_URL's host: then the app is reached through that HTTPS address even if the
 * proxy in front is not trusted. Opening the app directly by address keeps working over plain HTTP.
 */
function session_cookie_secure(): bool
{
    if (request_is_https()) return true;
    try { $pu = (string)setting('public_url'); } catch (Throwable) { $pu = (string)(cfg()['public_url'] ?? ''); }
    if (!str_starts_with(strtolower($pu), 'https://')) return false;
    $want = parse_url($pu, PHP_URL_HOST);
    $got = host_split((string)($_SERVER['HTTP_HOST'] ?? ''));
    return is_string($want) && $got !== null && strtolower(trim($want, '[]')) === $got[0];
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
        'secure'   => session_cookie_secure(),
    ]);
    session_start();
}

/** The request carries a session cookie whose session file still exists. */
function session_cookie_live(): bool
{
    $id = (string)($_COOKIE['apothekesid'] ?? '');
    return preg_match('/^[A-Za-z0-9,-]{22,256}$/D', $id) === 1 && is_file(SESS_DIR . '/sess_' . $id);
}

/**
 * Load an existing session (if the request has one), end it when the sign-in is older than session_days or
 * has been idle longer than session_idle_hours, record this request as activity (at most once a minute), make
 * sure a CSRF token exists, then release the lock so parallel requests (images!) never queue. A request without
 * a live session gets none here: 404s, branding, redirects and other anonymous pages without a form leave nothing
 * on disk (SEC-L2). The session starts on demand when a form needs a token (csrf_token()) or someone signs in.
 * Activity and the sign-in time are stored in the session file.
 */
function init_session(): void
{
    if (!session_cookie_live()) return;
    start_session();
    // The same end as a manual sign-out: the old file is deleted and the request is no longer signed in.
    if (session_idle_expired() || session_absolute_expired()) {
        logout();
        current_user(null, true);
        return;
    }
    session_stamp_sign_in();
    session_touch();
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
    $_SESSION['last_activity'] = app_time();
    $_SESSION['signed_in_at'] = app_time();
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

/**
 * The session's CSRF token. A page that renders a form calls this before any output, which starts the session
 * on demand. With $create false (error pages) or once output has started, it never starts one.
 */
function csrf_token(bool $create = true): string
{
    $tok = (string)($_SESSION['csrf'] ?? '');
    if ($tok !== '' || !$create || headers_sent()) return $tok;
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    session_write_close();
    return (string)$_SESSION['csrf'];
}

function check_csrf(): void
{
    $sent = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $tok = csrf_token(false);
    if ($tok === '' || !hash_equals($tok, $sent)) {
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
        // Only a Host that passes the allow-list (hosts.php) is ever printed; anything else becomes localhost.
        $https = request_is_https();
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !host_allowed($host, request_configured_hosts(...), (string)gethostname())) $host = 'localhost';
        $base = ($https ? 'https' : 'http') . '://' . strtolower($host);
    }
    return $base . $path;
}

/** The app version from the VERSION file (single source of truth, SemVer; updated by the release workflow). */
function app_version(): string
{
    static $v = null;
    return $v ??= (($t = @file_get_contents(dirname(__DIR__) . '/VERSION')) !== false && preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', trim($t)) ? trim($t) : '0.0.0');
}

