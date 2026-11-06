<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

/**
 * Relative application keys only. The Worker owns installation IDs and prefixes.
 * Response bodies are capped at 64 MiB; redirects are never followed.
 */
final class R2Bridge
{
    private const MAX_RESPONSE_BYTES = 67108864;

    /** @var \Closure(string, string, (resource|null)): mixed */
    private readonly \Closure $transport;

    private readonly string $base;

    /** @param callable(string, string, (resource|null)): mixed|null $transport */
    public function __construct(string $base = 'http://r2.internal', ?callable $transport = null, private readonly int $timeout = 30)
    {
        $parts = parse_url($base);
        if (!is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host'])
            || !preg_match('/^[A-Za-z0-9.-]+$/', $parts['host'])
            || $timeout <= 0
        ) {
            throw new \InvalidArgumentException('Object storage endpoint must be an origin-only HTTP(S) URL.');
        }
        if (array_intersect_key($parts, array_flip(['user', 'pass', 'path', 'query', 'fragment'])) !== []) {
            throw new \InvalidArgumentException('Object storage endpoint must be an origin-only HTTP(S) URL.');
        }
        $this->base = rtrim($base, '/');
        $this->transport = $transport === null ? $this->http(...) : \Closure::fromCallable($transport);
    }

    /** @param resource $body */
    public function put(string $key, mixed $body): void
    {
        $this->request('PUT', $key, $body);
    }

    /**
     * @param resource|null $body
     * @param array<string,string|int> $query
     * @return array<string, mixed>|null
     */
    public function request(string $method, string $key, $body = null, bool $allowMissing = false, array $query = []): ?array
    {
        $response = $this->send($method, $key, $body, $query);
        if ($response['status'] === 404 && $allowMissing) {
            return null;
        }
        if ($response['status'] === 412 && ($query['if_none_match'] ?? null) === '*') {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300 || strlen($response['body']) > self::MAX_RESPONSE_BYTES) {
            throw R2StorageException::failed();
        }

        return $this->json($response['body']);
    }

    public function beginMultipart(string $key): string
    {
        $json = $this->request('PUT', $key, query: ['action' => 'begin']);
        $id = $json['upload_id'] ?? null;
        if (!is_string($id)) {
            throw R2StorageException::failed();
        }
        try {
            self::validateUploadId($id);
        } catch (\InvalidArgumentException) {
            throw R2StorageException::failed();
        }

        return $id;
    }

    /**
     * @param resource $body
     * @return array{partNumber:int,etag:string}
     */
    public function uploadPart(string $key, string $id, int $part, $body): array
    {
        if ($part < 1 || $part > 10000) {
            throw new \InvalidArgumentException('Invalid multipart sequence.');
        }
        self::validateUploadId($id);
        if (stream_get_meta_data($body)['seekable']) {
            if (!rewind($body)) {
                throw R2StorageException::failed();
            }
            $length = fstat($body)['size'] ?? 0;
            if ($length < 0 || $length > 5242880) {
                throw R2StorageException::failed();
            }

            return $this->uploadPartRequest($key, $id, $part, $body, $length);
        }
        $buffer = fopen('php://temp/maxmemory:5242880', 'w+b');
        if ($buffer === false) {
            throw R2StorageException::failed();
        }
        try {
            $length = 0;
            while (!feof($body)) {
                $chunk = fread($body, 65536);
                if ($chunk === false || ($chunk === '' && !feof($body))) {
                    throw R2StorageException::failed();
                }
                $written = fwrite($buffer, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw R2StorageException::failed();
                }
                $length += $written;
                if ($length > 5242880) {
                    throw R2StorageException::failed();
                }
            }
            if (!rewind($buffer)) {
                throw R2StorageException::failed();
            }

            return $this->uploadPartRequest($key, $id, $part, $buffer, $length);
        } finally {
            fclose($buffer);
        }
    }

    /**
     * @param resource $body
     * @return array{partNumber:int,etag:string}
     */
    private function uploadPartRequest(string $key, string $id, int $part, $body, int $length): array
    {
        $json = $this->request('PUT', $key, $body, query: ['action' => 'part', 'upload_id' => $id, 'part' => $part, 'size' => $length]);
        if (($json['bytes'] ?? null) !== $length || ($json['part_number'] ?? null) !== $part
            || !is_string($json['etag'] ?? null) || !preg_match('/^(?!.*[\x20\x22\x27\x28\x29\x2A])[\x21-\x7E]{1,1024}$/D', $json['etag'])
        ) {
            throw R2StorageException::failed();
        }
        return ['partNumber' => $part, 'etag' => $json['etag']];
    }

