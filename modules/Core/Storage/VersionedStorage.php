<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

/** Read and update small coordination objects without losing a concurrent change. */
interface VersionedStorage
{
    /** @return array{0:string,1:string}|null bytes and version, or null when missing */
    public function version(string $key): ?array;

    public function putIfMissing(string $key, string $bytes): bool;

    /** Replace exactly the version observed; false means another writer won or it is missing. */
    public function putIfVersion(string $key, string $etag, string $bytes): bool;
}
