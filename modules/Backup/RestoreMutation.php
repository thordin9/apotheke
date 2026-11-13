<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Storage\LeaseDenied;
use Apotheke\Core\Storage\R2Storage;
use CodeIgniter\Database\BaseConnection;

/**
 * Remote restore writes. The fence row is the catalog owner. claim() is the
 * committed takeover: it waits while a transaction holds that row, and it does
 * not commit until the holder ends the transaction. A renew of the object lease
 * fails at once when the token differs, so the holder does not wait on the
 * process blocked in claim(). After claim() commits, rejectUnless rolls the
 * stale transaction back.
 *
 * A photo, thumbnail, attachment, or camera file is created only when the name
 * is absent. Those names are the content, and a local restore keeps a file that
 * is already there. A map pointer is replaced: the conditional PUT runs while
 * this transaction holds the fence row, so the next restore cannot write that
 * key first.
 */
final class RestoreMutation
{
    /** True when this call stored $bytes. False when the object was already there. */
    public static function createIfAbsent(R2Storage $store, string $key, string $bytes): bool
    {
        return $store->putIfMissing($key, $bytes);
    }

    /**
     * On the hosted database (D1, no interactive transaction) the order keeps the same guarantee: the object's
     * version is read first, then the fence is checked (one batch), then the PUT is conditional on that version. A
     * restore that claims the fence after the check writes the key only after its claim, so it changes the version
     * and this PUT fails; one that claimed before the check fails the check.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     */
    public static function replace(BaseConnection $db, string $token, R2Storage $store, string $key, string $bytes): void
    {
        $put = static function (?array $version) use ($store, $key, $bytes): void {
            $stored = $version === null
                ? $store->putIfMissing($key, $bytes)
                : $store->putIfVersion($key, $version[1], $bytes);
            if (!$stored) {
                throw new LeaseDenied('The lease is held.');
            }
        };
        if (Writes::onD1($db)) {
            $version = $store->version($key);
            RestoreFence::rejectUnless($db, $token);
            $put($version);

            return;
        }
        Writes::transaction($db, static function () use ($db, $token, $store, $key, $put): void {
            RestoreFence::rejectUnless($db, $token);
            $put($store->version($key));
        });
    }
}
