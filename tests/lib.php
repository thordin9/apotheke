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

final class Server
{
    public string $base;
    public string $log;
    /** @var resource */
    private $proc;

    public function __construct(public string $dataDir, array $env = [])
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $this->base = "http://127.0.0.1:$port";
        $this->log = $dataDir . '.server.log';
        $cmd = [PHP_BINARY, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=22M', '-d', 'memory_limit=256M',
                '-S', "127.0.0.1:$port", '-t', ROOT . '/public', ROOT . '/public/index.php'];
        $fullEnv = array_merge(getenv(), ['DATA_DIR' => $dataDir, 'TZ' => 'Europe/Athens', 'APP_PASSWORD' => ''], $env);
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
        return ['status' => $status, 'headers' => $hdrs, 'body' => $resp, 'json' => json_decode($resp, true)];
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

function jpg_file(string $data, string $name = 'photo.jpg', string $type = 'image/jpeg'): array
{
    return ['name' => $name, 'type' => $type, 'data' => $data];
}

function sqlite(string $file): PDO
{
    return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/** Card titles from a list JSON response. */
function titles(array $r): array
{
    preg_match_all('/class="card-title"[^>]*>([^<]*)</', (string)($r['json']['html'] ?? ''), $m);
    return array_map(fn($s) => html_entity_decode($s, ENT_QUOTES), $m[1]);
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
