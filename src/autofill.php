<?php
declare(strict_types=1);

/*
 * Apotheke – AI autofill client (optional).
 *
 * Talks to a separate "autofill service" (https://github.com/thordin9/apotheke-autofill-service, API v1: GET /v1/info,
 * POST /v1/suggest). Everything here fails soft: any error, timeout or unexpected answer means "no suggestions".
 * With autofill switched off nothing in this file makes a network request.
 * The bearer token is stored as a 'secret' setting: never rendered, exported, backed up or logged.
 */

const AUTOFILL_CAPABILITIES = ['name', 'category', 'tags', 'description'];
const AUTOFILL_MAX_BODY = 262144;          // bytes we accept from the service
const AUTOFILL_CONNECT_TIMEOUT = 3;        // seconds

function autofill_enabled(): bool
{
    return (bool)setting('autofill_enabled') && (string)setting('autofill_url') !== '';
}

function autofill_token(): string
{
    return (string)setting('autofill_token');
}

/** Log a short warning – never the token, never the response body. */
function autofill_log(string $msg): void
{
    error_log('apotheke autofill: ' . $msg);
}

/**
 * One HTTP request to the service. Never throws.
 * @param array<string,string|CURLStringFile>|null $multipart
 * @return array{status:int,body:string,error:?string} error: unreachable|timeout|unavailable|too_large|null
 */
