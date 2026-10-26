<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use Apotheke\Core\Storage\Storage;
use Apotheke\Media\ImageStore;
use Apotheke\Settings\SettingsService;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * The autofill client: suggestions, connection tests, and the photo test.
 * Settings and the token are read only through SettingsService. The
 * warn-once pair and the temporary JPEG live in Storage. Stored photos are
 * read through Media and never written.
 */
final class AutofillService
{
    public const IMAGE_SIDE = 1024;

    private readonly InfoCache $info;

    private readonly ImageLimit $limits;

    private readonly TempFile $temps;

    private readonly AnalysisCopyBuilder $analysis;

    private readonly ResponseClassifier $responses;

    private readonly TokenPolicy $tokens;

    private readonly CatalogContext $catalog;

    private readonly StoredPhoto $photos;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param Closure(int):void $sleep
     * @param Closure():int $now unix time
     */
    public function __construct(
        private readonly SettingsService $settings,
        Storage $storage,
        private readonly AutofillClient $http,
        private readonly LogRedactor $log,
        BaseConnection $db,
        ImageStore $images,
        private readonly string $samplePath,
        private readonly Closure $sleep,
        Closure $now,
    ) {
        $this->info = new InfoCache($storage, $settings, $now);
        $this->limits = new ImageLimit($settings, $this->info, $log);
        $this->temps = new TempFile($storage);
        $this->analysis = new AnalysisCopyBuilder($this->limits, $this->temps, $log);
        $this->responses = new ResponseClassifier($now);
        $this->tokens = new TokenPolicy($settings);
        $this->catalog = new CatalogContext($db);
        $this->photos = new StoredPhoto($db, $images);
    }

    public function limits(): ImageLimit
    {
        return $this->limits;
    }

    public function analysis(): AnalysisCopyBuilder
    {
        return $this->analysis;
    }

    public function responses(): ResponseClassifier
    {
        return $this->responses;
    }

    public function tokens(): TokenPolicy
    {
        return $this->tokens;
    }

    public function info(): InfoCache
    {
        return $this->info;
    }

    public function enabled(): bool
    {
        return (bool)$this->settings->get('autofill_enabled') && (string)$this->settings->get('autofill_url') !== '';
    }

    /** @param array<string,mixed> $info */
    public function rememberInfo(array $info): void
    {
        $this->info->put($info);
    }

    /** @return array<string,mixed>|null */
    public function cachedInfo(int $maxAge = 3600): ?array
    {
        return $this->info->get($maxAge);
    }

    /** @return array<string,mixed>|null */
    public function knownInfo(): ?array
    {
        return $this->info->get(PHP_INT_MAX);
    }

    public function releaseAnalysis(?string $key): void
    {
        $this->temps->release($key);
    }

    public function expectedMs(): ?int
    {
        $i = $this->info->get();
        if ($i === null) {
            return null;
        }
        $want = $this->chosenModel($i) ?? $this->defaultModel($i);
        foreach ($i['models'] ?? [] as $m) {
            if (is_array($m) && ($m['id'] ?? null) === $want && ($m['avg_latency_ms'] ?? null) !== null) {
                return (int)$m['avg_latency_ms'];
            }
        }
        $v = $i['avg_latency_ms'] ?? null;

        return is_int($v) && $v >= 0 ? $v : null;
    }

    /** @param array<string,mixed>|null $info */
    public function defaultModel(?array $info): ?string
    {
        $models = $info['models'] ?? null;
        if (!is_array($models) || $models === []) {
            return null;
        }
        foreach ($models as $m) {
            if (is_array($m) && !empty($m['default'])) {
                return (string)$m['id'];
            }
        }
        $first = $models[0] ?? null;

        return is_array($first) ? (string)($first['id'] ?? '') : null;
    }

