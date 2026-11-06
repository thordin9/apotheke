<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

/**
 * A short lease stored on the same versioned storage as the bytes it guards.
 * Acquire it before a side effect. A stale token does not replace a newer holder.
 */
final class ObjectLease
{
    public function __construct(private readonly Storage&VersionedStorage $storage)
    {
    }

    /** The holder token. Another live holder, or a lost compare-and-swap, refuses. */
    public function acquire(string $key, int $now, int $ttl): string
    {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('A lease needs a positive lifetime.');
        }
        $version = $this->storage->version($key);
        $token = bin2hex(random_bytes(16));
        $body = self::body($token, $now + $ttl);
        if ($version === null) {
            if ($this->storage->putIfMissing($key, $body)) {
                return $token;
            }
            $version = $this->storage->version($key);
            if ($version === null) {
                throw new LeaseDenied('The lease could not be taken.');
            }
        }
        $current = self::read($version[0]);
        if ($current !== null && $current['expires'] > $now) {
            throw new LeaseDenied('The lease is held.');
        }
        if (!$this->storage->putIfVersion($key, $version[1], $body)) {
            throw new LeaseDenied('The lease changed before it could be taken.');
        }

        return $token;
    }

    /** Drop this holder's lease. A different holder is left as it is. */
    public function release(string $key, string $token): void
    {
        $version = $this->storage->version($key);
        if ($version === null) {
            return;
        }
        $current = self::read($version[0]);
        if ($current === null || !hash_equals($current['token'], $token)) {
            return;
        }
        $this->storage->putIfVersion($key, $version[1], self::body('', 0));
    }

    public function holds(string $key, string $token, int $now): bool
    {
        $version = $this->storage->version($key);
        if ($version === null) {
            return false;
        }
        $current = self::read($version[0]);

        return $current !== null && hash_equals($current['token'], $token) && $current['expires'] > $now;
    }

    private static function body(string $token, int $expires): string
    {
        return json_encode(['token' => $token, 'expires' => $expires], JSON_THROW_ON_ERROR);
    }

    /** @return array{token:string,expires:int}|null */
    private static function read(string $bytes): ?array
    {
        try {
            $data = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !is_string($data['token'] ?? null) || !is_int($data['expires'] ?? null)) {
            return null;
        }

        return ['token' => $data['token'], 'expires' => $data['expires']];
    }
}