function autofill_http(string $method, string $url, ?array $multipart, string $token, int $timeout): array
{
    if (!function_exists('curl_init')) return ['status' => 0, 'body' => '', 'error' => 'unavailable'];
    $ch = curl_init($url);
    $body = '';
    $tooLarge = false;
    $headers = ['Accept: application/json', 'Expect:'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => min(AUTOFILL_CONNECT_TIMEOUT, max(1, $timeout)),
        CURLOPT_TIMEOUT => max(1, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > AUTOFILL_MAX_BODY) { $tooLarge = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($multipart !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($tooLarge) return ['status' => $status, 'body' => '', 'error' => 'too_large'];
    if ($ok === false || $errno !== 0) {
        $err = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable';
        return ['status' => 0, 'body' => '', 'error' => $err];
    }
    return ['status' => $status, 'body' => $body, 'error' => null];
}

/** JSON object (assoc array) or null. */
function autofill_json_object(string $body): ?array
{
    try { $o = json_decode($body, false, 16, JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
    if (!$o instanceof stdClass) return null;          // `[]`, strings, numbers, null: not an object
    return json_decode($body, true, 16);
}

/**
 * Validate GET /v1/info. Unknown fields and unknown capabilities are ignored.
 * @return array{name:string,version:string,capabilities:string[],backend:?string,max_image_bytes:?int,ready:bool}|null
 */
function autofill_parse_info(string $body): ?array
{
    $d = autofill_json_object($body);
    if ($d === null) return null;
    if (!is_string($d['name'] ?? null) || trim($d['name']) === '' || !is_string($d['version'] ?? null) || !is_array($d['capabilities'] ?? null)) return null;
    $caps = [];
    foreach ($d['capabilities'] as $c) {
        if (!is_string($c)) return null;
        if (in_array($c, AUTOFILL_CAPABILITIES, true) && !in_array($c, $caps, true)) $caps[] = $c;
    }
    $max = $d['max_image_bytes'] ?? null;
    if ($max !== null && (!is_int($max) || $max < 1)) return null;
    $backend = $d['backend'] ?? null;
    if ($backend !== null && !is_string($backend)) return null;
    $ready = $d['ready'] ?? true;
    if (!is_bool($ready)) return null;
    return ['name' => clean_line($d['name'], 80), 'version' => clean_line($d['version'], 20), 'capabilities' => $caps,
            'backend' => $backend === null ? null : clean_line($backend, 40), 'max_image_bytes' => $max, 'ready' => $ready];
}

/**
 * "Test connection": GET /v1/info with the given address/timeout and the stored token.
 * @return array{ok:bool,code:string,message:string,info?:array}
 */
function autofill_test(string $url, int $timeout, ?string $token = null): array
{
    $url = rtrim(trim($url), '/');
    if ($url === '') return ['ok' => false, 'code' => 'not_configured', 'message' => 'Enter the service address first.'];
    $fail = fn(string $code, string $msg) => ['ok' => false, 'code' => $code, 'message' => $msg];
    // 1. liveness (no token): tells "not reachable" apart from "reachable, but refuses the token"
    $h = autofill_http('GET', $url . '/healthz', null, '', $timeout);
    if ($h['error'] === 'timeout') return $fail('timeout', "No answer within $timeout seconds. Is the service busy or the timeout too short?");
    if ($h['error'] === 'unreachable') return $fail('unreachable', 'Could not connect. Check the address, that the service is running and that this server can reach it.');
    if ($h['error'] === 'unavailable') return $fail('unavailable', 'This PHP has no curl extension, which autofill needs.');
    if ($h['status'] !== 200) return $fail('bad_response', 'The service answered /healthz with HTTP ' . $h['status'] . ' – is this the autofill service (API v1)?');
    // 2. GET /v1/info with the token
    $r = autofill_http('GET', $url . '/v1/info', null, $token ?? autofill_token(), $timeout);
    if ($r['error'] === 'timeout') return $fail('timeout', "No answer within $timeout seconds. Is the service busy or the timeout too short?");
    if ($r['error'] === 'unreachable') return $fail('unreachable', 'Could not connect. Check the address, that the service is running and that this server can reach it.');
    if ($r['error'] === 'unavailable') return $fail('unavailable', 'This PHP has no curl extension, which autofill needs.');
    if ($r['status'] === 401 || $r['status'] === 403) return $fail('unauthorized', 'The service refused the token (401). Set the token the service expects, or clear it if the service has none.');
    if ($r['error'] !== null || $r['status'] !== 200) return $fail('bad_response', 'The service answered with HTTP ' . $r['status'] . ' – is this the autofill service (API v1)?');
    $info = autofill_parse_info($r['body']);
    if ($info === null) return $fail('bad_response', 'The service answered, but not with a valid /v1/info response – is this the autofill service (API v1)?');
    autofill_info_cache($info);
    if (!$info['ready']) return ['ok' => false, 'code' => 'warming_up', 'message' => 'Connected to “' . $info['name'] . '”, but it is still warming up (loading its model). Try again in a minute.', 'info' => $info];
    return ['ok' => true, 'code' => 'ok', 'message' => 'Connected to “' . $info['name'] . '”.', 'info' => $info];
}

/** Normalised service address for comparisons: trimmed, without trailing slashes, scheme and host lower-case. */
function autofill_normalise_url(string $url): string
{
    $url = rtrim(trim($url), '/');
    return (string)preg_replace_callback('#^([a-z][a-z0-9+.-]*://)([^/?\#@]+)#i', fn($m) => strtolower($m[1] . $m[2]), $url);
}

/**
 * Which token Test connection may send to $url: a token typed in the form wins; otherwise the stored token, but
 * only if $url is the saved address (after normalisation); any other address gets no token at all.
 */
function autofill_test_token(string $url, string $typed = ''): string
{
    $typed = trim($typed);
    if ($typed !== '') return $typed;
    $saved = (string)setting('autofill_url');
    if ($saved !== '' && autofill_normalise_url($url) === autofill_normalise_url($saved)) return autofill_token();
    return '';
}

/** Remember the last good /v1/info (max_image_bytes) for an hour; get it with no argument. */
function autofill_info_cache(?array $info = null): ?array
{
    $f = DATA_DIR . '/autofill-info.json';
    if ($info !== null) {
        @file_put_contents($f, json_encode(['at' => time(), 'url' => (string)setting('autofill_url'), 'info' => $info]));
        return $info;
    }
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d) || ($d['url'] ?? '') !== (string)setting('autofill_url') || time() - (int)($d['at'] ?? 0) > 3600) return null;
    return is_array($d['info'] ?? null) ? $d['info'] : null;
}

/* ---------------- suggestions (task 7) ---------------- */

const AUTOFILL_IMAGE_SIDE = 1024;          // long edge of the JPEG we send (the service's recommendation)
const AUTOFILL_IMAGE_QUALITY = 82;
const AUTOFILL_MAX_TAGS = 8;

/**
 * The photo as the service wants it: JPEG, about 1024 px on the long edge, EXIF orientation applied.
 * Anything GD cannot read (HEIC, broken files…) is passed through unchanged, so the service decides (415).
 * @return array{data:string,mime:string,converted:bool}
 */
function autofill_prepare_image(string $bytes): array
{
    $info = @getimagesizefromstring($bytes);
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if (!$info || !in_array($info[2], $types, true) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 60_000_000) {
        return ['data' => $bytes, 'mime' => 'application/octet-stream', 'converted' => false];
    }
    $src = @imagecreatefromstring($bytes);
    if (!$src) return ['data' => $bytes, 'mime' => 'application/octet-stream', 'converted' => false];
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        if (is_array($exif)) $src = apply_orientation($src, (int)($exif['Orientation'] ?? 1));
    }
    $im = image_fit($src, AUTOFILL_IMAGE_SIDE);
    ob_start();
    imagejpeg($im, null, AUTOFILL_IMAGE_QUALITY);
    return ['data' => (string)ob_get_clean(), 'mime' => 'image/jpeg', 'converted' => true];
}

/** What the backend may reuse: existing category and tag spellings, and the UI language. */
function autofill_context(): array
{
    $cats = array_map(fn($c) => (string)$c['name'], db()->query('SELECT name FROM categories ORDER BY name')->fetchAll());
    $tags = array_map(fn($t) => (string)$t['name'], tags_all());
    return ['categories' => array_slice($cats, 0, 200), 'tags' => array_slice($tags, 0, 300), 'locale' => 'en'];
}

/**
 * Validate a /v1/suggest answer. Every field is optional; unknown fields are ignored; a field of the wrong shape is
 * dropped. null: not a JSON object at all (malformed).
 * @return array{name:?string,category:?string,tags:string[],description:?string}|null
 */
function autofill_parse_suggestion(string $body): ?array
{
    $d = autofill_json_object($body);
    if ($d === null) return null;
    $shape = function (mixed $f): bool {
        if (!is_array($f) || !is_string($f['value'] ?? null)) return false;
        $c = $f['confidence'] ?? null;
        return $c === null || ((is_int($c) || is_float($c)) && $c >= 0 && $c <= 1);
    };
    $val = function (mixed $f, int $max) use ($shape): ?string {
        if (!$shape($f)) return null;
        $v = clean_line($f['value'], $max);
        return $v === '' ? null : $v;
    };
    $tags = [];
    if (is_array($d['tags'] ?? null) && array_is_list($d['tags'])) {
        foreach ($d['tags'] as $t) {
            $v = $val($t, 40);
            if ($v !== null && !in_array(mb_strtolower($v), array_map('mb_strtolower', $tags), true)) $tags[] = $v;
            if (count($tags) >= AUTOFILL_MAX_TAGS) break;
        }
    }
    $desc = null;
    if ($shape($d['description'] ?? null)) {
        $desc = trim(mb_substr(str_replace("\r", '', (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $d['description']['value'])), 0, 2000));
        if ($desc === '') $desc = null;
    }
    return ['name' => $val($d['name'] ?? null, 200), 'category' => $val($d['category'] ?? null, 120), 'tags' => $tags, 'description' => $desc];
}

/**
 * Photo → suggestions. Never throws; any problem is "no suggestions" with a short reason for the status line.
 * @return array{ok:bool,code:string,message:string,suggestions?:array}
 */
function autofill_suggest(string $imageBytes): array
{
    $fail = fn(string $code, string $msg) => ['ok' => false, 'code' => $code, 'message' => $msg];
    if (!autofill_enabled()) return $fail('off', 'AI autofill is off.');
    if ($imageBytes === '') return $fail('no_image', 'No photo.');
    $img = autofill_prepare_image($imageBytes);
    $info = autofill_info_cache();
    if ($info !== null && $info['max_image_bytes'] !== null && strlen($img['data']) > $info['max_image_bytes']) {
        return $fail('too_large', 'No suggestions: the photo is larger than the service accepts.');
    }
    $timeout = (int)setting('autofill_timeout');
    $url = rtrim((string)setting('autofill_url'), '/') . '/v1/suggest';
    $parts = [
        'image' => new CURLStringFile($img['data'], $img['converted'] ? 'photo.jpg' : 'photo', $img['mime']),
        'context' => json_encode(autofill_context(), JSON_UNESCAPED_UNICODE),
    ];
    $r = autofill_http('POST', $url, $parts, autofill_token(), $timeout);
    if ($r['error'] === 'timeout') return $fail('timeout', "No suggestions: the service did not answer within $timeout seconds.");
    if ($r['error'] === 'unreachable') { autofill_log('suggest: service unreachable'); return $fail('unreachable', 'No suggestions: the autofill service is not reachable.'); }
    if ($r['error'] === 'unavailable') return $fail('unavailable', 'No suggestions: this server has no curl extension.');
    if ($r['error'] === 'too_large') return $fail('bad_response', 'No suggestions: the service sent an unexpected answer.');
    switch ($r['status']) {
        case 200: break;
        case 401: case 403: autofill_log('suggest: token refused (HTTP ' . $r['status'] . ')'); return $fail('unauthorized', 'No suggestions: the service refused the access token. An admin can fix it in Settings → AI autofill.');
        case 413: return $fail('too_large', 'No suggestions: the photo is larger than the service accepts.');
        case 415: return $fail('unsupported', 'No suggestions: the service cannot read this kind of image.');
        case 503: return $fail('busy', 'No suggestions: the service is busy or not ready. Try again later.');
        case 504: return $fail('timeout', 'No suggestions: the service took too long.');
        default: autofill_log('suggest: HTTP ' . $r['status']); return $fail('bad_response', 'No suggestions: the service answered with HTTP ' . $r['status'] . '.');
    }
    $s = autofill_parse_suggestion($r['body']);
    if ($s === null) { autofill_log('suggest: malformed response'); return $fail('bad_response', 'No suggestions: the service sent an unexpected answer.'); }
    $filled = array_keys(array_filter($s, fn($v) => $v !== null && $v !== []));
    if (!$filled) return ['ok' => true, 'code' => 'empty', 'message' => 'No suggestions for this photo.', 'suggestions' => $s];
    return ['ok' => true, 'code' => 'ok', 'message' => 'Suggestions from the photo', 'suggestions' => $s];
}
