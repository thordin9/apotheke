<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

/** Application-relative storage through the Worker's installation-scoped R2 bridge. */
final class R2Storage implements Storage
{
    public function __construct(private readonly R2Bridge $bridge)
    {
    }

    public function read(string $key): ?string
    {
        if (!$this->validKey($key)) {
            return null;
        }
        $json = $this->bridge->request('GET', $key, allowMissing: true);
        if ($json === null || !self::found($json)) {
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
        $copy = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($copy === false) {
            throw R2StorageException::failed();
        }
        try {
            while (!feof($stream)) {
                $chunk = @fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw R2StorageException::failed();
                }
                if (!$this->writeAll($copy, $chunk)) {
                    throw R2StorageException::failed();
                }
            }
            if (!rewind($copy)) {
                throw R2StorageException::failed();
            }
            $this->bridge->put($key, $copy);
        } finally {
            is_resource($copy) && fclose($copy);
        }
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

    private function validKey(string $key): bool
    {
        try {
            R2Bridge::validateKey($key);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
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
