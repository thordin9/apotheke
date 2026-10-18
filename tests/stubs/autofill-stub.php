<?php
declare(strict_types=1);

/*
 * Tiny stand-in for the autofill service's echo backend (API v1), for tests and CI only.
 * Run:  PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8090 tests/stubs/autofill-stub.php
 *
 * The mode and token are picked by a path prefix, so one stub serves every test case:
 *   http://127.0.0.1:8090                      echo, no token
 *   http://127.0.0.1:8090/m/<mode>             ok | error (503) | malformed (200 "{not json") | slow (sleeps STUB_SLOW_S)
 *                                              | badinfo (/v1/info not API v1) | warming (/v1/info ready:false)
 *                                              | toolarge (413) | unsupported (415) | empty (200 {})
 *                                              | busy (503 backend_unavailable + Retry-After, always)
 *                                              | fault (503 backend_error: the model failed on this photo)
 *                                              | warmup (503 backend_unavailable "model … is warming up" + Retry-After)
 *                                              | cold (the same 503 on every other /v1/suggest, starting with the first)
 *   http://127.0.0.1:8090/t/<token>            requires "Authorization: Bearer <token>" on everything but /healthz
 *   http://127.0.0.1:8090/m/slow/t/s3cret      both
 * More prefixes, in any order before /t/:
 *   /r/<s>     Retry-After seconds for busy/cold (default 1)
 *   /l/<ms>    GET /v1/info reports avg_latency_ms = <ms> (without it the field is left out, like an older service)
 *   /d/<ms>    POST /v1/suggest waits <ms> before answering (the model "thinking")
 *   /s/<name>  suggest this category instead of the first one from the context
 *   /n/<models>  API v1.2: GET /v1/info lists these models, comma-separated; `*` after an id marks the default (else
 *              the first), `=<ms>` gives its avg_latency_ms, e.g. /n/qwen-3b*=4000,qwen3-2b=2500 (URL-encode as needed).
 *              /v1/suggest then answers with model_used: context.model when listed, else the default (unknown → default).
 *   /z/<sizes>   API v1.2: GET /v1/info lists these image_sizes (long edges), `*` marks the recommended one,
 *              e.g. /z/448,672*,1024
 * Mode `switch` (with /n/): the stub remembers which model is "loaded" (the default at first). A /v1/suggest for
 * another model answers 503 backend_unavailable "model '<id>' is loading" + Retry-After (/r/) once and loads it, so
 * Apotheke's single retry gets the answer – a model switch is a cold start. /v1/info marks the loaded model.
 * STUB_LOG=<file>: the last /v1/suggest request is written there as JSON (headers, context, image type, size,
 * width, height, alpha); every /v1/suggest is also appended to <file>.jsonl. Without STUB_LOG these go to the temp dir
 * (af-stub-<port>.jsonl). GET /_stub/uploads returns the last 50 of them (no token needed) – QA's hook to check the
 * size, dimensions and type of each upload. DELETE /_stub/uploads clears them (and the loaded model).
 * Every GET /v1/info writes its Authorization header (or null) to <STUB_LOG>.info.
 * STUB_MAX_BYTES: upload limit (default 10 MiB, like the real service).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mode = 'ok';
$token = '';
$opt = ['r' => 1, 'l' => null, 'd' => 0];
if (preg_match('#^/m/([a-z]+)(/.*)?$#D', $path, $m)) { $mode = $m[1]; $path = $m[2] ?? '/'; }
$opt['s'] = $opt['n'] = $opt['z'] = null;
while (true) {
    if (preg_match('#^/([rld])/(\d{1,7})(/.*)?$#D', $path, $m)) { $opt[$m[1]] = (int)$m[2]; $path = $m[3] ?? '/'; }
    elseif (preg_match('#^/s/([^/]+)(/.*)?$#D', $path, $m)) { $opt['s'] = rawurldecode($m[1]); $path = $m[2] ?? '/'; }
    elseif (preg_match('#^/([nz])/([^/]+)(/.*)?$#D', $path, $m)) { $opt[$m[1]] = rawurldecode($m[2]); $path = $m[3] ?? '/'; }
    else break;
}
if (preg_match('#^/t/([^/]+)(/.*)?$#D', $path, $m)) { $token = rawurldecode($m[1]); $path = $m[2] ?? '/'; }
$maxBytes = (int)(getenv('STUB_MAX_BYTES') ?: 10485760);

function out(int $status, mixed $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $h) header($h);
    echo is_string($body) ? $body : json_encode($body);
    exit;
}
function fail(int $status, string $code, string $msg, array $headers = []): never
{
    out($status, ['error' => ['code' => $code, 'message' => $msg]], $headers);
}

// models (/n/) and image sizes (/z/), parsed once
$models = [];
foreach ($opt['n'] === null ? [] : explode(',', $opt['n']) as $spec) {
    if (!preg_match('/^(.+?)(\*)?(?:=(\d+))?$/D', trim($spec), $m)) continue;
    $models[$m[1]] = ['id' => $m[1], 'default' => ($m[2] ?? '') === '*', 'lat' => isset($m[3]) ? (int)$m[3] : null];
}
if ($models && !array_filter($models, fn($x) => $x['default'])) $models[array_key_first($models)]['default'] = true;
$defaultModel = $models ? array_values(array_filter($models, fn($x) => $x['default']))[0]['id'] : null;
$sizes = [];
foreach ($opt['z'] === null ? [] : explode(',', $opt['z']) as $spec) {
    if (preg_match('/^(\d+)(\*)?$/D', trim($spec), $m)) $sizes[] = ['long_edge' => (int)$m[1], 'recommended' => ($m[2] ?? '') === '*'];
}
$stateBase = getenv('STUB_LOG') ?: sys_get_temp_dir() . '/af-stub-' . ($_SERVER['SERVER_PORT'] ?? '0');
$loadedFile = $stateBase . '.loaded';
$loaded = is_file($loadedFile) ? (string)file_get_contents($loadedFile) : $defaultModel;
if ($loaded !== null && !isset($models[$loaded])) $loaded = $defaultModel;

if ($path === '/healthz') out(200, ['status' => 'ok']);
if ($path === '/_stub/uploads') {
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') { @unlink($stateBase . '.jsonl'); @unlink($loadedFile); out(200, ['cleared' => true]); }
    $lines = is_file($stateBase . '.jsonl') ? file($stateBase . '.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    out(200, array_map(fn($l) => json_decode($l, true), array_slice($lines, -50)));
}
if ($path === '/v1/info' && $_SERVER['REQUEST_METHOD'] === 'GET' && ($log = getenv('STUB_LOG'))) {
    file_put_contents($log . '.info', json_encode(['auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null]));
}
if ($token !== '' && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $token) {
    fail(401, 'unauthorized', 'missing or invalid bearer token', ['WWW-Authenticate: Bearer']);
}
if ($mode === 'slow') sleep((int)(getenv('STUB_SLOW_S') ?: 10));
if ($path === '/v1/info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($mode === 'badinfo') out(200, ['hello' => 'world']);
    $info = ['name' => 'echo', 'version' => '1', 'capabilities' => ['name', 'category', 'tags', 'description'], 'backend' => 'echo', 'max_image_bytes' => $maxBytes];
    if ($mode === 'warming') $info['ready'] = false;
    if ($opt['l'] !== null) $info['avg_latency_ms'] = $opt['l'];
    if ($models) {
        $info['version'] = '1.2';
        $info['models'] = array_values(array_map(fn($x) => ['id' => $x['id'], 'default' => $x['default'], 'loaded' => $x['id'] === $loaded]
            + ($x['lat'] !== null ? ['avg_latency_ms' => $x['lat']] : []), $models));
    }
    if ($sizes) $info['image_sizes'] = $sizes;
    out(200, $info);
}
if ($path === '/v1/suggest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_FILES['image'] ?? null;
    $data = $f && $f['error'] === UPLOAD_ERR_OK ? (string)file_get_contents($f['tmp_name']) : '';
    $ctxRaw = (string)($_POST['context'] ?? '');
    $ctx = $ctxRaw === '' ? [] : json_decode($ctxRaw, true);
    $kind = str_starts_with($data, "\xFF\xD8\xFF") ? 'JPEG' : (str_starts_with($data, "\x89PNG") ? 'PNG' : (substr($data, 8, 4) === 'WEBP' ? 'WEBP' : null));
    $dims = $data !== '' ? @getimagesizefromstring($data) : false;
    // PNG colour type 4/6 (grey/RGB + alpha) or a tRNS chunk: the upload kept its transparency
    $alpha = $kind === 'PNG' ? (in_array(ord($data[25] ?? "\0"), [4, 6], true) || str_contains(substr($data, 0, (int)strpos($data, 'IDAT') ?: 4096), 'tRNS')) : false;
    $entry = json_encode(['auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'context' => $ctx, 'type' => $kind,
        'bytes' => strlen($data), 'width' => $dims[0] ?? null, 'height' => $dims[1] ?? null, 'alpha' => $alpha, 'mode' => $mode,
        'filename' => $f['name'] ?? null, 'mime' => $f['type'] ?? null]);
    if ($log = getenv('STUB_LOG')) file_put_contents($log, $entry);
    file_put_contents($stateBase . '.jsonl', $entry . "\n", FILE_APPEND | LOCK_EX);
    if ($data === '') fail(400, 'bad_request', 'image is required');
    if ($mode === 'toolarge' || strlen($data) > $maxBytes) fail(413, 'payload_too_large', 'image too large');
    if ($mode === 'unsupported' || $kind === null) fail(415, 'unsupported_media_type', 'image must be JPEG, PNG or WebP');
    if (!is_array($ctx)) fail(400, 'bad_request', 'context must be a JSON object');
    if ($mode === 'error') fail(503, 'backend_unavailable', 'echo backend simulated failure');
    if ($mode === 'fault') fail(503, 'backend_error', 'model returned no usable answer', $opt['r'] > 1 ? ['Retry-After: ' . $opt['r']] : []);
    if ($mode === 'warmup') fail(503, 'backend_unavailable', "model 'qwen2.5vl-jetson:3b' is warming up", ['Retry-After: ' . $opt['r']]);
    if ($mode === 'busy') fail(503, 'backend_unavailable', 'backend busy, queue full', ['Retry-After: ' . $opt['r']]);
    if ($mode === 'cold') {
        // every other call is "cold": the state lives next to the log (or in the temp dir), one file per stub port
        $state = (getenv('STUB_LOG') ?: sys_get_temp_dir() . '/af-stub-' . ($_SERVER['SERVER_PORT'] ?? '0')) . '.cold';
        if (!@unlink($state)) { touch($state); fail(503, 'backend_unavailable', "model 'qwen2.5vl-jetson:3b' is loading", ['Retry-After: ' . $opt['r']]); }
    }
    // API v1.2: the requested model when the service has it, else its default (an unknown model falls back)
    $want = is_array($ctx) && is_string($ctx['model'] ?? null) ? $ctx['model'] : null;
    $used = $models ? (($want !== null && isset($models[$want])) ? $want : $defaultModel) : null;
    if ($mode === 'switch' && $used !== null && $used !== $loaded) {
        file_put_contents($loadedFile, $used);
        fail(503, 'backend_unavailable', "model '$used' is loading", ['Retry-After: ' . $opt['r']]);
    }
    if ($opt['d'] > 0) usleep($opt['d'] * 1000);
    if ($mode === 'malformed') out(200, '{not json');
    if ($mode === 'empty') out(200, new stdClass());
    $cats = array_values(array_filter($ctx['categories'] ?? [], fn($c) => is_string($c) && trim($c) !== ''));
    $tags = array_slice(array_values(array_filter($ctx['tags'] ?? [], fn($t) => is_string($t) && trim($t) !== '')), 0, 3) ?: ['echo', 'sample'];
    out(200, [
        'name' => ['value' => 'Echo item ' . substr(hash('sha256', $data), 0, 8), 'confidence' => 0.5],
        'category' => ['value' => $opt['s'] ?? $cats[0] ?? 'Misc', 'confidence' => 0.5],
        'tags' => array_map(fn($t) => ['value' => $t, 'confidence' => 0.5], $tags),
        'description' => ['value' => "$kind image, " . strlen($data) . ' bytes', 'confidence' => 0.5],
    ] + ($used !== null ? ['model_used' => $used] : []));
}
fail(404, 'not_found', 'not found');
