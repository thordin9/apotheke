<?php
declare(strict_types=1);

require_once __DIR__ . '/image-color.php';

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
const AUTOFILL_SERVICE_IMAGE_BYTES = 983040; // 1 MiB − 64 KiB, until /v1/info has cached max_image_bytes
const AUTOFILL_IMAGE_BYTES_MIN = 65536;    // smallest explicit autofill_max_image_bytes
const AUTOFILL_IMAGE_BYTES_MAX = 10485760; // largest explicit autofill_max_image_bytes (10 MiB)
const AUTOFILL_UPLOAD_MAX_SIDE = 1600;     // long edge of the temporary analysis JPEG (env AUTOFILL_UPLOAD_MAX_SIDE)
const AUTOFILL_RETRY_AFTER_MAX = 60;       // one retry only when Retry-After is at most this many seconds
const AUTOFILL_UPLOAD_JPEG_QUALITY = 80;   // then 10 points at a time down to 60; then a shorter long edge

function autofill_enabled(): bool
{
    return (bool)setting('autofill_enabled') && (string)setting('autofill_url') !== '';
}

function autofill_token(): string
{
    return (string)setting('autofill_token');
}

/** Log a short warning. Callers must not pass the token or more than a short snippet of a response body. */
function autofill_log(string $msg): void
{
    error_log('apotheke autofill: ' . $msg);
}

/** At most 80 characters of a response, with any bearer token removed. For the log line only. */
function autofill_log_snippet(string $body): string
{
    $s = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $body) ?? '';
    $token = autofill_token();
    if ($token !== '') $s = str_replace($token, '[redacted]', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, 80);
}

/**
 * One HTTP request to the service. Never throws.
 * $body is a pre-built request body (so its size is known before the call) or null for GET.
 * @return array{status:int,body:string,error:?string,retry_after:?int,content_type:string}
 *         error: unreachable (incl. connect timeout)|timeout|unavailable|too_large|null;
 *         retry_after: the Retry-After header in seconds (delta-seconds or an HTTP date), null when absent or unreadable
 */
