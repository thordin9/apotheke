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
const AUTOFILL_SERVICE_IMAGE_BYTES = 10485760; // shipped service max_image_bytes, when /v1/info has not been cached yet

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
 * @return array{status:int,body:string,error:?string,retry_after:?int} error: unreachable (incl. connect timeout)|timeout|unavailable|too_large|null;
 *         retry_after: the Retry-After header in seconds (delta-seconds or an HTTP date), null when absent or unreadable
 */
function autofill_http(string $method, string $url, ?array $multipart, string $token, int $timeout): array
{
    if (!function_exists('curl_init')) return ['status' => 0, 'body' => '', 'error' => 'unavailable', 'retry_after' => null];
    $ch = curl_init($url);
    $body = '';
    $tooLarge = false;
    $retryAfter = null;
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
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retryAfter): int {
            if (stripos($line, 'retry-after:') === 0) $retryAfter = autofill_parse_retry_after(substr($line, 12));
            return strlen($line);
        },
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
    $connected = (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
    curl_close($ch);
    if ($tooLarge) return ['status' => $status, 'body' => '', 'error' => 'too_large', 'retry_after' => null];
    if ($ok === false || $errno !== 0) {
        // a timeout before the connection was made (the 3 s connect timeout) means "not reachable", not "slow"
        $err = $errno === CURLE_OPERATION_TIMEDOUT && $connected ? 'timeout' : 'unreachable';
        return ['status' => 0, 'body' => '', 'error' => $err, 'retry_after' => null];
    }
    return ['status' => $status, 'body' => $body, 'error' => null, 'retry_after' => $retryAfter];
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
 * @return array{name:string,version:string,capabilities:string[],backend:?string,max_image_bytes:?int,ready:bool,avg_latency_ms:?int}|null
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
    $lat = $d['avg_latency_ms'] ?? null;
    $lat = is_int($lat) && $lat >= 0 ? min($lat, 3_600_000) : null;
    return ['name' => clean_line($d['name'], 80), 'version' => clean_line($d['version'], 20), 'capabilities' => $caps,
            'backend' => $backend === null ? null : clean_line($backend, 40), 'max_image_bytes' => $max, 'ready' => $ready,
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

/** Bytes as MiB for the image limit: 10485760 → "10 MiB", 1572864 → "1.5 MiB". */
function autofill_mib(int $bytes): string
{
    return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MiB';
}

/**
 * The image limit to tell the user: the service's max_image_bytes when we have seen one, otherwise the shipped 10 MiB.
 */
function autofill_service_image_limit(): int
{
    foreach ([autofill_info_cache(), autofill_known_info()] as $info) {
        $n = is_array($info) ? ($info['max_image_bytes'] ?? null) : null;
        if (is_int($n) && $n > 0) return $n;
    }
    return AUTOFILL_SERVICE_IMAGE_BYTES;
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
        @file_put_contents($f, json_encode(['at' => time(), 'url' => (string)setting('autofill_url'), 'info' => $info]));
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

const AUTOFILL_IMAGE_SIDE = 1024;          // long edge of the JPEG we send (the service's recommendation)
const AUTOFILL_IMAGE_QUALITY = 82;
const AUTOFILL_MAX_TAGS = 8;

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
 * Photo → suggestions. Never throws; any problem is "no suggestions" with a short reason for the status line.
 * A 503 backend_unavailable with Retry-After (model loading, queue full, a model switch) also carries retry_after
 * (seconds) so the browser can wait and ask once more. avg_latency_ms: the expected time per suggestion for the chosen
 * model (or null). The chosen model (Settings → AI autofill) goes along as context.model when the service lists models;
 * the image is resized to the chosen long edge. image: what was actually sent (type, bytes, width, height) – a debug
 * hook the item form puts into data-af-sent.
 * @return array{ok:bool,code:string,message:string,suggestions?:array,retry_after?:int,avg_latency_ms:?int,model_used?:?string,image?:array}
 */
function autofill_suggest(string $imageBytes): array
{
    $fail = fn(string $code, string $msg) => ['ok' => false, 'code' => $code, 'message' => $msg, 'avg_latency_ms' => autofill_expected_ms()];
    if (!autofill_enabled()) return ['ok' => false, 'code' => 'off', 'message' => 'AI autofill is off.'];
    if ($imageBytes === '') return $fail('no_image', 'No photo.');
    $known = autofill_known_info();
    $img = autofill_prepare_image($imageBytes, autofill_image_side($known));
    if ($img === null) return $fail('unsupported', 'No suggestions: this kind of file cannot be read as a photo.');
    $sent = ['type' => $img['mime'], 'bytes' => strlen($img['data']), 'width' => $img['width'], 'height' => $img['height']];
    $info = autofill_info_cache();
    if ($info !== null && ($info['max_image_bytes'] ?? null) !== null && strlen($img['data']) > $info['max_image_bytes']) {
        return $fail('too_large', autofill_too_large_message((int)$info['max_image_bytes'])) + ['image' => $sent];
    }
    $base = rtrim((string)setting('autofill_url'), '/');
    $r = autofill_suggest_at($base, autofill_token(), (int)setting('autofill_timeout'), $img, autofill_context(autofill_chosen_model($known)));
    // the service answered: refresh its /v1/info (image limit, avg_latency_ms) when the copy is older than 10 minutes
    if ($r['ok'] && autofill_info_cache(null, 600) === null) autofill_refresh_info($base, autofill_token());
    unset($r['confidence'], $r['elapsed_ms']);
    return $r + ['avg_latency_ms' => autofill_expected_ms(), 'image' => $sent];
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
 * One POST /v1/suggest to $base. Used by the item form (autofill_suggest) and the Control Panel's photo test.
 * @param array{data:string,mime:string,converted:bool} $img
 * @return array{ok:bool,code:string,message:string,suggestions?:array,confidence?:array,retry_after?:int,elapsed_ms:int}
 */
function autofill_suggest_at(string $base, string $token, int $timeout, array $img, array $context): array
{
    $t0 = hrtime(true);
    $parts = [
        'image' => new CURLStringFile($img['data'], $img['converted'] ? 'photo.jpg' : 'photo', $img['mime']),
        'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
    ];
    $r = autofill_http('POST', $base . '/v1/suggest', $parts, $token, $timeout);
    $ms = (int)round((hrtime(true) - $t0) / 1e6);
    $fail = fn(string $code, string $msg) => ['ok' => false, 'code' => $code, 'message' => $msg, 'elapsed_ms' => $ms];
    if ($r['error'] === 'timeout') return $fail('timeout', "No suggestions: the service did not answer within $timeout seconds.");
    if ($r['error'] === 'unreachable') { autofill_log('suggest: service unreachable'); return $fail('unreachable', 'No suggestions: the autofill service is not reachable.'); }
    if ($r['error'] === 'unavailable') return $fail('unavailable', 'No suggestions: this server has no curl extension.');
    if ($r['error'] === 'too_large') return $fail('bad_response', 'No suggestions: the service sent an unexpected answer.');
    switch ($r['status']) {
        case 200: break;
        case 401: case 403:
            if ($token === '') { autofill_log('suggest: service requires a token (HTTP ' . $r['status'] . ')'); return $fail('unauthorized', 'No suggestions: the service requires an access token. An admin can set it in Settings → AI autofill.'); }
            autofill_log('suggest: token refused (HTTP ' . $r['status'] . ')'); return $fail('unauthorized', 'No suggestions: the service refused the access token. An admin can fix it in Settings → AI autofill.');
        case 413: return $fail('too_large', autofill_too_large_message(autofill_service_image_limit()));
        case 415: return $fail('unsupported', 'No suggestions: the service cannot read this kind of image.');
        case 503:
            // backend_unavailable + Retry-After: the model is loading or the queue is full; worth one more try later
            $err = autofill_json_object($r['body'])['error'] ?? null;
            $code = is_array($err) && is_string($err['code'] ?? null) ? $err['code'] : null;
            if ($r['retry_after'] !== null && ($code === null || $code === 'backend_unavailable')) {
                $n = max(1, $r['retry_after']);
                $why = autofill_retry_reason(is_array($err) && is_string($err['message'] ?? null) ? $err['message'] : '');
                return $fail('busy', $why === 'warming'
                    ? "No suggestions: the model is warming up. Try again in $n s."
                    : "No suggestions: the service is busy. Try again in $n s.") + ['retry_after' => $n, 'retry_reason' => $why];
            }
            // backend_unavailable (or no code): the backend is down or still starting; anything else (backend_error):
            // the backend failed on this photo
            if ($code !== null && $code !== 'backend_unavailable') {
                autofill_log('suggest: backend error (HTTP 503)');
                return $fail('backend_error', 'No suggestions: the service had a problem with this photo. Try again, or fill in the fields yourself.');
            }
            return $fail('busy', 'No suggestions: the service is busy or not ready. Try again later.');
        case 504: return $fail('timeout', 'No suggestions: the service took too long.');
        default: autofill_log('suggest: HTTP ' . $r['status']); return $fail('bad_response', 'No suggestions: the service answered with HTTP ' . $r['status'] . '.');
    }
    $s = autofill_parse_suggestion($r['body']);
    if ($s === null) { autofill_log('suggest: malformed response'); return $fail('bad_response', 'No suggestions: the service sent an unexpected answer.'); }
    $out = ['suggestions' => $s, 'confidence' => autofill_parse_confidence($r['body']), 'elapsed_ms' => $ms,
            'model_used' => autofill_parse_model_used($r['body'])];
    $filled = array_keys(array_filter($s, fn($v) => $v !== null && $v !== []));
    if (!$filled) return ['ok' => true, 'code' => 'empty', 'message' => 'No suggestions for this photo.'] + $out;
    return ['ok' => true, 'code' => 'ok', 'message' => 'Suggestions from the photo'] + $out;
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
    // the model and size as chosen in the form (or saved), offered by the service being tested
    $side = autofill_image_side($c['info'], $size);
    $chosen = autofill_chosen_model($c['info'], $model);
    $img = autofill_prepare_image($bytes, $side);
    if ($img === null) return ['ok' => false, 'code' => 'unavailable', 'message' => 'The sample photo is missing from this installation.', 'checks' => $checks, 'info' => $c['info']];
    $base = rtrim(trim($url), '/');
    $ctx = autofill_context($chosen);
    $afterChecks = $left;
    $r = autofill_suggest_at($base, $token, $left, $img, $ctx);
    // one retry, same rule as the item form: 503 + Retry-After, and only when that wait is within the timeout
    $wait = (int)($r['retry_after'] ?? 0);
    if (!$r['ok'] && ($r['code'] ?? '') === 'busy' && $wait > 0 && $wait <= $timeout) {
        sleep($wait);
        $left = max(1, (int)floor($timeout - (microtime(true) - $t0)));
        $r = autofill_suggest_at($base, $token, $left, $img, $ctx);
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
