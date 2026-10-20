<?php
declare(strict_types=1);

/*
 * Tiny, dependency-free test harness for Apotheke:
 * - starts the app with PHP's built-in server on a temp data dir
 * - a cookie-aware HTTP client built on PHP streams (no curl extension needed)
 * - assertions with a pass/fail summary
 */

const ROOT = __DIR__ . '/..';

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];
    public static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        fwrite(STDOUT, "\n\033[1m== $name\033[0m\n");
    }

    public static function ok(bool $cond, string $msg, string $detail = ''): bool
    {
        if ($cond) {
            self::$pass++;
            fwrite(STDOUT, "  \033[32m✔\033[0m $msg\n");
        } else {
            self::$fail++;
            self::$failures[] = self::$section . ': ' . $msg . ($detail !== '' ? " ($detail)" : '');
            fwrite(STDOUT, "  \033[31m✘ $msg\033[0m" . ($detail !== '' ? "\n      $detail" : '') . "\n");
        }
        return $cond;
    }

    public static function eq(mixed $expected, mixed $actual, string $msg): bool
    {
        return self::ok($expected === $actual, $msg, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public static function summary(): int
    {
        fwrite(STDOUT, "\n" . self::$pass . ' passed, ' . self::$fail . " failed\n");
        foreach (self::$failures as $f) fwrite(STDOUT, "  - $f\n");
        return self::$fail === 0 ? 0 : 1;
    }
}

function tmpdir(string $prefix): string
{
    $d = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($d, 0775, true);
    return $d;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($dir);
}

function apotheke_app(): string
{
    return getenv('APOTHEKE_APP') === 'ci4' ? 'ci4' : 'legacy';
}

function apotheke_front_controller(?string $app = null): string
{
    $app ??= apotheke_app();
    if ($app === 'ci4') return ROOT . '/ci4/public/router.php';
    return ROOT . '/public/index.php';
}

final class Server
{
    public string $base;
    public string $log;
    public string $app;
    /** @var resource */
    private $proc;

    public function __construct(public string $dataDir, array $env = [], ?string $app = null)
    {
        $this->app = $app ?? apotheke_app();
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $this->base = "http://127.0.0.1:$port";
        $this->log = $dataDir . '.server.log';
        $cov = ($this->app !== 'ci4' && getenv('APOTHEKE_COVERAGE_DIR')) ? ['-d', 'auto_prepend_file=' . ROOT . '/tests/coverage/prepend.php', '-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . ROOT, '-d', 'pcov.exclude=~/(vendor|tests|data)/~'] : [];
        if ($this->app === 'ci4') {
            $front = ROOT . '/ci4/public/router.php';
            $docroot = ROOT . '/ci4/public';
        } else {
            $front = $cov ? ROOT . '/tests/coverage/router.php' : ROOT . '/public/index.php';
            $docroot = ROOT . '/public';
        }
        $cmd = [PHP_BINARY, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=22M', '-d', 'memory_limit=256M', ...$cov,
                '-S', "127.0.0.1:$port", '-t', $docroot, $front];
        $harness = $this->app === 'ci4' ? ['APOTHEKE_HARNESS' => '1'] : [];
        $fullEnv = array_merge(getenv(), test_server_env($dataDir), $harness, $env);
        $this->proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']], $pipes, ROOT, $fullEnv);
        if (!is_resource($this->proc)) throw new RuntimeException('could not start php -S');
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($c) { fclose($c); return; }
            usleep(50000);
        }
        throw new RuntimeException('php -S did not come up on port ' . $port);
    }

    public function client(): Client
    {
        return new Client($this->base);
    }

    public function logHas(string $needle): bool
    {
        return is_file($this->log) && str_contains((string)file_get_contents($this->log), $needle);
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}

/** The autofill-service stand-in (tests/stubs/autofill-stub.php) on a free port. */
final class StubServer
{
    public string $base;
    public string $log;
    /** @var resource */
    private $proc;

    public function __construct(array $env = [])
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $this->base = "http://127.0.0.1:$port";
        $this->log = tempnam(sys_get_temp_dir(), 'af-stub-');
        $fullEnv = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4', 'STUB_LOG' => $this->log, 'STUB_SLOW_S' => '6'], $env);
        $this->proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", ROOT . '/tests/stubs/autofill-stub.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, ROOT, $fullEnv);
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($c) { fclose($c); return; }
            usleep(50000);
        }
        throw new RuntimeException('autofill stub did not come up');
    }

    /** The last /v1/suggest request the stub saw (null: none since clear()). */
    public function last(): ?array
    {
        $s = (string)@file_get_contents($this->log);
        return $s === '' ? null : json_decode($s, true);
    }

    /** The Authorization header of the last GET /v1/info (null: none sent; false: no /v1/info since clear()). */
    public function lastInfoAuth(): string|null|false
    {
        $s = (string)@file_get_contents($this->log . '.info');
        return $s === '' ? false : (json_decode($s, true)['auth'] ?? null);
    }

    public function clear(): void { @file_put_contents($this->log, ''); @file_put_contents($this->log . '.info', ''); @unlink($this->log . '.cold'); }

    public function stop(): void
    {
        if (is_resource($this->proc)) { proc_terminate($this->proc); proc_close($this->proc); }
        @unlink($this->log);
        @unlink($this->log . '.info');
        @unlink($this->log . '.cold');
    }

    public function __destruct() { $this->stop(); }
}