function autofill_http(string $method, string $url, ?string $body, string $token, int $timeout, string $contentType = ''): array
{
    $empty = fn(?string $err, int $status = 0, string $respType = ''): array => ['status' => $status, 'body' => '', 'error' => $err, 'retry_after' => null, 'content_type' => $respType];
    if (!function_exists('curl_init')) return $empty('unavailable');
    $ch = curl_init($url);
    $resp = '';
    $tooLarge = false;
    $retryAfter = null;
    $respType = '';
    $headers = ['Accept: application/json', 'Expect:'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null && $contentType !== '') $headers[] = 'Content-Type: ' . $contentType;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => min(AUTOFILL_CONNECT_TIMEOUT, max(1, $timeout)),
        CURLOPT_TIMEOUT => max(1, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retryAfter, &$respType): int {
            if (stripos($line, 'retry-after:') === 0) $retryAfter = autofill_parse_retry_after(substr($line, 12));
            if (stripos($line, 'content-type:') === 0) $respType = trim(substr($line, 13));
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$resp, &$tooLarge): int {
            if (strlen($resp) + strlen($chunk) > AUTOFILL_MAX_BODY) { $tooLarge = true; return 0; }
            $resp .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $connected = (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
    curl_close($ch);
    if ($tooLarge) return $empty('too_large', $status, $respType);
    if ($ok === false || $errno !== 0) {
        // a timeout before the connection was made (the 3 s connect timeout) means "not reachable", not "slow"
        $err = $errno === CURLE_OPERATION_TIMEDOUT && $connected ? 'timeout' : 'unreachable';
        return $empty($err);
    }
    return ['status' => $status, 'body' => $resp, 'error' => null, 'retry_after' => $retryAfter, 'content_type' => $respType];
}

/** Retry-After value → whole seconds from now (0–86400), or null. Accepts delta-seconds and HTTP dates. */
function autofill_parse_retry_after(string $v): ?int
{
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^\d{1,9}$/D', $v)) return min(86400, (int)$v);
    $t = preg_match('/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $v) ? strtotime($v) : false;
    return $t === false ? null : max(0, min(86400, $t - time()));
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
 * avg_latency_ms (optional, API v1.x): the service's recent average time per suggestion; anything but a non-negative
 * integer is ignored (null), never a reason to reject the answer.
 * enforced_image_bytes (optional): a positive integer the service will not accept above. A wrong shape is ignored.
 * @return array{name:string,version:string,capabilities:string[],backend:?string,max_image_bytes:?int,enforced_image_bytes:?int,ready:bool,avg_latency_ms:?int}|null
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
    $enforced = $d['enforced_image_bytes'] ?? null;
    $enforced = is_int($enforced) && $enforced > 0 ? $enforced : null;
    $backend = $d['backend'] ?? null;
    if ($backend !== null && !is_string($backend)) return null;
    $ready = $d['ready'] ?? true;
    if (!is_bool($ready)) return null;
    $lat = $d['avg_latency_ms'] ?? null;
    $lat = is_int($lat) && $lat >= 0 ? min($lat, 3_600_000) : null;
    return ['name' => clean_line($d['name'], 80), 'version' => clean_line($d['version'], 20), 'capabilities' => $caps,
            'backend' => $backend === null ? null : clean_line($backend, 40), 'max_image_bytes' => $max,
            'enforced_image_bytes' => $enforced, 'ready' => $ready,
            'avg_latency_ms' => $lat, 'models' => autofill_parse_models($d['models'] ?? null),
            'image_sizes' => autofill_parse_image_sizes($d['image_sizes'] ?? null)];
}

const AUTOFILL_MAX_MODELS = 50;
const AUTOFILL_SIDE_MIN = 64;              // smallest / largest long edge an admin can pick (px)
const AUTOFILL_SIDE_MAX = 4096;

/**
 * /v1/info "models" (optional, API v1.2): [{id, default?, loaded?, avg_latency_ms?}]. Entries of the wrong shape are
 * skipped, never a reason to reject the answer; duplicates keep the first. null: absent, not a list, or nothing usable
 * (then the Model dropdown is hidden and no model is sent). At most one entry is the default (the first one marked).
 * @return list<array{id:string,default:bool,loaded:?bool,avg_latency_ms:?int}>|null
 */
function autofill_parse_models(mixed $v): ?array
{
    if (!is_array($v) || !array_is_list($v)) return null;
    $out = [];
    $hasDefault = false;
    foreach ($v as $m) {
        if (!is_array($m) || !is_string($m['id'] ?? null)) continue;
        $id = clean_line($m['id'], 120);
        if ($id === '' || isset($out[$id])) continue;
        $def = ($m['default'] ?? false) === true && !$hasDefault;
        $hasDefault = $hasDefault || $def;
        $loaded = $m['loaded'] ?? null;
        $lat = $m['avg_latency_ms'] ?? null;
        $out[$id] = ['id' => $id, 'default' => $def, 'loaded' => is_bool($loaded) ? $loaded : null,
                     'avg_latency_ms' => is_int($lat) && $lat >= 0 ? min($lat, 3_600_000) : null];
        if (count($out) >= AUTOFILL_MAX_MODELS) break;
    }
    return $out ? array_values($out) : null;
}

/**
 * /v1/info "image_sizes" (optional, API v1.2): [{long_edge, recommended?}], long edges AUTOFILL_SIDE_MIN–MAX px.
 * Wrong-shaped entries are skipped; sorted by size, duplicates merged; at most one is recommended. null: none usable.
 * @return list<array{long_edge:int,recommended:bool}>|null
 */
function autofill_parse_image_sizes(mixed $v): ?array
{
    if (!is_array($v) || !array_is_list($v)) return null;
    $out = [];
    $rec = null;
    foreach ($v as $s) {
        $e = is_array($s) ? ($s['long_edge'] ?? null) : null;
        if (!is_int($e) || $e < AUTOFILL_SIDE_MIN || $e > AUTOFILL_SIDE_MAX) continue;
        $out[$e] = true;
        if ($rec === null && ($s['recommended'] ?? false) === true) $rec = $e;
        if (count($out) >= 20) break;
    }
    if (!$out) return null;
    ksort($out);
    return array_map(fn(int $e) => ['long_edge' => $e, 'recommended' => $e === $rec], array_keys($out));
}

/** The service's default model id from its info (the one marked default, else the first listed), or null. */
function autofill_default_model(?array $info): ?string
{
    $models = $info['models'] ?? null;
    if (!$models) return null;
    foreach ($models as $m) if ($m['default']) return $m['id'];
    return $models[0]['id'];
}

/**
 * The model to ask for (context.model): the admin's choice, but only when the service lists models (API v1.2). An
 * older service, or no choice ('' = the service's default), sends nothing, exactly as before.
 */
function autofill_chosen_model(?array $info, ?string $choice = null): ?string
{
    $choice = trim($choice ?? (string)setting('autofill_model'));
    return $choice !== '' && !empty($info['models']) ? $choice : null;
}

/**
 * Long edge of the image sent to the service. Without image_sizes in /v1/info: AUTOFILL_IMAGE_SIDE (1024, as before),
 * whatever is stored. With them: the admin's choice (0 = the size the service recommends; else the largest listed
 * size up to 1024, else the smallest).
 */
function autofill_image_side(?array $info, ?int $choice = null): int
{
    $sizes = $info['image_sizes'] ?? null;
    if (!$sizes) return AUTOFILL_IMAGE_SIDE;
    $choice ??= (int)setting('autofill_image_size');
    if ($choice > 0) return max(AUTOFILL_SIDE_MIN, min(AUTOFILL_SIDE_MAX, $choice));
    return autofill_recommended_side($sizes);
}

/** @param list<array{long_edge:int,recommended:bool}> $sizes */
function autofill_recommended_side(array $sizes): int
{
    foreach ($sizes as $s) if ($s['recommended']) return $s['long_edge'];
    $fit = array_filter(array_column($sizes, 'long_edge'), fn(int $e) => $e <= AUTOFILL_IMAGE_SIDE);
    return $fit ? max($fit) : $sizes[0]['long_edge'];
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
    $token ??= autofill_token();
    $r = autofill_http('GET', $url . '/v1/info', null, $token, $timeout);
    if ($r['error'] === 'timeout') return $fail('timeout', "No answer within $timeout seconds. Is the service busy or the timeout too short?");
    if ($r['error'] === 'unreachable') return $fail('unreachable', 'Could not connect. Check the address, that the service is running and that this server can reach it.');
    if ($r['error'] === 'unavailable') return $fail('unavailable', 'This PHP has no curl extension, which autofill needs.');
    if ($r['status'] === 401 || $r['status'] === 403) {
        return $token === ''
            ? $fail('unauthorized', 'The service requires a token (401). Set the access token the service expects below.')
            : $fail('unauthorized', 'The service refused the token (401). Set the token the service expects, or clear it if the service has none.');
    }
    if ($r['error'] !== null || $r['status'] !== 200) return $fail('bad_response', 'The service answered with HTTP ' . $r['status'] . ' – is this the autofill service (API v1)?');
    $info = autofill_parse_info($r['body']);
    if ($info === null) return $fail('bad_response', 'The service answered, but not with a valid /v1/info response – is this the autofill service (API v1)?');
    // only the saved service's info may drive the item form (estimate, size limit): an unsaved address is just a test
    if (autofill_is_saved_url($url)) autofill_info_cache($info);
    if (!$info['ready']) return ['ok' => false, 'code' => 'warming_up', 'message' => 'Connected to “' . $info['name'] . '”, but it is still warming up (loading its model). Try again in a minute.', 'info' => $info];
    return ['ok' => true, 'code' => 'ok', 'message' => 'Connected to “' . $info['name'] . '”.', 'info' => $info];
}

/** Bytes for the image limit: 10485760 → "10 MiB", 983040 → "0.9 MiB", 1000 → "1 KB" (a 1-decimal MiB of 0 would hide it). */
function autofill_mib(int $bytes): string
{
    $mib = $bytes / 1048576;
    if ($bytes > 0 && $mib < 0.05) {
        $kb = $bytes / 1024;
        $decimals = $kb >= 0.05 ? 1 : 2;
        return rtrim(rtrim(number_format($kb, $decimals, '.', ''), '0'), '.') . ' KB';
    }
    return rtrim(rtrim(number_format($mib, 1, '.', ''), '0'), '.') . ' MiB';
}

/**
 * Byte cap before enforced_image_bytes. "auto" (the setting default; there is no env override) uses max_image_bytes
 * from the cached /v1/info, or 983040 when that field has not been seen.
 */
function autofill_configured_image_bytes(): int
{
    $raw = setting('autofill_max_image_bytes');
    if ($raw !== 'auto') return (int)$raw;
    foreach ([autofill_info_cache(), autofill_known_info()] as $info) {
        $n = is_array($info) ? ($info['max_image_bytes'] ?? null) : null;
        if (is_int($n) && $n > 0) return $n;
    }
    return AUTOFILL_SERVICE_IMAGE_BYTES;
}

/** Positive enforced_image_bytes from the cached /v1/info, or null. */
function autofill_enforced_image_bytes(): ?int
{
    foreach ([autofill_info_cache(), autofill_known_info()] as $info) {
        $n = is_array($info) ? ($info['enforced_image_bytes'] ?? null) : null;
        if (is_int($n) && $n > 0) return $n;
    }
    return null;
}

/**
 * Positive max_image_bytes from the cached /v1/info, or null.
 */
function autofill_advertised_image_bytes(): ?int
{
    foreach ([autofill_info_cache(), autofill_known_info()] as $info) {
        $n = is_array($info) ? ($info['max_image_bytes'] ?? null) : null;
        if (is_int($n) && $n > 0) return $n;
    }
    return null;
}

/**
 * The byte cap for the analysis copy. An explicit setting is capped to enforced_image_bytes when the service
 * sent one, otherwise to max_image_bytes, and used as stored only when neither is known. auto already follows
 * max_image_bytes, then the same enforced clamp. $warn logs when that cap is lower, once per distinct pair.
 * The line is the two sizes and which field won: never the token.
 */
function autofill_target_image_bytes(bool $warn = false): int
{
    $target = autofill_configured_image_bytes();
    $enforced = autofill_enforced_image_bytes();
    $cap = $enforced;
    $why = 'enforced_image_bytes';
    if ($cap === null && setting('autofill_max_image_bytes') !== 'auto') {
        $cap = autofill_advertised_image_bytes();
        $why = 'max_image_bytes';
    }
    if ($cap !== null && $cap < $target) {
        if ($warn) autofill_log_image_clamp($target, $cap, $why);
        return $cap;
    }
    return $target;
}

/**
 * Log one clamp. The same from/to pair is not logged again, including when a 413 retry rebuilds the copy.
 * The remembered pair lives in the existing info-cache file and is kept when that file is refreshed for the
 * same address. A different pair, or a different address, logs again. The line is the two sizes and the
 * field name: never the token. No request-static state.
 */
function autofill_log_image_clamp(int $target, int $cap, string $why): void
{
    $f = DATA_DIR . '/autofill-info.json';
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d)) $d = [];
    $prev = $d['clamp'] ?? null;
    if (is_array($prev) && ($prev['from'] ?? null) === $target && ($prev['to'] ?? null) === $cap && ($prev['field'] ?? null) === $why) return;
    $d['clamp'] = ['from' => $target, 'to' => $cap, 'field' => $why];
    @file_put_contents($f, json_encode($d));
    autofill_log('image limit clamped from ' . $target . ' to ' . $cap . ' bytes (' . $why . ')');
}

/** The image limit that applies now (explicit or auto, then the service cap). No log line. */
function autofill_service_image_limit(): int
{
    return autofill_target_image_bytes(false);
}

/** Status line when a photo is rejected as too large. $bytes is the limit that applies. */
function autofill_too_large_message(int $bytes): string
{
    return 'No suggestions: the photo is too large (max ' . autofill_mib($bytes) . ' / ' . $bytes . ' bytes).';
}

/** Normalised service address for comparisons: trimmed, without trailing slashes, scheme and host lower-case. */
function autofill_normalise_url(string $url): string
{
    $url = rtrim(trim($url), '/');
    return (string)preg_replace_callback('#^([a-z][a-z0-9+.-]*://)([^/?\#@]+)#i', fn($m) => strtolower($m[1] . $m[2]), $url);
}

/** Is $url the saved service address (same normalisation as autofill_test_token)? */
function autofill_is_saved_url(string $url): bool
{
    $saved = (string)setting('autofill_url');
    return $saved !== '' && autofill_normalise_url($url) === autofill_normalise_url($saved);
}

/**
 * Which token Test connection may send to $url: a token typed in the form wins; otherwise the stored token, but
 * only if $url is the saved address (after normalisation); any other address gets no token at all.
 */
function autofill_test_token(string $url, string $typed = ''): string
{
    $typed = trim($typed);
    if ($typed !== '') return $typed;
    return autofill_is_saved_url($url) ? autofill_token() : '';
}

/**
 * After the saved address changed from $oldUrl (normalised comparison, the same rule as autofill_test_token), clear the
 * stored token: it was meant for the old service. Returns true when a token was actually cleared.
 */
function autofill_drop_token_if_moved(string $oldUrl): bool
{
    if (autofill_token() === '') return false;
    if (autofill_normalise_url($oldUrl) === autofill_normalise_url((string)setting('autofill_url'))) return false;
    settings_store(['autofill_token' => '']);
    autofill_log('token cleared: the service address changed');
    return true;
}

/** Remember the last good /v1/info (max_image_bytes, avg_latency_ms) for an hour ($maxAge); get it with no argument. */
function autofill_info_cache(?array $info = null, int $maxAge = 3600): ?array
{
    $f = DATA_DIR . '/autofill-info.json';
    if ($info !== null) {
        $prev = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        $row = ['at' => time(), 'url' => (string)setting('autofill_url'), 'info' => $info];
        if (is_array($prev) && ($prev['url'] ?? '') === $row['url'] && is_array($prev['clamp'] ?? null)) $row['clamp'] = $prev['clamp'];
        @file_put_contents($f, json_encode($row));
        return $info;
    }
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d) || ($d['url'] ?? '') !== (string)setting('autofill_url') || time() - (int)($d['at'] ?? 0) > $maxAge) return null;
    return is_array($d['info'] ?? null) ? $d['info'] : null;
}

