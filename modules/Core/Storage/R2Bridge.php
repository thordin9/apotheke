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
 * 64 MiB. Objects are sent in 64 KiB chunks; the Worker response must set
 * Content-Length and close the connection. Redirects are never followed.
 *
 */
final class R2Bridge
{
    private const MAX_RESPONSE_BYTES = 67108864;

    /** @var \Closure(string, string, (resource|null)): mixed */
    private readonly \Closure $transport;

    /** @var array{scheme:string,host:string,port?:int} */
    private readonly array $origin;

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
        $this->origin = [
            'scheme' => $parts['scheme'],
            'host' => $parts['host'],
        ] + (isset($parts['port']) ? ['port' => $parts['port']] : []);
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
        try {
            $response = ($this->transport)($method, $this->base . $this->path($key), $body);
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
        $secure = $this->origin['scheme'] === 'https';
        $port = $this->origin['port'] ?? ($secure ? 443 : 80);
        $socket = @stream_socket_client(
            ($secure ? 'ssl://' : 'tcp://') . $this->origin['host'] . ':' . $port,
            $number,
            $error,
            $this->timeout
        );
        if ($socket === false) {
            throw new \RuntimeException('Connection failed');
        }
        try {
            $target = parse_url($url, PHP_URL_PATH);
            if (!is_string($target) || !stream_set_timeout($socket, $this->timeout)) {
                throw new \RuntimeException('Connection failed');
            }
            $host = $this->origin['host'];
            $default = $secure ? 443 : 80;
            $authority = $host . ($port !== $default ? ':' . $port : '');
            $headers = $method . ' ' . $target . " HTTP/1.1\r\n"
                . 'Host: ' . $authority . "\r\n"
                . "Accept: application/json\r\nConnection: close\r\n";
            $headers .= is_resource($body)
                ? "Content-Type: application/octet-stream\r\nTransfer-Encoding: chunked\r\n"
                : "Content-Length: 0\r\n";
            $this->writeAll($socket, $headers . "\r\n");
            if (is_resource($body)) {
                $this->sendBody($socket, $body);
            }

            return $this->readResponse($socket);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param resource $body
     */
    private function sendBody($socket, $body): void
    {
        while (!feof($body)) {
            $chunk = @fread($body, 65536);
            if ($chunk === false || ($chunk === '' && !feof($body))) {
                throw new \RuntimeException('Body read failed');
            }
            if ($chunk === '') {
                break;
            }
            $this->writeAll($socket, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
        }
        $this->writeAll($socket, "0\r\n\r\n");
    }

    /**
     * @param resource $socket
     * @return array{status:int,body:string}
     */
    private function readResponse($socket): array
    {
        $raw = stream_get_contents($socket, self::MAX_RESPONSE_BYTES + 16385);
        if ($raw === false) {
            throw new \RuntimeException('Response failed');
        }
        $separator = strpos($raw, "\r\n\r\n");
        if ($separator === false || $separator > 16384) {
            throw new \RuntimeException('Malformed response');
        }
        $head = substr($raw, 0, $separator);
        $body = substr($raw, $separator + 4);
        $lines = explode("\r\n", $head);
        if (!preg_match('~^HTTP/1\.[01] (\d{3})(?: |$)~', $lines[0], $match)) {
            throw new \RuntimeException('Malformed response');
        }
        $status = (int) $match[1];
        $length = null;
        foreach (array_slice($lines, 1) as $line) {
            if (preg_match('/^Content-Length:\s*(\d+)$/i', $line, $match)) {
                if ($length !== null && $length !== (int) $match[1]) {
                    throw new \RuntimeException('Malformed response');
                }
                $length = (int) $match[1];
            }
            if (str_starts_with(strtolower($line), 'transfer-encoding:')) {
                throw new \RuntimeException('Malformed response');
            }
        }
        if ($length === null || $length > self::MAX_RESPONSE_BYTES || strlen($body) !== $length) {
            throw new \RuntimeException('Malformed response');
        }

        return ['status' => $status, 'body' => $body];
    }

    /** @param resource $socket */
    private function writeAll($socket, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $n = @fwrite($socket, substr($bytes, $offset, 65536));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Socket write failed');
            }
            $offset += $n;
        }
    }
}
