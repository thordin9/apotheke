<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Storage\LeaseDenied;
use CodeIgniter\Database\BaseConnection;
use SQLite3;

/**
 * The catalog owner of a remote restore. The token is one restore_marker row
 * (run 'fence'), not the staged restore's lock row and not the object lease.
 *
 * claim() is the committed takeover. It replaces the row in its own transaction.
 * A restore transaction that has updated the row still owns it until that
 * transaction commits or rolls back, even when the object lease has already
 * moved: claim() waits on the row and cannot commit first. Renewing the object
 * lease does not wait on that claim. When the stored token is different, renew
 * fails on the read, the open transaction rolls back, and the waiting claim
 * then commits. After that commit, rejectUnless matches no row and the stale
 * transaction writes nothing.
 */
final class RestoreFence
{
    public const RUN = 'fence';

    /**
     * How long a SQLite claim waits for the open fence transaction. The connection's
     * usual busy timeout is 5s, and a failed DELETE then a failed INSERT spend it
     * twice. One bridge call inside that transaction is allowed 30s, and a map
     * pointer reads the version and then writes it. The claim waits for both and
     * then puts the connection's timeout back. It still commits only after the
     * holder ends the transaction.
     */
    private const SQLITE_CLAIM_WAIT_MS = 60000;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public static function claim(BaseConnection $db, string $token): void
    {
        Writes::refuseOnD1($db, 'Restoring a backup');
        $stamp = self::stamp();
        $sqlite = $db->connID instanceof SQLite3 ? $db->connID : null;
        $previous = 5000;
        if ($sqlite !== null) {
            $current = $sqlite->querySingle('PRAGMA busy_timeout');
            $previous = is_numeric($current) ? (int)$current : 5000;
            $sqlite->busyTimeout(self::SQLITE_CLAIM_WAIT_MS);
            $sqlite->exec('PRAGMA busy_timeout = ' . self::SQLITE_CLAIM_WAIT_MS);
        }
        try {
            Writes::transaction($db, static function () use ($db, $token, $stamp): void {
                Writes::check($db->query('DELETE FROM restore_marker WHERE run = ?', [self::RUN]), 'restore fence');
                Writes::check(
                    $db->query('INSERT INTO restore_marker (run, sha256, applied_at) VALUES (?, ?, ?)', [self::RUN, $token, $stamp]),
                    'restore fence',
                );
            });
        } finally {
            if ($sqlite !== null) {
                $sqlite->busyTimeout($previous);
                $sqlite->exec('PRAGMA busy_timeout = ' . $previous);
            }
        }
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