/**
 * The saved service's last /v1/info, however old (null when there is none, or it belongs to another address). Used for
 * what the service offers (models, image sizes): an expired copy still says which models and sizes exist, so a chosen
 * model is not silently dropped an hour after the last Test connection.
 */
function autofill_known_info(): ?array
{
    return autofill_info_cache(null, PHP_INT_MAX);
}

/* ---------------- suggestions (task 7) ---------------- */

const AUTOFILL_IMAGE_SIDE = 1024;          // long edge when the service lists no image_sizes (unchanged from 2.3)
const AUTOFILL_IMAGE_QUALITY = 82;         // used by autofill_prepare_image(); the analysis copy uses AUTOFILL_UPLOAD_JPEG_QUALITY
const AUTOFILL_MAX_TAGS = 8;

/** Long edge for a new analysis copy: AUTOFILL_UPLOAD_MAX_SIDE, or AUTOFILL_UPLOAD_MAX_SIDE from the environment. */
function autofill_upload_max_side(): int
{
    $v = getenv('AUTOFILL_UPLOAD_MAX_SIDE');
    if ($v !== false && preg_match('/^\d{1,5}$/D', (string)$v)) {
        $n = (int)$v;
        if ($n >= AUTOFILL_SIDE_MIN && $n <= AUTOFILL_SIDE_MAX) return $n;
    }
    return AUTOFILL_UPLOAD_MAX_SIDE;
}

/** JPEG quality for a new analysis copy: 80, or AUTOFILL_UPLOAD_JPEG_QUALITY from the environment (40–95). */
function autofill_upload_jpeg_quality(): int
{
    $v = getenv('AUTOFILL_UPLOAD_JPEG_QUALITY');
    if ($v !== false && preg_match('/^\d{1,3}$/D', (string)$v)) {
        $n = (int)$v;
        if ($n >= 40 && $n <= 95) return $n;
    }
    return AUTOFILL_UPLOAD_JPEG_QUALITY;
}

/** Quality to try, highest first: the configured one, then 10 points lower, down through 60. Below 60, only the configured quality. */
function autofill_jpeg_qualities(): array
{
    $q = autofill_upload_jpeg_quality();
    $out = [];
    for ($n = $q; $n >= 60; $n -= 10) $out[] = $n;
    if ($out === []) return [$q];
    if ($out[array_key_last($out)] !== 60) $out[] = 60;
    return $out;
}

