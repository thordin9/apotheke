<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Storage\LeaseDenied;
use CodeIgniter\Database\BaseConnection;

/**
 * The catalog half of a remote restore lease. The token is a row in restore_marker,
 * and the restoring transaction updates that row itself. A takeover that has already
 * committed a different token makes this transaction roll back. This is not the
 * staged restore's own lock row.
 */
final class RestoreFence
{
    public const RUN = 'fence';

    /** @param BaseConnection<object|resource, object|resource> $db */
    public static function claim(BaseConnection $db, string $token): void
    {
        $stamp = self::stamp();
        Writes::transaction($db, static function () use ($db, $token, $stamp): void {
            $db->query('DELETE FROM restore_marker WHERE run = ?', [self::RUN]);
            Writes::check(
                $db->query('INSERT INTO restore_marker (run, sha256, applied_at) VALUES (?, ?, ?)', [self::RUN, $token, $stamp]),
                'restore fence',
            );
        });
    }

    /**
     * Fail unless this token still owns the fence. The UPDATE is the check, so it is
     * part of the caller's transaction and locks the row until that transaction ends.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     */
    public static function rejectUnless(BaseConnection $db, string $token): void
    {
        $db->query('UPDATE restore_marker SET applied_at = ? WHERE run = ? AND sha256 = ?', [self::stamp(), self::RUN, $token]);
        if ($db->affectedRows() !== 1) {
            throw new LeaseDenied('The lease is held.');
        }
    }

    /** @param BaseConnection<object|resource, object|resource> $db */
    public static function release(BaseConnection $db, string $token): void
    {
        $db->query('DELETE FROM restore_marker WHERE run = ? AND sha256 = ?', [self::RUN, $token]);
    }

    /** @return array{sql:string,params:list<string>} */
    public static function guard(string $token): array
    {
        return [
            'sql' => "INSERT INTO uow_guard (ok) SELECT NULL WHERE NOT EXISTS (SELECT 1 FROM restore_marker WHERE run = 'fence' AND sha256 = ?)",
            'params' => [$token],
        ];
    }

    private static function stamp(): string
    {
        return gmdate('Y-m-d H:i:s') . '.' . bin2hex(random_bytes(3));
    }
}