final class Client
{
    public array $cookies = [];

    public function __construct(public string $base) {}

    /**
     * @param array<string,string|int> $fields
     * @param array<string,array{name:string,type:string,data:string}> $files
     * @return array{status:int,headers:array<string,string>,body:string,json:mixed}
     */
    public function req(string $method, string $path, array $fields = [], array $files = [], array $headers = []): array
    {
        $h = $headers;
        $body = '';
        if ($files) {
            $b = '----apotheke' . bin2hex(random_bytes(8));
            foreach ($fields as $k => $v) {
                $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
            }
            foreach ($files as $k => $f) {
                $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"{$f['name']}\"\r\nContent-Type: {$f['type']}\r\n\r\n{$f['data']}\r\n";
            }
            $body .= "--$b--\r\n";
            $h[] = "Content-Type: multipart/form-data; boundary=$b";
        } elseif ($method === 'POST') {
            $body = http_build_query($fields);
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $h), 'content' => $body,
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30, 'protocol_version' => 1.1,
        ]]);
        $resp = @file_get_contents($this->base . $path, false, $ctx);
        $raw = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = 0;
        $hdrs = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) { $status = (int)$m[1]; $hdrs = []; continue; }
            [$k, $v] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $k = strtolower($k);
            if ($k === 'set-cookie') {
                [$pair] = explode(';', $v, 2);
                [$ck, $cv] = explode('=', $pair, 2) + [1 => ''];
                $this->cookies[$ck] = $cv;
            }
            $hdrs[$k] = $v;
        }
        $resp = (string)$resp;
        return ['status' => $status, 'headers' => $hdrs, 'body' => $resp, 'json' => json_decode($resp, true), 'raw' => $raw];
    }

    public function get(string $path, array $headers = []): array { return $this->req('GET', $path, [], [], $headers); }
    public function post(string $path, array $fields = [], array $files = [], array $headers = []): array { return $this->req('POST', $path, $fields, $files, $headers); }

    /** POST as the app's JS does (expects JSON back). */
    public function api(string $path, array $fields = [], array $files = []): array
    {
        return $this->post($path, $fields, $files, ['X-Requested-With: fetch', 'Accept: application/json']);
    }

    public function json(string $path): array
    {
        return $this->get($path, ['X-Requested-With: fetch', 'Accept: application/json']);
    }

    public function csrf(): string
    {
        $r = $this->get('/');
        if (!preg_match('/name="csrf" content="([a-f0-9]+)"/', $r['body'], $m)) {
            $r = $this->get('/login');
            preg_match('/name="csrf" value="([a-f0-9]+)"/', $r['body'], $m);
        }
        return $m[1] ?? '';
    }
}