    /** @param array<string,mixed>|null $info */
    public function chosenModel(?array $info, ?string $choice = null): ?string
    {
        $choice = trim($choice ?? (string)$this->settings->get('autofill_model'));

        return $choice !== '' && !empty($info['models']) ? $choice : null;
    }

    /** @param array<string,mixed>|null $info */
    public function imageSide(?array $info, ?int $choice = null): int
    {
        $sizes = $info['image_sizes'] ?? null;
        if (!is_array($sizes) || $sizes === []) {
            return self::IMAGE_SIDE;
        }
        $choice ??= (int)$this->settings->get('autofill_image_size');
        if ($choice > 0) {
            return max(ResponseClassifier::SIDE_MIN, min(ResponseClassifier::SIDE_MAX, $choice));
        }

        return $this->recommendedSide($sizes);
    }

    /**
     * @param list<array{long_edge:int,recommended:bool}> $sizes
     */
    public function recommendedSide(array $sizes): int
    {
        foreach ($sizes as $s) {
            if ($s['recommended']) {
                return $s['long_edge'];
            }
        }
        $fit = array_filter(array_column($sizes, 'long_edge'), static fn (int $e): bool => $e <= self::IMAGE_SIDE);

        return $fit !== [] ? max($fit) : $sizes[0]['long_edge'];
    }

    /**
     * @param list<string>|null $engines
     * @return array<string,mixed>
     */
    public function suggest(string $imageBytes, ?array $engines = null): array
    {
        $fail = fn (string $code, string $msg): array => $this->fail($code, $msg);
        if (!$this->enabled()) {
            return ['ok' => false, 'code' => 'off', 'message' => 'AI autofill is off.'];
        }
        if ($imageBytes === '') {
            return $fail('no_image', 'No photo.');
        }

        return $this->suggestBytes($imageBytes, null, $engines);
    }

    /**
     * Suggestions from a file. The file is read and never written back.
     *
     * @param list<string>|null $engines
     * @return array<string,mixed>
     */
    public function suggestPath(string $source, ?array $engines = null): array
    {
        $fail = fn (string $code, string $msg): array => $this->fail($code, $msg);
        if (!$this->enabled()) {
            return ['ok' => false, 'code' => 'off', 'message' => 'AI autofill is off.'];
        }
        if (!is_file($source)) {
            return $fail('no_image', 'No photo.');
        }
        $bytes = (string)@file_get_contents($source);
        if ($bytes === '') {
            return $fail('no_image', 'No photo.');
        }

        return $this->suggestBytes($bytes, $source, $engines);
    }

    /** @return array<string,mixed> */
    public function suggestPhotoId(string $photoId): array
    {
        $found = $this->photos->bytesFor($photoId);
        if (!$found['ok']) {
            return ['ok' => false, 'code' => $found['code'], 'message' => $found['message'], 'avg_latency_ms' => $this->expectedMs()];
        }

        return $this->suggest($found['bytes']);
    }

