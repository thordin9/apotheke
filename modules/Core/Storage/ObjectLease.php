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

    /**
     * Extend this holder's lease. The token stays the fence: a different holder, or a lost
     * compare-and-swap that leaves someone else in place, refuses. An expired body that still
     * carries this token can be extended only if the compare-and-swap wins.
     */
    public function renew(string $key, string $token, int $now, int $ttl): void
    {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('A lease needs a positive lifetime.');
        }
        $this->replace($key, $token, self::body($token, $now + $ttl), true);
    }

    /**
     * Drop this holder's lease. A lost compare-and-swap is read again: another holder is left
     * as it is, and this holder is cleared once the write lands.
     */
    public function release(string $key, string $token): void
    {
        $this->replace($key, $token, self::body('', 0), false);
    }

    private function replace(string $key, string $token, string $next, bool $mustOwn): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $version = $this->storage->version($key);
            if ($version === null) {
                if ($mustOwn) {
                    throw new LeaseDenied('The lease is held.');
                }

                return;
            }
            $current = self::read($version[0]);
            if ($current === null || !hash_equals($current['token'], $token)) {
                if ($mustOwn) {
                    throw new LeaseDenied('The lease is held.');
                }

                return;
            }
            if ($this->storage->putIfVersion($key, $version[1], $next)) {
                return;
            }
        }
        throw new LeaseDenied('The lease changed before it could be taken.');
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