/* ---------- fixtures ---------- */

function img_jpeg(int $w, int $h, int $quality = 90, ?int $orientation = null): string
{
    $im = imagecreatetruecolor($w, $h);
    mt_srand($w * 31 + $h);
    for ($i = 0; $i < 40; $i++) {
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(20, max(21, intdiv($w, 3))), mt_rand(20, max(21, intdiv($h, 3))),
            imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    }
    ob_start();
    imagejpeg($im, null, $quality);
    $jpg = (string)ob_get_clean();
    if ($orientation !== null) {
        // Insert a minimal EXIF APP1 segment (little-endian TIFF, one IFD entry: Orientation) right after SOI.
        $tiff = "II*\x00" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
        $payload = "Exif\x00\x00" . $tiff;
        $jpg = substr($jpg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpg, 2);
    }
    return $jpg;
}

function img_png(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 160, 90));
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
}

/** Kept camera file for a served photo in an integration data dir, or null. */
function test_original(string $dir, string $served): ?string
{
    $map = $dir . '/originals/map/' . substr($served, 0, 32);
    if (!is_file($map)) return null;
    $base = trim((string)file_get_contents($map));
    if (!preg_match('/^[a-f0-9]{64}\.(jpg|png|webp|gif|heic)$/D', $base)) return null;
    $p = $dir . '/originals/' . $base;
    return is_file($p) ? $p : null;
}

function jpg_file(string $data, string $name = 'photo.jpg', string $type = 'image/jpeg'): array
{
    return ['name' => $name, 'type' => $type, 'data' => $data];
}

/* ---------- database under test: SQLite (default) or PostgreSQL ---------- */

function test_driver(): string
{
    foreach ([getenv('APOTHEKE_TEST_DB'), getenv('TEST_DB_DRIVER'), getenv('DB_DRIVER')] as $named) {
        if ($named === 'pgsql' || $named === 'sqlite') return $named;
    }
    return 'sqlite';
}

/**
 * Env passed to every php -S the harness starts.
 * APOTHEKE_TEST_DB and the DB_* keys are set here. The child does not
 * infer its database from whatever the PHPUnit process happened to inherit.
 *
 * @param array<string,string> $env
 * @return array<string,string>
 */
function test_server_env(string $dataDir, array $env = []): array
{
    $base = ['DATA_DIR' => $dataDir, 'TZ' => 'Europe/Athens', 'APP_PASSWORD' => '', 'SEED_DEFAULTS' => '0', 'APOTHEKE_CONFIG' => '/nonexistent'];
    $db = test_db_env($dataDir);
    $db['APOTHEKE_TEST_DB'] = test_driver();
    return array_merge($base, $db, $env);
}

/** Env for the app server: on pgsql every data dir gets its own throwaway schema. */
function test_db_env(string $dataDir): array
{
    if (test_driver() !== 'pgsql') return ['DB_DRIVER' => 'sqlite'];
    return ['DB_DRIVER' => 'pgsql', 'DB_SCHEMA' => test_schema($dataDir),
            'DB_HOST' => getenv('TEST_PG_HOST') ?: '127.0.0.1', 'DB_PORT' => getenv('TEST_PG_PORT') ?: '5432',
            'DB_NAME' => getenv('TEST_PG_DB') ?: 'apotheke', 'DB_USER' => getenv('TEST_PG_USER') ?: 'apotheke',
            'DB_PASSWORD' => getenv('TEST_PG_PASSWORD') ?: 'apotheke'];
}

function test_schema(string $dataDir): string
{
    return 't_' . substr(md5($dataDir), 0, 12);
}

