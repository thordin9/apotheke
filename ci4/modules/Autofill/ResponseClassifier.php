<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use Apotheke\Core\Libraries\Text;
use Closure;
use JsonException;
use stdClass;

/**
 * /v1/info and /v1/suggest answers, and the HTTP status map. Unknown fields
 * are ignored. A transport failure, HTML, truncated JSON or a 5xx that is
 * not the API's own 503 shape is "autofill unavailable".
 */
final class ResponseClassifier
{
    public const CAPABILITIES = ['name', 'category', 'tags', 'description'];

    public const MAX_MODELS = 50;

    public const SIDE_MIN = 64;

    public const SIDE_MAX = 4096;

    public const MAX_TAGS = 8;

    public const RETRY_AFTER_MAX = 60;

    public const UNAVAILABLE = 'No suggestions: autofill is unavailable right now. You can save the item and fill it in yourself.';

    /** @param Closure():int $now unix time, for an HTTP-date Retry-After */
    public function __construct(private readonly Closure $now)
    {
    }

    /** Retry-After → whole seconds from now (0–86400), or null. Delta-seconds or an HTTP date. */
    public function retryAfter(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,9}$/D', $value)) {
            return min(86400, (int)$value);
        }
        $t = preg_match('/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $value) ? strtotime($value) : false;

        return $t === false ? null : max(0, min(86400, $t - ($this->now)()));
    }

    /** @return array<string,mixed>|null */
    public function jsonObject(string $body): ?array
    {
        try {
            $o = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!$o instanceof stdClass) {
            return null;
        }
        $assoc = json_decode($body, true, 16);

        return is_array($assoc) ? $assoc : null;
    }

    /**
     * @return array{name:string,version:string,capabilities:list<string>,backend:?string,max_image_bytes:?int,enforced_image_bytes:?int,ready:bool,avg_latency_ms:?int,models:?list<array{id:string,default:bool,loaded:?bool,avg_latency_ms:?int}>,image_sizes:?list<array{long_edge:int,recommended:bool}>}|null
     */
    public function parseInfo(string $body): ?array
    {
        $d = $this->jsonObject($body);
        if ($d === null) {
            return null;
        }
        if (!is_string($d['name'] ?? null) || trim($d['name']) === '' || !is_string($d['version'] ?? null) || !is_array($d['capabilities'] ?? null)) {
            return null;
        }
        $caps = [];
        foreach ($d['capabilities'] as $c) {
            if (!is_string($c)) {
                return null;
            }
            if (in_array($c, self::CAPABILITIES, true) && !in_array($c, $caps, true)) {
                $caps[] = $c;
            }
        }
        $max = $d['max_image_bytes'] ?? null;
        if ($max !== null && (!is_int($max) || $max < 1)) {
            return null;
        }
        $enforced = $d['enforced_image_bytes'] ?? null;
        $enforced = is_int($enforced) && $enforced > 0 ? $enforced : null;
        $backend = $d['backend'] ?? null;
        if ($backend !== null && !is_string($backend)) {
            return null;
        }
        $ready = $d['ready'] ?? true;
        if (!is_bool($ready)) {
            return null;
        }
        $lat = $d['avg_latency_ms'] ?? null;
        $lat = is_int($lat) && $lat >= 0 ? min($lat, 3_600_000) : null;

        return [
            'name' => Text::cleanLine($d['name'], 80),
            'version' => Text::cleanLine($d['version'], 20),
            'capabilities' => $caps,
            'backend' => $backend === null ? null : Text::cleanLine($backend, 40),
            'max_image_bytes' => $max,
            'enforced_image_bytes' => $enforced,
            'ready' => $ready,
            'avg_latency_ms' => $lat,
            'models' => $this->parseModels($d['models'] ?? null),
            'image_sizes' => $this->parseImageSizes($d['image_sizes'] ?? null),
        ];
    }

    /**
     * @return list<array{id:string,default:bool,loaded:?bool,avg_latency_ms:?int}>|null
     */
    public function parseModels(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $out = [];
        $hasDefault = false;
        foreach ($value as $m) {
            if (!is_array($m) || !is_string($m['id'] ?? null)) {
                continue;
            }
            $id = Text::cleanLine($m['id'], 120);
            if ($id === '' || isset($out[$id])) {
                continue;
            }
            $def = ($m['default'] ?? false) === true && !$hasDefault;
            $hasDefault = $hasDefault || $def;
            $loaded = $m['loaded'] ?? null;
            $lat = $m['avg_latency_ms'] ?? null;
            $out[$id] = [
                'id' => $id,
                'default' => $def,
                'loaded' => is_bool($loaded) ? $loaded : null,
                'avg_latency_ms' => is_int($lat) && $lat >= 0 ? min($lat, 3_600_000) : null,
            ];
            if (count($out) >= self::MAX_MODELS) {
                break;
            }
        }

        return $out !== [] ? array_values($out) : null;
    }

    /**
     * @return list<array{long_edge:int,recommended:bool}>|null
     */
    public function parseImageSizes(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $out = [];
        $rec = null;
        foreach ($value as $s) {
            $e = is_array($s) ? ($s['long_edge'] ?? null) : null;
            if (!is_int($e) || $e < self::SIDE_MIN || $e > self::SIDE_MAX) {
                continue;
            }
            $out[$e] = true;
            if ($rec === null && ($s['recommended'] ?? false) === true) {
                $rec = $e;
            }
            if (count($out) >= 20) {
                break;
            }
        }
        if ($out === []) {
            return null;
        }
        ksort($out);

        return array_map(static fn (int $e): array => ['long_edge' => $e, 'recommended' => $e === $rec], array_keys($out));
    }

    /**
     * @return array{name:?string,category:?string,tags:list<string>,description:?string}|null
     */
    public function parseSuggestion(string $body): ?array
    {
        $d = $this->jsonObject($body);
        if ($d === null) {
            return null;
        }
        $shape = static function (mixed $f): bool {
            if (!is_array($f) || !is_string($f['value'] ?? null)) {
                return false;
            }
            $c = $f['confidence'] ?? null;

            return $c === null || ((is_int($c) || is_float($c)) && $c >= 0 && $c <= 1);
        };
        $val = static function (mixed $f, int $max) use ($shape): ?string {
            if (!$shape($f)) {
                return null;
            }
            $v = Text::cleanLine($f['value'], $max);

            return $v === '' ? null : $v;
        };
        $tags = [];
        if (is_array($d['tags'] ?? null) && array_is_list($d['tags'])) {
            foreach ($d['tags'] as $t) {
                $v = $val($t, 40);
                if ($v !== null && !in_array(mb_strtolower($v), array_map(mb_strtolower(...), $tags), true)) {
                    $tags[] = $v;
                }
                if (count($tags) >= self::MAX_TAGS) {
                    break;
                }
            }
        }
        $desc = null;
        if ($shape($d['description'] ?? null)) {
            $desc = trim(mb_substr(str_replace("\r", '', (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $d['description']['value'])), 0, 2000));
            if ($desc === '') {
                $desc = null;
            }
        }

        return [
            'name' => $val($d['name'] ?? null, 200),
            'category' => $val($d['category'] ?? null, 120),
            'tags' => $tags,
            'description' => $desc,
        ];
    }

    /** @return array{name:?float,category:?float,description:?float} */
    public function parseConfidence(string $body): array
    {
        $d = $this->jsonObject($body) ?? [];
        $c = static function (mixed $f): ?float {
            $v = is_array($f) ? ($f['confidence'] ?? null) : null;

            return (is_int($v) || is_float($v)) && $v >= 0 && $v <= 1 ? round((float)$v, 2) : null;
        };

        return [
            'name' => $c($d['name'] ?? null),
            'category' => $c($d['category'] ?? null),
            'description' => $c($d['description'] ?? null),
        ];
    }

    public function parseModelUsed(string $body): ?string
    {
        $v = ($this->jsonObject($body) ?? [])['model_used'] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = Text::cleanLine($v, 120);

        return $v === '' ? null : $v;
    }

    public function contentTypeIsJson(string $contentType): bool
    {
        $ct = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $ct === 'application/json' || str_ends_with($ct, '+json');
    }

    /** @return array{code:?string,message:string}|null */
    public function apiError(string $contentType, string $body): ?array
    {
        $ct = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($ct !== '' && !$this->contentTypeIsJson($ct)) {
            return null;
        }
        $d = $this->jsonObject($body);
        if ($d === null) {
            return null;
        }
        $err = $d['error'] ?? null;
        if ($err === null) {
            return ['code' => null, 'message' => ''];
        }
        if (!is_array($err) || array_is_list($err)) {
            return null;
        }
        $code = $err['code'] ?? null;
        if ($code !== null && !is_string($code)) {
            return null;
        }
        $msg = $err['message'] ?? null;

        return ['code' => is_string($code) ? $code : null, 'message' => is_string($msg) ? $msg : ''];
    }

    public function suggestBodyOk(string $contentType, string $body): bool
    {
        $ct = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($ct !== '' && !$this->contentTypeIsJson($ct)) {
            return false;
        }

        return $this->jsonObject($body) !== null;
    }

    public function retryReason(string $message): string
    {
        return preg_match('/\b(loading|warming|warm-up|warmup|starting)\b/i', $message) ? 'warming' : 'busy';
    }

    /**
     * One /v1/suggest response, already received. Never retries.
     *
     * @param array{status:int,body:string,error:?string,retry_after:?int,content_type:string} $http
     * @return array<string,mixed>
     */
    public function outcome(array $http, string $token, int $startedNs, LogRedactor $log, string $tooLarge): array
    {
        $fail = function (string $code, string $message) use ($startedNs): array {
            return ['ok' => false, 'code' => $code, 'message' => $message, 'elapsed_ms' => $this->elapsed($startedNs)];
        };
        $down = fn (): array => $fail('unavailable', self::UNAVAILABLE);
        if ($http['error'] === 'timeout' || $http['error'] === 'unreachable') {
            $log->warning('suggest: ' . $http['error']);

            return $down();
        }
        if ($http['error'] === 'unavailable') {
            return $fail('unavailable', 'No suggestions: this server has no curl extension.');
        }
        if ($http['error'] === 'too_large') {
            $log->warning('suggest: response too large');

            return $down();
        }
        switch ($http['status']) {
            case 200:
                if (!$this->suggestBodyOk($http['content_type'], $http['body'])) {
                    $log->warning('suggest: unexpected body (' . $log->snippet($http['body']) . ')');

                    return $down();
                }
                break;
            case 401:
            case 403:
                if ($token === '') {
                    $log->warning('suggest: service requires a token (HTTP ' . $http['status'] . ')');

                    return $fail('unauthorized', 'No suggestions: the service requires an access token. An admin can set it in Settings → AI autofill.');
                }
                $log->warning('suggest: token refused (HTTP ' . $http['status'] . ')');

                return $fail('unauthorized', 'No suggestions: the service refused the access token. An admin can fix it in Settings → AI autofill.');
            case 413:
                return $fail('too_large', $tooLarge) + ['http_413' => true];
            case 415:
                return $fail('unsupported', 'No suggestions: the service cannot read this kind of image.');
            case 503:
                $api = $this->apiError($http['content_type'], $http['body']);
                if ($api === null) {
                    $log->warning('suggest: HTTP 503 (' . $log->snippet($http['body']) . ')');

                    return $down();
                }
                $code = $api['code'];
                if ($http['retry_after'] !== null && ($code === null || $code === 'backend_unavailable')) {
                    $n = max(1, $http['retry_after']);
                    if ($n <= self::RETRY_AFTER_MAX) {
                        $why = $this->retryReason($api['message']);

                        return $fail('busy', $why === 'warming'
                            ? "No suggestions: the model is warming up. Try again in $n s."
                            : "No suggestions: the service is busy. Try again in $n s.") + ['retry_after' => $n, 'retry_reason' => $why];
                    }
                    $log->warning('suggest: Retry-After ' . $n . 's is over ' . self::RETRY_AFTER_MAX . 's');

                    return $fail('busy', 'No suggestions: the service is busy or not ready. Try again later.');
                }
                if ($code !== null && $code !== 'backend_unavailable') {
                    $log->warning('suggest: backend error (HTTP 503)');

                    return $fail('backend_error', 'No suggestions: the service had a problem with this photo. Try again, or fill in the fields yourself.');
                }

                return $fail('busy', 'No suggestions: the service is busy or not ready. Try again later.');
            default:
                if ($http['status'] >= 500) {
                    $log->warning('suggest: HTTP ' . $http['status'] . ' (' . $log->snippet($http['body']) . ')');

                    return $down();
                }
                $log->warning('suggest: HTTP ' . $http['status']);

                return $fail('bad_response', 'No suggestions: the service answered with HTTP ' . $http['status'] . '.');
        }
        $s = $this->parseSuggestion($http['body']);
        if ($s === null) {
            $log->warning('suggest: unexpected body (' . $log->snippet($http['body']) . ')');

            return $down();
        }
        $out = [
            'suggestions' => $s,
            'confidence' => $this->parseConfidence($http['body']),
            'elapsed_ms' => $this->elapsed($startedNs),
            'model_used' => $this->parseModelUsed($http['body']),
        ];
        $filled = array_keys(array_filter($s, static fn (mixed $v): bool => $v !== null && $v !== []));
        if ($filled === []) {
            return ['ok' => true, 'code' => 'empty', 'message' => 'No suggestions for this photo.'] + $out;
        }

        return ['ok' => true, 'code' => 'ok', 'message' => 'Suggestions from the photo'] + $out;
    }

    private function elapsed(int $startedNs): int
    {
        return (int)round((hrtime(true) - $startedNs) / 1e6);
    }
}
