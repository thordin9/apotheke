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
 *                                              | cold (the same 503 on every other /v1/suggest, starting with the first)
 *   http://127.0.0.1:8090/t/<token>            requires "Authorization: Bearer <token>" on everything but /healthz
 *   http://127.0.0.1:8090/m/slow/t/s3cret      both
 * More prefixes, in any order before /t/:
 *   /r/<s>     Retry-After seconds for busy/cold (default 1)
 *   /l/<ms>    GET /v1/info reports avg_latency_ms = <ms> (without it the field is left out, like an older service)
 *   /d/<ms>    POST /v1/suggest waits <ms> before answering (the model "thinking")
 * STUB_LOG=<file>: the last /v1/suggest request is written there as JSON (headers, context, image type and size).
 * Every GET /v1/info writes its Authorization header (or null) to <STUB_LOG>.info.
 * STUB_MAX_BYTES: upload limit (default 10 MiB, like the real service).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mode = 'ok';
$token = '';
$opt = ['r' => 1, 'l' => null, 'd' => 0];
if (preg_match('#^/m/([a-z]+)(/.*)?$#D', $path, $m)) { $mode = $m[1]; $path = $m[2] ?? '/'; }
while (preg_match('#^/([rld])/(\d{1,7})(/.*)?$#D', $path, $m)) { $opt[$m[1]] = (int)$m[2]; $path = $m[3] ?? '/'; }
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

if ($path === '/healthz') out(200, ['status' => 'ok']);
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
    out(200, $info);
}
if ($path === '/v1/suggest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_FILES['image'] ?? null;
    $data = $f && $f['error'] === UPLOAD_ERR_OK ? (string)file_get_contents($f['tmp_name']) : '';
    $ctxRaw = (string)($_POST['context'] ?? '');
    $ctx = $ctxRaw === '' ? [] : json_decode($ctxRaw, true);
    $kind = str_starts_with($data, "\xFF\xD8\xFF") ? 'JPEG' : (str_starts_with($data, "\x89PNG") ? 'PNG' : (substr($data, 8, 4) === 'WEBP' ? 'WEBP' : null));
    $dims = $data !== '' ? @getimagesizefromstring($data) : false;
    if ($log = getenv('STUB_LOG')) {
        file_put_contents($log, json_encode(['auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'context' => $ctx, 'type' => $kind,
            'bytes' => strlen($data), 'width' => $dims[0] ?? null, 'height' => $dims[1] ?? null, 'mode' => $mode]));
    }
    if ($data === '') fail(400, 'bad_request', 'image is required');
    if ($mode === 'toolarge' || strlen($data) > $maxBytes) fail(413, 'payload_too_large', 'image too large');
    if ($mode === 'unsupported' || $kind === null) fail(415, 'unsupported_media_type', 'image must be JPEG, PNG or WebP');
    if (!is_array($ctx)) fail(400, 'bad_request', 'context must be a JSON object');
    if ($mode === 'error') fail(503, 'backend_unavailable', 'echo backend simulated failure');
    if ($mode === 'fault') fail(503, 'backend_error', 'model returned no usable answer', $opt['r'] > 1 ? ['Retry-After: ' . $opt['r']] : []);
    if ($mode === 'busy') fail(503, 'backend_unavailable', 'backend busy, queue full', ['Retry-After: ' . $opt['r']]);
    if ($mode === 'cold') {
        // every other call is "cold": the state lives next to the log (or in the temp dir), one file per stub port
        $state = (getenv('STUB_LOG') ?: sys_get_temp_dir() . '/af-stub-' . ($_SERVER['SERVER_PORT'] ?? '0')) . '.cold';
        if (!@unlink($state)) { touch($state); fail(503, 'backend_unavailable', 'model is loading', ['Retry-After: ' . $opt['r']]); }
    }
    if ($opt['d'] > 0) usleep($opt['d'] * 1000);
    if ($mode === 'malformed') out(200, '{not json');
    if ($mode === 'empty') out(200, new stdClass());
    $cats = array_values(array_filter($ctx['categories'] ?? [], fn($c) => is_string($c) && trim($c) !== ''));
    $tags = array_slice(array_values(array_filter($ctx['tags'] ?? [], fn($t) => is_string($t) && trim($t) !== '')), 0, 3) ?: ['echo', 'sample'];
    out(200, [
        'name' => ['value' => 'Echo item ' . substr(hash('sha256', $data), 0, 8), 'confidence' => 0.5],
        'category' => ['value' => $cats[0] ?? 'Misc', 'confidence' => 0.5],
        'tags' => array_map(fn($t) => ['value' => $t, 'confidence' => 0.5], $tags),
        'description' => ['value' => "$kind image, " . strlen($data) . ' bytes', 'confidence' => 0.5],
    ]);
}
fail(404, 'not_found', 'not found');
