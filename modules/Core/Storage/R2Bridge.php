<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

/**
 * The only HTTP protocol R2Storage speaks. Installation IDs, credentials and
 * prefixes deliberately stop at the Worker; this client accepts relative keys.
 *
 * GET: {ok:true, found:true, bytes:base64} or {ok:true,found:false}.
 * HEAD: {ok:true,found:true,modified:int} or {ok:true,found:false}.
 * PUT/DELETE: {ok:true}; PUT has an application/octet-stream body.
 * DELETE may return HTTP 404, which is already absent. Responses are at most
 * 64 MiB. Objects are sent in 64 KiB reads. Redirects are never followed.
 *
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
    public function put(string $key, $body): void
    {
        $this->request('PUT', $key, $body);
    }

    /**
     * @param resource|null $body
     * @return array<string, mixed>|null
     */
    public function request(string $method, string $key, $body = null, bool $allowMissing = false): ?array
    {
        $response = $this->send($method, $key, $body);
        if ($response['status'] === 404 && $allowMissing) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300 || strlen($response['body']) > self::MAX_RESPONSE_BYTES) {
            throw R2StorageException::failed();
        }

        return $this->json($response['body']);
    }

    /** Same path grammar as LocalStorage: no absolute, empty or dot segments. */
    public static function validateKey(string $key): void
    {
        if ($key === '' || str_contains($key, "\0") || str_contains($key, '\\') || str_starts_with($key, '/')
            || str_contains($key, '?') || str_contains($key, '#') || preg_match('/[\x00-\x1f\x7f]/', $key)
        ) {
            throw new \InvalidArgumentException('Invalid object storage key.');
        }
        $parts = explode('/', $key);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new \InvalidArgumentException('Invalid object storage key.');
        }
    }

    private function path(string $key): string
    {
        self::validateKey($key);

        return '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }

    /**
     * @param resource|null $body
     * @return array{status:int,body:string}
     */
    private function send(string $method, string $key, $body): array
    {
        // Caller misuse is not a transport failure and must not invoke HTTP.
        $url = $this->base . $this->path($key);
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
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
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
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/octet-stream',
                    'Transfer-Encoding: chunked',
                    'Expect:',
                ],
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
