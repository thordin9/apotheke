<?php
declare(strict_types=1);
namespace Apotheke\Core\Storage;
interface LeaseStorage extends Storage, VersionedStorage
{
    /** Run $fn under this key's stable lock; false when non-blocking and busy. */
    public function withLease(string $key, \Closure $fn, bool $wait = true): mixed;
}
