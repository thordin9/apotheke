<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

/**
 * Bytes addressed by a relative key.
 *
 * Local disk today. An object store such as R2 in the Cloudflare phase
 * implements the same methods. Media keeps photos, thumbnails and the
 * kept originals here. Callers never write a `writable/`
 * path themselves.
 */
interface Storage
{
    /** The bytes, or null when the key is missing or not allowed. */
    public function read(string $key): ?string;

    /**
     * Replace the bytes at $key. Creates parent directories.
     * Throws when the key is not allowed or the write fails.
     */
    public function write(string $key, string $bytes): void;

    /** Remove the key. A missing key is fine. */
    public function delete(string $key): void;

    public function exists(string $key): bool;

    /** Last change as a Unix time, or null when missing. Feeds an ETag. */
    public function modifiedAt(string $key): ?int;
}