/** Imagick in this PHP can decode HEIC/HEIF (libheif). Checked at call time. */
function autofill_heic_supported(): bool
{
    if (!class_exists('Imagick')) return false;
    try {
        if (Imagick::queryFormats('HEIC')) return true;
        return (bool)Imagick::queryFormats('HEIF');
    } catch (Throwable) {
        return false;
    }
}

/**
 * Which libraries may build the analysis copy. HEIC never uses GD. Anything else tries GD, then Imagick.
 * @return list<string>
 */
function autofill_pick_engines(bool $heic, bool $gd, bool $imagick, bool $heicOk): array
{
    if ($heic) return $imagick && $heicOk ? ['imagick'] : [];
    $out = [];
    if ($gd) $out[] = 'gd';
    if ($imagick) $out[] = 'imagick';
    return $out;
}

/** @return list<string> */
function autofill_image_engines(string $bytes): array
{
    return autofill_pick_engines(
        autofill_is_heic($bytes),
        function_exists('imagecreatefromstring') && function_exists('imagejpeg'),
        class_exists('Imagick'),
        autofill_heic_supported()
    );
}

/**
 * A metadata-stripped JPEG for the service. Long side at most $maxSide (never enlarged), EXIF orientation applied
 * first. Null when no listed engine can read the bytes — the original is never returned.
 * $engines overrides runtime detection (tests). Default $maxSide is the upload cap (1600), not the model size.
 * @param list<string>|null $engines
 */
function autofill_analysis_jpeg(string $bytes, ?int $maxSide = null, ?int $quality = null, ?array $engines = null, ?string $exifPath = null): ?string
{
    $maxSide ??= autofill_upload_max_side();
    $quality ??= autofill_upload_jpeg_quality();
    $engines ??= autofill_image_engines($bytes);
    foreach ($engines as $engine) {
        $out = match ($engine) {
            'gd' => autofill_analysis_gd($bytes, $maxSide, $quality, $exifPath),
            'imagick' => autofill_analysis_imagick($bytes, $maxSide, $quality),
            default => null,
        };
        if (is_string($out) && str_starts_with($out, "\xFF\xD8")) return $out;
    }
    return null;
}

function autofill_analysis_gd(string $bytes, int $maxSide, int $quality, ?string $exifPath): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
    $info = @getimagesizefromstring($bytes);
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if (!$info || !in_array($info[2], $types, true) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 60_000_000) return null;
    $src = @imagecreatefromstring($bytes);
    if (!$src) return null;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = $exifPath !== null && is_file($exifPath) ? @exif_read_data($exifPath) : false;
        if (!is_array($exif)) $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        if (is_array($exif)) $src = apply_orientation($src, (int)($exif['Orientation'] ?? 1));
    }
    $im = image_fit($src, max(1, $maxSide));
    ob_start();
    $ok = imagejpeg($im, null, max(1, min(100, $quality)));
    $out = (string)ob_get_clean();
    return $ok && str_starts_with($out, "\xFF\xD8") ? $out : null;
}

function autofill_analysis_imagick(string $bytes, int $maxSide, int $quality): ?string
{
    if (!class_exists('Imagick') || $bytes === '') return null;
    try {
        $im = new Imagick();
        $im->readImageBlob($bytes);
        if (method_exists($im, 'setFirstIterator')) $im->setFirstIterator();
        if (method_exists($im, 'autoOrient')) $im->autoOrient();
        elseif (method_exists($im, 'autoOrientImage')) $im->autoOrientImage();
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        if ($w < 1 || $h < 1 || $w * $h > 60_000_000) { $im->clear(); return null; }
        imagick_match_gd($im);
        $long = max($w, $h);
        if ($long > $maxSide) {
            $s = $maxSide / $long;
            $nw = max(1, (int)round($w * $s));
            $nh = max(1, (int)round($h * $s));
            $filter = defined('Imagick::FILTER_LANCZOS') ? constant('Imagick::FILTER_LANCZOS') : 1;
            $im->resizeImage($nw, $nh, $filter, 1);
        }
        $im->setImageFormat('jpeg');
        // 4:2:0 only on this analysis copy. A 4:4:4 source otherwise keeps its sampling and overshoots the size budget.
        // setSamplingFactors() only accepts floats, so it cannot spell 2x2 and is not used.
        // image_store_heic() does not set this: GD re-encodes that JPEG, and a forced 4:2:0 softens it at quality 90+.
        $im->setOption('jpeg:sampling-factor', '2x2,1x1,1x1');
        if (method_exists($im, 'setImageProperty')) $im->setImageProperty('jpeg:sampling-factor', '2x2,1x1,1x1');
        $im->setImageCompressionQuality(max(1, min(100, $quality)));
        $im->stripImage();
        $out = $im->getImageBlob();
        $im->clear();
        return is_string($out) && str_starts_with($out, "\xFF\xD8") ? $out : null;
    } catch (Throwable) {
        return null;
    }
}

/** multipart/form-data for POST /v1/suggest. The returned body is what curl will send, headers included. */
function autofill_multipart_body(string $jpeg, string $contextJson): array
{
    $boundary = 'ApothekeForm' . bin2hex(random_bytes(8));
    $body = "--$boundary\r\n"
        . "Content-Disposition: form-data; name=\"image\"; filename=\"photo.jpg\"\r\n"
        . "Content-Type: image/jpeg\r\n\r\n"
        . $jpeg . "\r\n"
        . "--$boundary\r\n"
        . "Content-Disposition: form-data; name=\"context\"\r\n"
        . "Content-Type: application/json\r\n\r\n"
        . $contextJson . "\r\n"
        . "--$boundary--\r\n";
    return ['body' => $body, 'content_type' => 'multipart/form-data; boundary=' . $boundary];
}

function autofill_temp_jpg(): string
{
    $dir = DATA_DIR . '/tmp';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('temp');
    return $dir . '/autofill-' . bin2hex(random_bytes(8)) . '.jpg';
}

/** Delete only an analysis copy this process created under DATA_DIR/tmp. Never a stored photo. */
function autofill_unlink_analysis(?string $path): void
{
    if ($path === null || $path === '') return;
    $base = basename($path);
    if (!preg_match('/^autofill-[0-9a-f]{16}\.jpg$/D', $base)) return;
    if ($path !== DATA_DIR . '/tmp/' . $base) return;
    if (is_file($path)) @unlink($path);
}

/**
 * Build the analysis JPEG and the full multipart body. Writes the JPEG under DATA_DIR/tmp when it fits;
 * the caller deletes that path. The original bytes are never written and never returned.
 * Quality steps down by 10 from the configured quality, and 60 is tried before the long edge shortens,
 * until the JPEG fits the byte cap. If nothing fits, the result is too_large.
 * @param list<string>|null $engines
 * @return array{ok:true,path:string,body:string,content_type:string,jpeg:string,width:int,height:int}|array{ok:false,code:string,message:string}
 */
