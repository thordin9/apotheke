<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * One stored backup per lifecycle job (operator console C2): the hosting side's export asks for the backup of job
 * <id>, and the same id again returns the same backup without making another.
 *
 * The job's record is one settings row, `ops_backup_job:<id>`, outside the Control Panel's keys (never exported or
 * backed up): `{"state":"running","since":…,"token":…}` while the backup is made, then
 * `{"state":"done","id":…}`. The row is claimed in a unit of work keyed by its name, which checks that no row
 * exists: on PostgreSQL two requests for one job wait for each other on that key, on SQLite on the write lock, and on
 * D1 the check and insert are one batch. The backup itself runs outside the unit. A claim older than STALE_SECONDS
 * (a request that died) may be taken over. So that a takeover never overlaps a backup still running, the running
 * one checks before each part and before its manifest that it is within GUARD_SECONDS of that age and still holds
 * the claim; otherwise it stops and keeps nothing. A backup stored after its claim was lost is deleted again.
 *
 * @phpstan-import-type Manifest from StoredBackups
 */
final class OpsBackups
{
    public const SETTING_PREFIX = 'ops_backup_job:';

    public const STALE_SECONDS = 7200;

    /** A backup stops when its claim is this close to STALE_SECONDS old. */
    public const GUARD_SECONDS = 600;

    private const JOB_ID = '/^[A-Za-z0-9_-]{1,64}$/D';

    private readonly UnitsOfWork $units;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly StoredBackups $stored,
        private readonly Clock $clock,
        ?UnitsOfWork $units = null,
    ) {
        $this->units = UnitsOfWork::forSite($db, $units);
    }

    public static function validJobId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::JOB_ID, $id) === 1;
    }

    /**
     * The backup of job $jobId, made now if there is none yet.
     *
     * @return array{backup:Manifest, created:bool}
     * @throws OpsBackupError backup_in_progress (409), backup_gone (410), backup_failed (500), bad_request (400)
     */
    public function forJob(string $jobId): array
    {
        if (!self::validJobId($jobId)) {
            throw new OpsBackupError('bad_request', 400);
        }
        $name = self::SETTING_PREFIX . $jobId;
        $row = $this->row($name);
        $state = $row === null ? null : json_decode($row, true);
        if (is_array($state) && ($state['state'] ?? null) === 'done' && is_string($state['id'] ?? null)) {
            $m = $this->stored->get($state['id']);
            if ($m === null) {
                throw new OpsBackupError('backup_gone', 410);
            }

            return ['backup' => $m, 'created' => false];
        }
        if ($row !== null && is_array($state) && ($state['state'] ?? null) === 'running'
            && is_int($state['since'] ?? null) && $this->clock->unix() - $state['since'] < self::STALE_SECONDS) {
            throw new OpsBackupError('backup_in_progress', 409);
        }
        $since = $this->clock->unix();
        $claim = (string)json_encode(['state' => 'running', 'since' => $since, 'token' => bin2hex(random_bytes(8))]);
        $this->claim($name, $row, $claim);
        $deadline = $since + self::STALE_SECONDS - self::GUARD_SECONDS;
        $proceed = function () use ($name, $claim, $deadline): void {
            if ($this->clock->unix() >= $deadline) {
                throw new OpsBackupError('backup_failed', 500);
            }
            if ($this->row($name) !== $claim) {
                throw new OpsBackupError('backup_in_progress', 409);
            }
        };
        try {
            $m = $this->stored->store(StoredBackups::KIND_EXPORT, 'job:' . $jobId, $proceed);
        } catch (Throwable $e) {
            $this->settle($name, $claim, null);
            $lost = $e->getPrevious();

            throw $lost instanceof OpsBackupError && $lost->status === 409 ? $lost : new OpsBackupError('backup_failed', 500, $e);
        }
        try {
            $this->settle($name, $claim, (string)json_encode(['state' => 'done', 'id' => $m['id']]));
        } catch (OpsBackupError $e) {
            $this->stored->discard($m['id']);   // the claim went to another request meanwhile: its backup counts

            throw $e;
        }

        return ['backup' => $m, 'created' => true];
    }

    /** Take the job's row: insert it when there was none, or replace the stale claim read as $old. */
    private function claim(string $name, ?string $old, string $claim): void
    {
        $now = $this->clock->now();
        $busy = static fn (): OpsBackupError => new OpsBackupError('backup_in_progress', 409);
        $this->units->run('settings', static function (UnitOfWork $uow) use ($name, $old, $claim, $now, $busy): void {
            if ($old === null) {
                $uow->assert('NOT EXISTS (SELECT 1 FROM settings WHERE name = ?)', [$name], 'job not claimed', $busy);
                $uow->write('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)', [$name, $claim, $now], 'claim job');
            } else {
                $uow->write('UPDATE settings SET value = ?, updated_at = ? WHERE name = ? AND value = ?', [$claim, $now, $name, $old], 'take over stale claim', 1, $busy);
            }
        }, $name);
    }

    /** Record the finished backup ($done), or drop the claim after a failure ($done null); only while it is ours. */
    private function settle(string $name, string $claim, ?string $done): void
    {
        $now = $this->clock->now();
        $this->units->run('settings', static function (UnitOfWork $uow) use ($name, $claim, $done, $now): void {
            if ($done === null) {
                $uow->write('DELETE FROM settings WHERE name = ? AND value = ?', [$name, $claim], 'release claim');
            } else {
                $uow->write('UPDATE settings SET value = ?, updated_at = ? WHERE name = ? AND value = ?', [$done, $now, $name, $claim], 'record backup', 1,
                    static fn (): OpsBackupError => new OpsBackupError('backup_in_progress', 409));
            }
        }, $name);
    }

    private function row(string $name): ?string
    {
        $v = Writes::rows($this->db, 'SELECT value FROM settings WHERE name = ?', [$name])[0]['value'] ?? null;

        return is_string($v) ? $v : null;
    }
}