    /**
     * @return array{ok:bool,code:string,message:string,info?:array<string,mixed>}
     */
    public function test(string $url, int $timeout, ?string $token = null): array
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return ['ok' => false, 'code' => 'not_configured', 'message' => 'Enter the service address first.'];
        }
        $fail = static fn (string $code, string $msg): array => ['ok' => false, 'code' => $code, 'message' => $msg];
        $h = $this->http->request('GET', $url . '/healthz', null, '', $timeout);
        if ($h['error'] === 'timeout') {
            return $fail('timeout', "No answer within $timeout seconds. Is the service busy or the timeout too short?");
        }
        if ($h['error'] === 'unreachable') {
            return $fail('unreachable', 'Could not connect. Check the address, that the service is running and that this server can reach it.');
        }
        if ($h['error'] === 'unavailable') {
            return $fail('unavailable', 'This PHP has no curl extension, which autofill needs.');
        }
        if ($h['status'] !== 200) {
            return $fail('bad_response', 'The service answered /healthz with HTTP ' . $h['status'] . ' – is this the autofill service (API v1)?');
        }
        $token ??= $this->settings->autofillToken();
        $r = $this->http->request('GET', $url . '/v1/info', null, $token, $timeout);
        if ($r['error'] === 'timeout') {
            return $fail('timeout', "No answer within $timeout seconds. Is the service busy or the timeout too short?");
        }
        if ($r['error'] === 'unreachable') {
            return $fail('unreachable', 'Could not connect. Check the address, that the service is running and that this server can reach it.');
        }
        if ($r['error'] === 'unavailable') {
            return $fail('unavailable', 'This PHP has no curl extension, which autofill needs.');
        }
        if ($r['status'] === 401 || $r['status'] === 403) {
            return $token === ''
                ? $fail('unauthorized', 'The service requires a token (401). Set the access token the service expects below.')
                : $fail('unauthorized', 'The service refused the token (401). Set the token the service expects, or clear it if the service has none.');
        }
        if ($r['error'] !== null || $r['status'] !== 200) {
            return $fail('bad_response', 'The service answered with HTTP ' . $r['status'] . ' – is this the autofill service (API v1)?');
        }
        $info = $this->responses->parseInfo($r['body']);
        if ($info === null) {
            return $fail('bad_response', 'The service answered, but not with a valid /v1/info response – is this the autofill service (API v1)?');
        }
        if ($this->tokens->isSavedUrl($url)) {
            $this->info->put($info);
        }
        if (!$info['ready']) {
            return ['ok' => false, 'code' => 'warming_up', 'message' => 'Connected to “' . $info['name'] . '”, but it is still warming up (loading its model). Try again in a minute.', 'info' => $info];
        }

        return ['ok' => true, 'code' => 'ok', 'message' => 'Connected to “' . $info['name'] . '”.', 'info' => $info];
    }

    /**
     * @return array<string,mixed>
     */
    public function testPhoto(string $url, int $timeout, string $token, ?string $model = null, ?int $size = null): array
    {
        $t0 = microtime(true);
        $checks = ['reachable' => null, 'token' => null, 'ready' => null];
        $c = $this->test($url, $timeout, $token);
        $checks['reachable'] = !in_array($c['code'], ['not_configured', 'unreachable', 'timeout', 'unavailable'], true);
        if ($checks['reachable'] && $c['code'] !== 'bad_response') {
            $checks['token'] = $c['code'] !== 'unauthorized';
        }
        if ($checks['token']) {
            $checks['ready'] = $c['code'] === 'ok';
        }
        if (!$c['ok']) {
            return $c + ['checks' => $checks];
        }
        $left = (int)floor($timeout - (microtime(true) - $t0));
        if ($left < 1) {
            return ['ok' => false, 'code' => 'timeout', 'message' => "No answer within $timeout seconds. Is the service busy or the timeout too short?", 'checks' => $checks, 'info' => $c['info']];
        }
        $bytes = (string)@file_get_contents($this->samplePath);
        if ($bytes === '') {
            return ['ok' => false, 'code' => 'unavailable', 'message' => 'The sample photo is missing from this installation.', 'checks' => $checks, 'info' => $c['info']];
        }
        $side = min($this->imageSide($c['info'], $size), $this->analysis->uploadMaxSide());
        $chosen = $this->chosenModel($c['info'], $model);
        $contextJson = $this->contextJson($chosen);
        $base = rtrim(trim($url), '/');
        $analysis = null;
        $afterChecks = $left;
        try {
            $opened = $this->analysis->open($bytes, $side, $contextJson, null, $this->samplePath);
            if (!$opened['ok']) {
                $msg = preg_replace('/^No suggestions: /', 'The sample photo got no suggestions: ', $opened['message']) ?? $opened['message'];

                return ['ok' => false, 'code' => $opened['code'], 'message' => $msg, 'checks' => $checks, 'info' => $c['info']];
            }
            $analysis = $opened['path'];
            $afterChecks = $left;
            [$r, $opened] = $this->postAnalysis($base, $token, $left, $opened, fn (): array => $this->analysis->open($bytes, $side, $contextJson, null, $this->samplePath));
            $analysis = is_string($opened['path'] ?? null) ? $opened['path'] : $analysis;
            $wait = (int)($r['retry_after'] ?? 0);
            if (!$r['ok'] && ($r['code'] ?? '') === 'busy' && $wait > 0 && $wait <= ResponseClassifier::RETRY_AFTER_MAX && $wait <= $timeout) {
                ($this->sleep)($wait);
                $left = max(1, (int)floor($timeout - (microtime(true) - $t0)));
                $r = $this->suggestAt($base, $token, $left, $opened['body'], $opened['content_type'], strlen((string)($opened['jpeg'] ?? '')));
                unset($r['http_413']);
            }
        } catch (Throwable $e) {
            $this->log->warning('suggest: failed (' . $e::class . ')');

            return ['ok' => false, 'code' => 'unavailable', 'message' => 'The sample photo got no suggestions: ' . substr(ResponseClassifier::UNAVAILABLE, strlen('No suggestions: ')), 'checks' => $checks, 'info' => $c['info']];
        } finally {
            $this->temps->release(is_string($analysis) ? $analysis : null);
        }
        $r += ['model_sent' => $chosen, 'image_side' => empty($c['info']['image_sizes']) ? null : $side];
        if ($r['ok']) {
            $msg = $r['code'] === 'empty'
                ? 'Connected to “' . $c['info']['name'] . '”, but it had no suggestions for the sample photo.'
                : 'Connected to “' . $c['info']['name'] . '”: it suggested these details for the sample photo.';

            return ['ok' => true, 'code' => $r['code'], 'message' => $msg, 'checks' => $checks, 'info' => $c['info']] + $r;
        }
        if ($r['code'] === 'timeout' && $afterChecks < $timeout) {
            $r['message'] = "No suggestions within the $timeout s timeout (the checks used " . ($timeout - $afterChecks) . ' s of it).';
        }
        if ($r['code'] === 'busy' && ($r['retry_reason'] ?? 'warming') === 'warming') {
            $checks['ready'] = false;
        }
        $r['message'] = preg_replace('/^No suggestions: /', 'The sample photo got no suggestions: ', (string)$r['message']);

        return $r + ['checks' => $checks, 'info' => $c['info']];
    }

    public function primeInfo(): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        return $this->refreshInfo((string)$this->settings->get('autofill_url'), $this->settings->autofillToken(), min(5, max(1, (int)$this->settings->get('autofill_timeout'))));
    }

    /**
     * POST /v1/suggest. On HTTP 413, refetch /v1/info once, rebuild, and POST
     * that copy once. A second 413 is returned as-is.
     *
     * @param array<string,mixed> $opened
     * @param callable():array<string,mixed> $rebuild
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public function postAnalysis(string $base, string $token, int $timeout, array $opened, callable $rebuild): array
    {
        $r = $this->suggestAt($base, $token, $timeout, (string)$opened['body'], (string)$opened['content_type'], strlen((string)($opened['jpeg'] ?? '')));
        if (empty($r['http_413'])) {
            unset($r['http_413']);

            return [$r, $opened];
        }
        unset($r['http_413']);
        $this->log->warning('suggest: HTTP 413, refetching image limit');
        $this->refreshInfo($base, $token, min(5, max(1, $timeout)));
        $next = $rebuild();
        if (empty($next['ok'])) {
            return [['ok' => false, 'code' => $next['code'], 'message' => $next['message'], 'elapsed_ms' => (int)($r['elapsed_ms'] ?? 0)], $opened];
        }
        if (($next['path'] ?? '') !== ($opened['path'] ?? '')) {
            $this->temps->release(is_string($opened['path'] ?? null) ? $opened['path'] : null);
        }
        $r = $this->suggestAt($base, $token, $timeout, (string)$next['body'], (string)$next['content_type'], strlen((string)($next['jpeg'] ?? '')));
        unset($r['http_413']);

        return [$r, $next];
    }

    /**
     * @return array<string,mixed>
     */
    public function suggestAt(string $base, string $token, int $timeout, string $body, string $contentType, int $imageBytes = 0): array
    {
        $t0 = hrtime(true);
        $limit = $this->limits->target(false);
        $measured = $imageBytes > 0 ? $imageBytes : strlen($body);
        if ($measured > $limit) {
            return ['ok' => false, 'code' => 'too_large', 'message' => $this->limits->tooLargeMessage($limit), 'elapsed_ms' => (int)round((hrtime(true) - $t0) / 1e6)];
        }
        $http = $this->http->request('POST', rtrim($base, '/') . '/v1/suggest', $body, $token, $timeout, $contentType);

        return $this->responses->outcome($http, $token, $t0, $this->log, $this->limits->tooLargeMessage($limit));
    }

    /**
     * @param list<string>|null $engines
     * @return array<string,mixed>
     */
    private function suggestBytes(string $bytes, ?string $exifPath, ?array $engines): array
    {
        $fail = fn (string $code, string $msg): array => $this->fail($code, $msg);
        $known = $this->knownInfo();
        $side = min($this->imageSide($known), $this->analysis->uploadMaxSide());
        $contextJson = $this->contextJson($this->chosenModel($known));
        $analysis = null;
        try {
            $opened = $this->analysis->open($bytes, $side, $contextJson, $engines, $exifPath);
            if (!$opened['ok']) {
                return $fail($opened['code'], $opened['message']);
            }
            $analysis = $opened['path'];
            $base = rtrim((string)$this->settings->get('autofill_url'), '/');
            $timeout = (int)$this->settings->get('autofill_timeout');
            $token = $this->settings->autofillToken();
            [$r, $opened] = $this->postAnalysis($base, $token, $timeout, $opened, fn (): array => $this->analysis->open($bytes, $side, $contextJson, $engines, $exifPath));
            $analysis = is_string($opened['path'] ?? null) ? $opened['path'] : $analysis;
            $sent = [
                'type' => 'image/jpeg',
                'bytes' => strlen((string)($opened['jpeg'] ?? '')),
                'width' => (int)($opened['width'] ?? 0),
                'height' => (int)($opened['height'] ?? 0),
            ];
            if ($r['ok'] && $this->info->get(600) === null) {
                $this->refreshInfo($base, $token);
            }
            unset($r['confidence'], $r['elapsed_ms']);

            return $r + ['avg_latency_ms' => $this->expectedMs(), 'image' => $sent];
        } catch (Throwable $e) {
            $this->log->warning('suggest: failed (' . $e::class . ')');

            return $fail('unavailable', ResponseClassifier::UNAVAILABLE);
        } finally {
            $this->temps->release(is_string($analysis) ? $analysis : null);
        }
    }

    private function contextJson(?string $model): string
    {
        $ctx = $this->catalog->names();
        if ($model !== null && $model !== '') {
            $ctx['model'] = $model;
        }
        $json = json_encode($ctx, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    private function refreshInfo(string $base, string $token, int $timeout = 3): bool
    {
        $r = $this->http->request('GET', rtrim($base, '/') . '/v1/info', null, $token, $timeout);
        if ($r['error'] !== null || $r['status'] !== 200) {
            return false;
        }
        $info = $this->responses->parseInfo($r['body']);
        if ($info === null) {
            return false;
        }
        $this->info->put($info);

        return true;
    }

    /** @return array{ok:false,code:string,message:string,avg_latency_ms:?int} */
    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'avg_latency_ms' => $this->expectedMs()];
    }
}