function autofill_open_analysis(string $bytes, int $side, string $contextJson, ?array $engines = null, ?string $exifPath = null): array
{
    $fail = fn(string $code, string $msg): array => ['ok' => false, 'code' => $code, 'message' => $msg];
    if ($bytes === '') return $fail('no_image', 'No photo.');
    $engines ??= autofill_image_engines($bytes);
    if ($engines === []) {
        autofill_log('suggest: skipped, no image library for this photo');
        return $fail('unsupported', 'No suggestions: this kind of file cannot be read as a photo.');
    }
    $limit = autofill_target_image_bytes(true);
    $saw = false;
    foreach (autofill_analysis_sides($side) as $trySide) {
        foreach (autofill_jpeg_qualities() as $q) {
            $jpeg = autofill_analysis_jpeg($bytes, $trySide, $q, $engines, $exifPath);
            if ($jpeg === null) {
                if ($saw) break 2;
                autofill_log('suggest: skipped, could not build a stripped JPEG');
                return $fail('unsupported', 'No suggestions: this kind of file cannot be read as a photo.');
            }
            $saw = true;
            $part = autofill_multipart_body($jpeg, $contextJson);
            // The service limits the image part. The multipart wrapper is about 320 bytes and is not counted.
            if (strlen($jpeg) <= $limit) {
                try { $path = autofill_temp_jpg(); } catch (Throwable) { return $fail('unavailable', autofill_unavailable_message()); }
                if (file_put_contents($path, $jpeg) === false) {
                    autofill_unlink_analysis($path);
                    return $fail('unavailable', autofill_unavailable_message());
                }
                $info = @getimagesizefromstring($jpeg);
                return ['ok' => true, 'path' => $path, 'body' => $part['body'], 'content_type' => $part['content_type'],
                    'jpeg' => $jpeg, 'width' => (int)($info[0] ?? 0), 'height' => (int)($info[1] ?? 0)];
            }
        }
    }
    autofill_log('suggest: analysis copy over the image limit');
    return $fail('too_large', autofill_too_large_message($limit));
}

/** Long edges to try, largest first: $side, then four fifths, and never below AUTOFILL_SIDE_MIN. */
function autofill_analysis_sides(int $side): array
{
    $n = max(1, $side);
    $out = [];
    while (count($out) < 24) {
        $out[] = $n;
        if ($n <= AUTOFILL_SIDE_MIN) break;
        $next = (int)floor($n * 4 / 5);
        if ($next > $n - 1) $next = $n - 1;
        if ($next < AUTOFILL_SIDE_MIN) $next = AUTOFILL_SIDE_MIN;
        $n = $next;
    }
    return $out;
}

function autofill_unavailable_message(): string
{
    return 'No suggestions: autofill is unavailable right now. You can save the item and fill it in yourself.';
}

function autofill_content_type_is_json(string $contentType): bool
{
    $ct = strtolower(trim(explode(';', $contentType, 2)[0]));
    return $ct === 'application/json' || str_ends_with($ct, '+json');
}

/**
 * A /v1 error object, or null when the body is not that shape (HTML, truncated JSON, a bare string error).
 * An empty Content-Type still counts: some answers omit the header. text/html never does.
 * @return array{code:?string,message:string}|null
 */
function autofill_api_error(string $contentType, string $body): ?array
{
    $ct = strtolower(trim(explode(';', $contentType, 2)[0]));
    if ($ct !== '' && !autofill_content_type_is_json($ct)) return null;
    $d = autofill_json_object($body);
    if ($d === null) return null;
    $err = $d['error'] ?? null;
    if ($err === null) return ['code' => null, 'message' => ''];
    if (!is_array($err) || array_is_list($err)) return null;
    $code = $err['code'] ?? null;
    if ($code !== null && !is_string($code)) return null;
    $msg = $err['message'] ?? null;
    return ['code' => is_string($code) ? $code : null, 'message' => is_string($msg) ? $msg : ''];
}

/**
 * The photo as the service wants it: about $side px on the long edge (never enlarged), EXIF orientation applied.
 * A JPEG normally; a PNG when the photo has transparency (PNG with an alpha channel or tRNS, WebP with alpha, GIF with
 * a transparent colour), so the alpha survives instead of being flattened onto white.
 * HEIC/HEIF (which GD cannot read but the service may) is passed through unchanged; anything else GD cannot read
 * (PDFs, broken or huge files…) is null: not sent at all.
 * @return array{data:string,mime:string,converted:bool,width:?int,height:?int}|null
 */
function autofill_prepare_image(string $bytes, int $side = AUTOFILL_IMAGE_SIDE): ?array
{
    $pass = autofill_is_heic($bytes) ? ['data' => $bytes, 'mime' => 'image/heic', 'converted' => false, 'width' => null, 'height' => null] : null;
    $info = @getimagesizefromstring($bytes);
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if (!$info || !in_array($info[2], $types, true) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 60_000_000) return $pass;
    $src = @imagecreatefromstring($bytes);
    if (!$src) return $pass;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        if (is_array($exif)) $src = apply_orientation($src, (int)($exif['Orientation'] ?? 1));
    }
    $side = max(AUTOFILL_SIDE_MIN, min(AUTOFILL_SIDE_MAX, $side));
    ob_start();
    if (autofill_has_alpha($bytes, $info[2], $src)) {
        $im = autofill_fit_alpha($src, $side);
        imagepng($im, null, 6);
        $mime = 'image/png';
    } else {
        $im = image_fit($src, $side);
        imagejpeg($im, null, AUTOFILL_IMAGE_QUALITY);
        $mime = 'image/jpeg';
    }
    return ['data' => (string)ob_get_clean(), 'mime' => $mime, 'converted' => true, 'width' => imagesx($im), 'height' => imagesy($im)];
}

/** Does the image carry transparency? PNG: colour type 4/6 or a tRNS chunk; WebP: the VP8X alpha flag or lossless
 *  alpha hint; GIF: a transparent colour index. */
function autofill_has_alpha(string $bytes, int $type, GdImage $src): bool
{
    if ($type === IMAGETYPE_PNG) {
        $ct = strlen($bytes) > 25 ? ord($bytes[25]) : 0;
        if ($ct === 4 || $ct === 6) return true;
        $idat = strpos($bytes, 'IDAT');
        $trns = strpos($bytes, 'tRNS');
        return $trns !== false && ($idat === false || $trns < $idat);
    }
    if ($type === IMAGETYPE_WEBP) {
        $chunk = substr($bytes, 12, 4);
        if ($chunk === 'VP8X') return (ord($bytes[20] ?? "\0") & 0x10) !== 0;
        if ($chunk === 'VP8L') return (ord($bytes[24] ?? "\0") & 0x10) !== 0;
        return false;
    }
    if ($type === IMAGETYPE_GIF) return imagecolortransparent($src) >= 0;
    return false;
}

