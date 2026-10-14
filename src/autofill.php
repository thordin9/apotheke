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
    try { $d = json_decode($body, true, 16, JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
    return is_array($d) && ($d === [] || !array_is_list($d)) ? $d : null;
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
