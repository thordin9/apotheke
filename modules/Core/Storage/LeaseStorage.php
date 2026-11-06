<?php
declare(strict_types=1);
namespace Apotheke\Core\Storage;
/** Local atomic coordination and streaming needed by chunked restores. */
interface LeaseStorage extends Storage, VersionedStorage
{
    /** Run $fn with this key's lock; false when busy and $wait is false. */
    public function withLease(string $key, \Closure $fn, bool $wait = true): mixed;
    /** Atomically replace matching $etag; local implementations durable-sync when $sync is true. */
    public function putIfVersion(string $key, string $etag, string $bytes, bool $sync = false): bool;
    /** Local ZIP/chunk spool, only beneath the validated storage key's root. */
    public function pathFor(string $key): string;
    public function renew(string $key): void;
}