/** PDO to the same database the server for $dataDir uses. */
function testdb(string $dataDir): PDO
{
    if (test_driver() !== 'pgsql') return sqlite("$dataDir/catalog.sqlite");
    $e = test_db_env($dataDir);
    $p = new PDO("pgsql:host={$e['DB_HOST']};port={$e['DB_PORT']};dbname={$e['DB_NAME']}", $e['DB_USER'], $e['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec('CREATE SCHEMA IF NOT EXISTS "' . $e['DB_SCHEMA'] . '"');
    $p->exec('SET search_path TO "' . $e['DB_SCHEMA'] . '"');
    return $p;
}

function testdb_drop(string $dataDir): void
{
    if (test_driver() === 'pgsql') testdb($dataDir)->exec('DROP SCHEMA IF EXISTS "' . test_schema($dataDir) . '" CASCADE');
}

function sqlite(string $file): PDO
{
    return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/** Card titles from a list JSON response. */
function titles(array $r): array
{
    preg_match_all('#class="card-title"[^>]*>(.*?)</a>#s', (string)($r['json']['html'] ?? ''), $m);
    return array_map(fn($s) => html_entity_decode(strip_tags($s), ENT_QUOTES), $m[1]);
}

function selected_option(string $html, string $selectName): ?string
{
    if (!preg_match('#<select name="' . preg_quote($selectName, '#') . '".*?</select>#s', $html, $m)) return null;
    return preg_match('#<option value="[^"]*" selected>([^<]*)#', $m[0], $o) ? html_entity_decode($o[1], ENT_QUOTES) : null;
}

function option_labels(string $html, string $selectName): array
{
    if (!preg_match('#<select name="' . preg_quote($selectName, '#') . '".*?</select>#s', $html, $m)) return [];
    preg_match_all('#<option[^>]*>([^<]*)#', $m[0], $o);
    return array_map(fn($s) => html_entity_decode($s, ENT_QUOTES), $o[1]);
}

/** Versions of the migration files shipped in migrations/. @return string[] */
function migration_versions(): array
{
    $v = array_map(fn($f) => substr(basename($f), 0, 4), glob(ROOT . '/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: []);
    sort($v);
    return $v;
}

/** Decode a QR <svg> produced by the app with zbarimg (null when zbarimg is not installed). */
function qr_decode_svg(string $svg): ?string
{
    if (!trim((string)shell_exec('command -v zbarimg 2>/dev/null'))) return null;
    if (!preg_match('/viewBox="0 0 (\d+) \d+"/', $svg, $vb) || !preg_match('/<path fill="#000" d="([^"]*)"/', $svg, $pm)) return '';
    $n = (int)$vb[1]; $sc = 6;
    $im = imagecreatetruecolor($n * $sc, $n * $sc);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    $black = imagecolorallocate($im, 0, 0, 0);
    preg_match_all('/M(\d+),(\d+)/', $pm[1], $mm, PREG_SET_ORDER);
    foreach ($mm as [, $x, $y]) imagefilledrectangle($im, (int)$x * $sc, (int)$y * $sc, ((int)$x + 1) * $sc - 1, ((int)$y + 1) * $sc - 1, $black);
    $f = tempnam(sys_get_temp_dir(), 'qr') . '.png';
    imagepng($im, $f);
    $out = (string)shell_exec('zbarimg -q --raw ' . escapeshellarg($f) . ' 2>/dev/null');
    @unlink($f);
    return rtrim($out, "\n");
}

/** Item ids of the cards in a list HTML fragment, in order. */
function ids_from_html(string $html): array
{
    preg_match_all('#<article class="card" data-id="(\d+)"#', $html, $m);
    return array_map('intval', $m[1]);
}

function item_exists_in(PDO $db, int $id): bool
{
    return (int)$db->query("SELECT COUNT(*) FROM items WHERE id = $id")->fetchColumn() === 1;
}

/** Is sign-in switched on in this database? (reads the settings and users tables directly) */
function auth_on_db(PDO $db): bool
{
    $v = $db->query("SELECT value FROM settings WHERE name = 'auth_enabled'")->fetchColumn();
    return json_decode((string)($v ?: 'false')) === true && (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}