    /** @param list<array{partNumber:int,etag:string}> $parts */
    public function completeMultipart(string $key, string $id, array $parts): void
    {
        self::validateUploadId($id);
        if (count($parts) > 10000) throw new \InvalidArgumentException('Invalid multipart sequence.');
        $json = json_encode($parts, JSON_THROW_ON_ERROR);
        $this->request('PUT', $key, query: ['action' => 'complete', 'upload_id' => $id, 'parts' => $json]);
    }

    public function abortMultipart(string $key, string $id): void
    {
        self::validateUploadId($id);
        try {
            $this->request('DELETE', $key, query: ['action' => 'abort', 'upload_id' => $id]);
        } catch (\Throwable) {
            // Preserve the original source, transport, or size-limit failure.
        }
    }

    /** @param resource $body */
    public function conditionalCreate(string $key, $body): bool
    {
        return $this->request('PUT', $key, $body, query: ['if_none_match' => '*']) !== null;
    }

    /** @return array{objects:list<string>,cursor:?string} */
    public function list(string $prefix = '', string $cursor = '', int $limit = 100): array
    {
        if ($prefix !== '') {
            self::validateKey($prefix);
        }
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid object list limit.');
        }
        $json = $this->request('GET', 'index', query: ['action' => 'list', 'prefix' => $prefix, 'cursor' => $cursor, 'limit' => $limit]);
        $objects = $json['objects'] ?? null;
        $next = $json['cursor'] ?? null;
        if (!is_array($objects) || !array_is_list($objects) || (!is_string($next) && $next !== null)) {
            throw R2StorageException::failed();
        }
        try {
            foreach ($objects as $object) {
                if (!is_string($object)) {
                    throw R2StorageException::failed();
                }
                self::validateKey($object);
            }
        } catch (\InvalidArgumentException) {
            throw R2StorageException::failed();
        }

        return ['objects' => $objects, 'cursor' => $next];
    }

    private static function validateUploadId(string $id): void
    {
        if (!preg_match('/^(?!.*[\x20\x22\x27\x28\x29\x2A])[\x21-\x7E]{1,1024}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid multipart upload identifier.');
        }
    }

    /** Same path grammar as LocalStorage: no absolute, empty, or dot segments. */
    public static function validateKey(string $key): void
    {
        if ($key === '' || str_contains($key, "\0") || str_contains($key, '\\') || str_starts_with($key, '/')
            || str_contains($key, '?') || str_contains($key, '#') || preg_match('/[\x00-\x1f\x7f]/', $key)
        ) {
            throw new \InvalidArgumentException('Invalid object storage key.');
        }
        if (array_any(explode('/', $key), self::internalSegment(...))) {
            throw new \InvalidArgumentException('Invalid object storage key.');
        }
    }

    private function path(string $key): string
    {
        self::validateKey($key);
        if (array_any(explode('/', $key), self::internalSegment(...))) {
            throw new \InvalidArgumentException('Invalid object storage key.');
        }

        return '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }

    private static function internalSegment(string $part): bool
    {
        return $part === '' || str_starts_with($part, '.');
    }

    /**
     * @param resource|null $body
     * @param array<string,string|int> $query
     * @return array{status:int,body:string}
     */
    private function send(string $method, string $key, $body, array $query): array
    {
        $url = $this->base . $this->path($key);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        try {
            $response = ($this->transport)($method, $url, $body);
        } catch (\Throwable $e) {
            throw R2StorageException::failed();
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) || !is_string($response['body'] ?? null)) {
            throw R2StorageException::failed();
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(string $raw): array
    {
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw R2StorageException::failed();
        }
        if (!is_array($value) || ($value['ok'] ?? null) !== true) {
            throw R2StorageException::failed();
        }

        return $value;
    }

    /**
     * @param resource|null $body
     * @return array{status:int,body:string}
     */
    private function http(string $method, string $url, $body): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('HTTP client unavailable');
        }
        $raw = '';
        $failed = false;
        $curl = curl_init();
        if ($curl === false) {
            throw new \RuntimeException('HTTP client unavailable');
        }
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => static fn ($handle, string $line): int => strlen($line),
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $raw .= $chunk;

                return strlen($chunk);
            },
        ];
        if (is_resource($body)) {
            $options += [
                CURLOPT_UPLOAD => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Transfer-Encoding: chunked', 'Expect:'],
                CURLOPT_READFUNCTION => function ($handle, $input, int $length) use ($body, &$failed): string {
                    $chunk = @fread($body, min(65536, $length));
                    if ($chunk === false || ($chunk === '' && !feof($body))) {
                        $failed = true;

                        return '';
                    }
                    return $chunk;
                },
            ];
        }
        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($result === false || $failed) {
            throw new \RuntimeException('HTTP client failed');
        }

        return ['status' => $status, 'body' => $raw];
    }
}
