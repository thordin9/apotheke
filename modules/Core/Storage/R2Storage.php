<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

/** Application-relative storage through the Worker's installation-scoped R2 bridge. */
final class R2Storage implements Storage, VersionedStorage
{
    public function __construct(private readonly R2Bridge $bridge, private readonly int $maxBytes = 104857600)
    {
        if ($maxBytes <= 0 || $maxBytes > 104857600) {
            throw new \InvalidArgumentException('Object storage write limit must be between 1 and 104857600 bytes.');
        }
    }

    public function forOriginals(): self
    {
        return new self($this->bridge->forOriginals(), $this->maxBytes);
    }

    public function read(string $key): ?string
    {
        if (!$this->validKey($key)) {
            return null;
        }
        $json = $this->bridge->request('GET', $key);
        if (!self::found($json)) {
            return null;
        }
        $bytes = base64_decode(self::string($json, 'bytes'), true);
        if ($bytes === false) {
            throw R2StorageException::failed();
        }

        return $bytes;
    }

    public function write(string $key, string $bytes): void
    {
        $this->requireSize(strlen($bytes));
        $this->requireValidKey($key);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw R2StorageException::failed();
        }
        try {
            $this->writeAll($stream, $bytes);
            if (!rewind($stream)) {
                throw R2StorageException::failed();
            }
            $this->bridge->put($key, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }
    }

    public function writeStream(string $key, mixed $stream): void
    {
        $this->requireValidKey($key);
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Storage stream must be a readable stream.');
        }
        $part = fopen('php://temp/maxmemory:5242880', 'w+b');
        if ($part === false) {
            throw R2StorageException::failed();
        }
        $remaining = $this->maxBytes;
        $number = 0;
        $uploadId = null;
        $parts = [];
        try {
            while (!feof($stream)) {
                try {
                    $chunk = fread($stream, min(65536, $remaining + 1));
                } catch (\Throwable) {
                    throw R2StorageException::failed();
                }
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw R2StorageException::failed();
                }
                if (strlen($chunk) > $remaining) {
                    throw new R2StorageException('Object storage write exceeds its configured size limit.');
                }
                $remaining -= strlen($chunk);
                if (!$this->writeAll($part, $chunk)) {
                    throw R2StorageException::failed();
                }
                if (ftell($part) !== 5242880) {
                    continue;
                }
                $uploadId ??= $this->bridge->beginMultipart($key);
                $parts[] = $this->bridge->uploadPart($key, $uploadId, ++$number, $part);
                ftruncate($part, 0);
                rewind($part);
            }
            $uploadId ??= $this->bridge->beginMultipart($key);
            if (($size = fstat($part)['size'] ?? 0) > 0) {
                $parts[] = $this->bridge->uploadPart($key, $uploadId, ++$number, $part);
            }
            $this->bridge->completeMultipart($key, $uploadId, $parts);
        } catch (\Throwable $e) {
            if ($uploadId !== null) {
                $this->bridge->abortMultipart($key, $uploadId);
            }
            throw $e;
        } finally {
            is_resource($part) && fclose($part);
        }
    }

    /** Conditional create is atomic only through ordinary PUT, not multipart. */
    public function conditionalCreate(string $key, string $bytes): bool
    {
        $this->requireSize(strlen($bytes));
        $this->requireValidKey($key);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false || !$this->writeAll($stream, $bytes) || !rewind($stream)) {
            throw R2StorageException::failed();
        }
        try {
            return $this->bridge->conditionalCreate($key, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** @return array{0:string,1:string}|null */
    public function version(string $key): ?array
    {
        $this->requireValidKey($key);
        $json = $this->presence('GET', $key);
        if (!self::found($json)) return null;
        $raw = base64_decode(self::string($json, 'bytes'), true);
        if ($raw === false) throw R2StorageException::failed();
        $metadata = $this->presence('HEAD', $key);
        if (!self::found($metadata)) throw R2StorageException::failed();
        return [$raw, self::string($metadata, 'etag')];
    }

    public function putIfMissing(string $key, string $bytes): bool
    {
        return $this->conditionalCreate($key, $bytes);
    }

    public function putIfVersion(string $key, string $etag, string $bytes): bool
    {
        $this->requireSize(strlen($bytes));
        $this->requireValidKey($key);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false || !$this->writeAll($stream, $bytes) || !rewind($stream)) throw R2StorageException::failed();
        try {
            return $this->bridge->conditionalReplace($key, $etag, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** @return array{objects:list<string>,cursor:?string} */
    public function list(string $prefix = '', string $cursor = '', int $limit = 100): array
    {
        return $this->bridge->list($prefix, $cursor, $limit);
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): bool
    {
        if ($bytes === '') {
            return true;
        }
        $written = @fwrite($stream, $bytes);
        while ($written !== false && $written > 0 && $written < strlen($bytes)) {
            $n = @fwrite($stream, substr($bytes, $written));
            $written = $n === false || $n === 0 ? false : $written + $n;
        }

        return $written === strlen($bytes);
    }

    public function delete(string $key): void
    {
        if (!$this->validKey($key)) {
            return;
        }
        $this->bridge->request('DELETE', $key, allowMissing: true);
    }

    public function exists(string $key): bool
    {
        if (!$this->validKey($key)) {
            return false;
        }
        $json = $this->bridge->request('HEAD', $key, allowMissing: true);

        return $json !== null && self::found($json);
    }

    public function modifiedAt(string $key): ?int
    {
        if (!$this->validKey($key)) {
            return null;
        }
        $json = $this->bridge->request('HEAD', $key, allowMissing: true);
        if ($json === null || !self::found($json)) {
            return null;
        }
        $modified = $json['modified'] ?? null;
        if (!is_int($modified) || $modified < 0) {
            throw R2StorageException::failed();
        }

        return $modified;
    }

    /** @param array<mixed> $json */
    private static function found(array $json): bool
    {
        if (!is_bool($json['found'] ?? null)) {
            throw R2StorageException::failed();
        }

        return $json['found'];
    }

    private function requireValidKey(string $key): void
    {
        if (!$this->validKey($key)) {
            throw new \InvalidArgumentException('Invalid object storage key.');
        }
    }

    private function requireSize(int $size): void
    {
        if ($size > $this->maxBytes) {
            throw new R2StorageException('Object storage write exceeds its configured size limit.');
        }
    }

    private function validKey(string $key): bool
    {
        try {
            R2Bridge::validateKey($key);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function presence(string $method, string $key): array
    {
        $response = $this->bridge->request($method, $key, allowMissing: true);
        // A 404 from the real Worker carries no body, so absence is the found flag.
        return $response ?? ['ok' => true, 'found' => false];
    }

    /** @param array<mixed> $json */
    private static function string(array $json, string $field): string
    {
        if (!isset($json[$field]) || !is_string($json[$field])) {
            throw R2StorageException::failed();
        }

        return $json[$field];
    }
}