/** image_fit() that keeps the alpha channel (transparent canvas instead of white). Never enlarges. */
function autofill_fit_alpha(GdImage $src, int $max): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $s = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $s));
    $nh = max(1, (int)round($h * $s));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    if (!imageistruecolor($src)) imagepalettetotruecolor($src);
    imagealphablending($src, false);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/** HEIC/HEIF container (ISO BMFF "ftyp" box with a HEIF brand)? */
function autofill_is_heic(string $bytes): bool
{
    return strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp'
        && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true);
}

/** What the backend may reuse: existing category and tag spellings, and the UI language. */
function autofill_context(?string $model = null): array
{
    $cats = array_map(fn($c) => (string)$c['name'], db()->query('SELECT name FROM categories ORDER BY name')->fetchAll());
    $tags = array_map(fn($t) => (string)$t['name'], tags_all());
    $ctx = ['categories' => array_slice($cats, 0, 200), 'tags' => array_slice($tags, 0, 300), 'locale' => 'en'];
    if ($model !== null && $model !== '') $ctx['model'] = $model;   // API v1.2; only when the service lists models
    return $ctx;
}

/** model_used from a /v1/suggest answer (API v1.2, optional): a short string, else null. */
function autofill_parse_model_used(string $body): ?string
{
    $v = (autofill_json_object($body) ?? [])['model_used'] ?? null;
    if (!is_string($v)) return null;
    $v = clean_line($v, 120);
    return $v === '' ? null : $v;
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
 * Why a 503 with Retry-After asks us to wait, from the service's error message: 'warming' for a cold model
 * ("model 'x' is loading", "… is warming up"), otherwise 'busy' (e.g. "backend busy, queue full").
 */
function autofill_retry_reason(string $message): string
{
    return preg_match('/\b(loading|warming|warm-up|warmup|starting)\b/i', $message) ? 'warming' : 'busy';
}

/** Per-field confidence (0–1) from a /v1/suggest answer, for the Control Panel's photo test; null where absent. */
function autofill_parse_confidence(string $body): array
{
    $d = autofill_json_object($body) ?? [];
    $c = function (mixed $f): ?float {
        $v = is_array($f) ? ($f['confidence'] ?? null) : null;
        return (is_int($v) || is_float($v)) && $v >= 0 && $v <= 1 ? round((float)$v, 2) : null;
    };
    return ['name' => $c($d['name'] ?? null), 'category' => $c($d['category'] ?? null), 'description' => $c($d['description'] ?? null)];
}

/**
 * Photo bytes → suggestions. Copies the bytes to a temp file and calls autofill_suggest_path; never rewrites a stored
 * photo. Never throws. $engines overrides library detection (tests).
 * @param list<string>|null $engines
 * @return array{ok:bool,code:string,message:string,suggestions?:array,retry_after?:int,avg_latency_ms:?int,model_used?:?string,image?:array}
 */
function autofill_suggest(string $imageBytes, ?array $engines = null): array
{
    $fail = fn(string $code, string $msg) => ['ok' => false, 'code' => $code, 'message' => $msg, 'avg_latency_ms' => autofill_expected_ms()];
    if (!autofill_enabled()) return ['ok' => false, 'code' => 'off', 'message' => 'AI autofill is off.'];
    if ($imageBytes === '') return $fail('no_image', 'No photo.');
    $src = null;
    try {
        $dir = DATA_DIR . '/tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return $fail('unavailable', autofill_unavailable_message());
        $src = $dir . '/autofill-src-' . bin2hex(random_bytes(8));
        if (file_put_contents($src, $imageBytes) === false) return $fail('unavailable', autofill_unavailable_message());
        return autofill_suggest_path($src, $engines);
    } catch (Throwable) {
        return $fail('unavailable', autofill_unavailable_message());
    } finally {
        if (is_string($src) && str_starts_with($src, DATA_DIR . '/tmp/autofill-src-') && is_file($src)) @unlink($src);
    }
}

/**
 * Suggestions from a file on disk. Reads $source and never writes it back: the analysis JPEG is a different temp file,
 * deleted before this returns (including on errors). $engines overrides library detection.
 * @param list<string>|null $engines
 * @return array{ok:bool,code:string,message:string,suggestions?:array,retry_after?:int,avg_latency_ms:?int,model_used?:?string,image?:array}
 */
function autofill_suggest_path(string $source, ?array $engines = null): array
{
    $fail = fn(string $code, string $msg) => ['ok' => false, 'code' => $code, 'message' => $msg, 'avg_latency_ms' => autofill_expected_ms()];
    if (!autofill_enabled()) return ['ok' => false, 'code' => 'off', 'message' => 'AI autofill is off.'];
    if (!is_file($source)) return $fail('no_image', 'No photo.');
    $bytes = (string)@file_get_contents($source);
    if ($bytes === '') return $fail('no_image', 'No photo.');
    $known = autofill_known_info();
    $side = min(autofill_image_side($known), autofill_upload_max_side());
    $ctx = json_encode(autofill_context(autofill_chosen_model($known)), JSON_UNESCAPED_UNICODE);
    $analysis = null;
    try {
        $contextJson = $ctx === false ? '{}' : $ctx;
        $opened = autofill_open_analysis($bytes, $side, $contextJson, $engines, $source);
        if (!$opened['ok']) return $fail($opened['code'], $opened['message']);
        $analysis = $opened['path'];
        $base = rtrim((string)setting('autofill_url'), '/');
        $timeout = (int)setting('autofill_timeout');
        $token = autofill_token();
        [$r, $opened] = autofill_post_analysis($base, $token, $timeout, $opened, fn() => autofill_open_analysis($bytes, $side, $contextJson, $engines, $source));
        $analysis = is_string($opened['path'] ?? null) ? $opened['path'] : $analysis;
        $sent = ['type' => 'image/jpeg', 'bytes' => strlen((string)($opened['jpeg'] ?? '')), 'width' => (int)($opened['width'] ?? 0), 'height' => (int)($opened['height'] ?? 0)];
        // the service answered: refresh its /v1/info (image limit, avg_latency_ms) when the copy is older than 10 minutes
        if ($r['ok'] && autofill_info_cache(null, 600) === null) autofill_refresh_info($base, $token);
        unset($r['confidence'], $r['elapsed_ms']);
        return $r + ['avg_latency_ms' => autofill_expected_ms(), 'image' => $sent];
    } catch (Throwable $e) {
        autofill_log('suggest: failed (' . $e::class . ')');
        return $fail('unavailable', autofill_unavailable_message());
    } finally {
        autofill_unlink_analysis(is_string($analysis) ? $analysis : null);
    }
}

/**
 * The expected time per suggestion from the cached /v1/info, or null (unknown: the browser shows a spinner). With a
 * models list: the chosen model's own avg_latency_ms (the default model's when none is chosen), else the service-wide one.
 */
function autofill_expected_ms(): ?int
{
    $i = autofill_info_cache();
    if ($i === null) return null;
    $want = autofill_chosen_model($i) ?? autofill_default_model($i);
    foreach ($i['models'] ?? [] as $m) {
        if ($m['id'] === $want && $m['avg_latency_ms'] !== null) return $m['avg_latency_ms'];
    }
    $v = $i['avg_latency_ms'] ?? null;
    return is_int($v) && $v >= 0 ? $v : null;
}

/** GET /v1/info ($timeout s at most) and cache it; quietly does nothing when that fails. True when cached. */
function autofill_refresh_info(string $base, string $token, int $timeout = 3): bool
{
    $r = autofill_http('GET', rtrim($base, '/') . '/v1/info', null, $token, $timeout);
    if ($r['error'] !== null || $r['status'] !== 200) return false;
    $info = autofill_parse_info($r['body']);
    if ($info === null) return false;
    autofill_info_cache($info);
    return true;
}

/**
 * After the autofill section was saved: fetch the saved service's /v1/info once, so the first photo already gets an
 * estimate (and the size limit) without a Test click. Bounded by min(timeout, 5 s); failures are ignored.
 */
function autofill_prime_info(): bool
{
    if (!autofill_enabled()) return false;
    return autofill_refresh_info((string)setting('autofill_url'), autofill_token(), min(5, max(1, (int)setting('autofill_timeout'))));
}

/**
 * POST /v1/suggest. On HTTP 413, refetch /v1/info once, rebuild, and POST that copy once. A second 413 is returned
 * as-is. Never loops, and the log line does not include the token.
 * @param callable():array $rebuild
 * @return array{0:array,1:array}
 */
function autofill_post_analysis(string $base, string $token, int $timeout, array $opened, callable $rebuild): array
{
    $r = autofill_suggest_at($base, $token, $timeout, $opened['body'], $opened['content_type'], strlen((string)($opened['jpeg'] ?? '')));
    if (empty($r['http_413'])) {
        unset($r['http_413']);
        return [$r, $opened];
    }
    unset($r['http_413']);
    autofill_log('suggest: HTTP 413, refetching image limit');
    autofill_refresh_info($base, $token, min(5, max(1, $timeout)));
    $next = $rebuild();
    if (empty($next['ok'])) {
        return [['ok' => false, 'code' => $next['code'], 'message' => $next['message'], 'elapsed_ms' => (int)($r['elapsed_ms'] ?? 0)], $opened];
    }
    if (($next['path'] ?? '') !== ($opened['path'] ?? '')) autofill_unlink_analysis(is_string($opened['path'] ?? null) ? $opened['path'] : null);
    $r = autofill_suggest_at($base, $token, $timeout, $next['body'], $next['content_type'], strlen((string)($next['jpeg'] ?? '')));
    unset($r['http_413']);
    return [$r, $next];
}

/**
 * One POST /v1/suggest to $base. $imageBytes is the JPEG the service limits (the multipart wrapper is not counted).
 * Never throws, never retries.
 * A transport failure, a non-JSON body, truncated JSON, an HTML error page or a 5xx that is not the API's own
 * 503 shape is "autofill unavailable" with no retry_after. A JSON 503 backend_unavailable / backend_error is unchanged.
 * @return array{ok:bool,code:string,message:string,suggestions?:array,confidence?:array,retry_after?:int,elapsed_ms:int}
 */
function autofill_suggest_at(string $base, string $token, int $timeout, string $body, string $contentType, int $imageBytes = 0): array
{
    $t0 = hrtime(true);
    $fail = fn(string $code, string $msg): array => ['ok' => false, 'code' => $code, 'message' => $msg, 'elapsed_ms' => (int)round((hrtime(true) - $t0) / 1e6)];
    $limit = autofill_service_image_limit();
    if (($imageBytes > 0 ? $imageBytes : strlen($body)) > $limit) return $fail('too_large', autofill_too_large_message($limit));
    $r = autofill_http('POST', rtrim($base, '/') . '/v1/suggest', $body, $token, $timeout, $contentType);
    $down = fn() => $fail('unavailable', autofill_unavailable_message());
    if ($r['error'] === 'timeout' || $r['error'] === 'unreachable') { autofill_log('suggest: ' . $r['error']); return $down(); }
    if ($r['error'] === 'unavailable') return $fail('unavailable', 'No suggestions: this server has no curl extension.');
    if ($r['error'] === 'too_large') { autofill_log('suggest: response too large'); return $down(); }
    switch ($r['status']) {
        case 200:
            if (!autofill_suggest_body_ok($r['content_type'], $r['body'])) {
                autofill_log('suggest: unexpected body (' . autofill_log_snippet($r['body']) . ')');
                return $down();
            }
            break;
        case 401: case 403:
            if ($token === '') { autofill_log('suggest: service requires a token (HTTP ' . $r['status'] . ')'); return $fail('unauthorized', 'No suggestions: the service requires an access token. An admin can set it in Settings → AI autofill.'); }
            autofill_log('suggest: token refused (HTTP ' . $r['status'] . ')'); return $fail('unauthorized', 'No suggestions: the service refused the access token. An admin can fix it in Settings → AI autofill.');
        case 413: return $fail('too_large', autofill_too_large_message($limit)) + ['http_413' => true];
        case 415: return $fail('unsupported', 'No suggestions: the service cannot read this kind of image.');
        case 503:
            // Only the API's JSON error object. An HTML 503 (or any other shape) is unavailable, and is not retried.
            $api = autofill_api_error($r['content_type'], $r['body']);
            if ($api === null) { autofill_log('suggest: HTTP 503 (' . autofill_log_snippet($r['body']) . ')'); return $down(); }
            $code = $api['code'];
            if ($r['retry_after'] !== null && ($code === null || $code === 'backend_unavailable')) {
                $n = max(1, $r['retry_after']);
                // A warming/busy wait (30 s on the current worker) may be retried once. A daily cap counts
                // down to 00:00 UTC and is longer than a minute: show busy and do not retry.
                if ($n <= AUTOFILL_RETRY_AFTER_MAX) {
                    $why = autofill_retry_reason($api['message']);
                    return $fail('busy', $why === 'warming'
                        ? "No suggestions: the model is warming up. Try again in $n s."
                        : "No suggestions: the service is busy. Try again in $n s.") + ['retry_after' => $n, 'retry_reason' => $why];
                }
                autofill_log('suggest: Retry-After ' . $n . 's is over ' . AUTOFILL_RETRY_AFTER_MAX . 's');
                return $fail('busy', 'No suggestions: the service is busy or not ready. Try again later.');
            }
            if ($code !== null && $code !== 'backend_unavailable') {
                autofill_log('suggest: backend error (HTTP 503)');
                return $fail('backend_error', 'No suggestions: the service had a problem with this photo. Try again, or fill in the fields yourself.');
            }
            return $fail('busy', 'No suggestions: the service is busy or not ready. Try again later.');
        default:
            if ($r['status'] >= 500) { autofill_log('suggest: HTTP ' . $r['status'] . ' (' . autofill_log_snippet($r['body']) . ')'); return $down(); }
            autofill_log('suggest: HTTP ' . $r['status']);
            return $fail('bad_response', 'No suggestions: the service answered with HTTP ' . $r['status'] . '.');
    }
    $s = autofill_parse_suggestion($r['body']);
    if ($s === null) { autofill_log('suggest: unexpected body (' . autofill_log_snippet($r['body']) . ')'); return $down(); }
    $out = ['suggestions' => $s, 'confidence' => autofill_parse_confidence($r['body']), 'elapsed_ms' => (int)round((hrtime(true) - $t0) / 1e6),
            'model_used' => autofill_parse_model_used($r['body'])];
    $filled = array_keys(array_filter($s, fn($v) => $v !== null && $v !== []));
    if (!$filled) return ['ok' => true, 'code' => 'empty', 'message' => 'No suggestions for this photo.'] + $out;
    return ['ok' => true, 'code' => 'ok', 'message' => 'Suggestions from the photo'] + $out;
}

/** 200 body we may read: JSON content type (or none) and a JSON object. HTML, plain text and truncated JSON are not. */
function autofill_suggest_body_ok(string $contentType, string $body): bool
{
    $ct = strtolower(trim(explode(';', $contentType, 2)[0]));
    if ($ct !== '' && !autofill_content_type_is_json($ct)) return false;
    return autofill_json_object($body) !== null;
}

/**
 * "Test with a photo": the same checks as Test connection (reachable, token, model ready), then the bundled sample
 * photo through POST /v1/suggest. A 503 with Retry-After no longer than $timeout is retried once after that wait
 * (a cold or switched model), the same single retry as the item form. Each request stays within the time still
 * left. Never contains the token.
 * @return array{ok:bool,code:string,message:string,checks:array<string,?bool>,info?:array,suggestions?:array,confidence?:array,elapsed_ms?:int,retry_after?:int}
 */
function autofill_test_photo(string $url, int $timeout, string $token, ?string $model = null, ?int $size = null): array
{
    $t0 = microtime(true);
    $checks = ['reachable' => null, 'token' => null, 'ready' => null];
    $c = autofill_test($url, $timeout, $token);
    $checks['reachable'] = !in_array($c['code'], ['not_configured', 'unreachable', 'timeout', 'unavailable'], true);
    if ($checks['reachable'] && $c['code'] !== 'bad_response') $checks['token'] = $c['code'] !== 'unauthorized';
    if ($checks['token']) $checks['ready'] = $c['code'] === 'ok';
    if (!$c['ok']) return $c + ['checks' => $checks];
    $left = (int)floor($timeout - (microtime(true) - $t0));
    if ($left < 1) return ['ok' => false, 'code' => 'timeout', 'message' => "No answer within $timeout seconds. Is the service busy or the timeout too short?", 'checks' => $checks, 'info' => $c['info']];
    $bytes = (string)@file_get_contents(autofill_sample_path());
    if ($bytes === '') return ['ok' => false, 'code' => 'unavailable', 'message' => 'The sample photo is missing from this installation.', 'checks' => $checks, 'info' => $c['info']];
    // the model and size as chosen in the form (or saved), offered by the service being tested, capped at the upload side
    $side = min(autofill_image_side($c['info'], $size), autofill_upload_max_side());
    $chosen = autofill_chosen_model($c['info'], $model);
    $ctx = json_encode(autofill_context($chosen), JSON_UNESCAPED_UNICODE);
    $base = rtrim(trim($url), '/');
    $analysis = null;
    try {
        $contextJson = $ctx === false ? '{}' : $ctx;
        $opened = autofill_open_analysis($bytes, $side, $contextJson, null, autofill_sample_path());
        if (!$opened['ok']) {
            $msg = preg_replace('/^No suggestions: /', 'The sample photo got no suggestions: ', $opened['message']) ?? $opened['message'];
            return ['ok' => false, 'code' => $opened['code'], 'message' => $msg, 'checks' => $checks, 'info' => $c['info']];
        }
        $analysis = $opened['path'];
        $afterChecks = $left;
        [$r, $opened] = autofill_post_analysis($base, $token, $left, $opened, fn() => autofill_open_analysis($bytes, $side, $contextJson, null, autofill_sample_path()));
        $analysis = is_string($opened['path'] ?? null) ? $opened['path'] : $analysis;
        // one retry, same rule as the item form: 503 + Retry-After, and only when that wait is within the timeout
        $wait = (int)($r['retry_after'] ?? 0);
        if (!$r['ok'] && ($r['code'] ?? '') === 'busy' && $wait > 0 && $wait <= AUTOFILL_RETRY_AFTER_MAX && $wait <= $timeout) {
            sleep($wait);
            $left = max(1, (int)floor($timeout - (microtime(true) - $t0)));
            $r = autofill_suggest_at($base, $token, $left, $opened['body'], $opened['content_type'], strlen((string)($opened['jpeg'] ?? '')));
            unset($r['http_413']);
        }
    } catch (Throwable $e) {
        autofill_log('suggest: failed (' . $e::class . ')');
        return ['ok' => false, 'code' => 'unavailable', 'message' => 'The sample photo got no suggestions: autofill is unavailable right now. You can save the item and fill it in yourself.', 'checks' => $checks, 'info' => $c['info']];
    } finally {
        autofill_unlink_analysis(is_string($analysis) ? $analysis : null);
    }
    $r += ['model_sent' => $chosen, 'image_side' => empty($c['info']['image_sizes']) ? null : $side];
    if ($r['ok']) {
        $msg = $r['code'] === 'empty'
            ? 'Connected to “' . $c['info']['name'] . '”, but it had no suggestions for the sample photo.'
            : 'Connected to “' . $c['info']['name'] . '”: it suggested these details for the sample photo.';
        return ['ok' => true, 'code' => $r['code'], 'message' => $msg, 'checks' => $checks, 'info' => $c['info']] + $r;
    }
    if ($r['code'] === 'timeout' && $afterChecks < $timeout) $r['message'] = "No suggestions within the $timeout s timeout (the checks used " . ($timeout - $afterChecks) . ' s of it).';
    if ($r['code'] === 'busy' && ($r['retry_reason'] ?? 'warming') === 'warming') $checks['ready'] = false;   // a full queue: the model is loaded
    $r['message'] = preg_replace('/^No suggestions: /', 'The sample photo got no suggestions: ', $r['message']);
    return $r + ['checks' => $checks, 'info' => $c['info']];
}

function autofill_sample_path(): string
{
    return dirname(__DIR__) . '/public/assets/autofill-sample.jpg';
}

/** 13456 → "13.5 s". */
function autofill_seconds(int $ms): string
{
    return number_format($ms / 1000, 1, '.', '') . ' s';
}
