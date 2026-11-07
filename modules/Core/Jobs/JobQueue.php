<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use Throwable;

/**
 * The jobs table (migration 0029). A worker claims a due job with a lease, renews it on every checkpoint, and
 * finishes it; a job whose lease ran out goes to the next worker, which resumes from the checkpoint. Each step is
 * one UPDATE guarded by status, lease owner and attempt, so two workers never both own a job and no step needs a
 * transaction (D1 has none). No statement binds more than eight values or any list.
 */
final class JobQueue
{
    public const PAYLOAD_MAX = 65536;

    public const RETRY_SECONDS = 30;

    public const EXPIRED_ERROR = 'The job stopped without finishing.';

    /**
     * At most one queued or running row for a payload of this type. A second
     * enqueue returns that row. Other types may have several rows.
     */
    public const ONE_ACTIVE_TYPE = 'restore.confirm';

    private const TYPE = '/^[a-z][a-z0-9_.-]{0,63}$/';

    private const CANDIDATES = 5;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock, private readonly int $leaseSeconds = 300)
    {
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(string $type, array $payload, ?string $actor = null, int $maxAttempts = 3, ?string $runAfter = null): int
    {
        if (preg_match(self::TYPE, $type) !== 1) {
            throw new InvalidArgumentException('Job type must match ' . self::TYPE . '.');
        }
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('A job needs at least one attempt.');
        }
        $json = self::encode($payload);
        if (strlen($json) > self::PAYLOAD_MAX) {
            throw new InvalidArgumentException('Job payload is over ' . self::PAYLOAD_MAX . ' bytes.');
        }
        $now = $this->at(0);
        $run = $runAfter ?? $now;
        if ($type === self::ONE_ACTIVE_TYPE) {
            return $this->enqueueOne($type, $json, $maxAttempts, $actor, $run, $now);
        }
        $this->db->query(
            "INSERT INTO jobs (type, status, payload, attempts, max_attempts, actor, run_after, created_at, updated_at) VALUES (?, 'queued', ?, 0, ?, ?, ?, ?, ?)",
            [$type, $json, $maxAttempts, $actor, $run, $now, $now],
        );

        return (int)$this->db->insertID();
    }

    /**
     * One statement: insert only when no queued or running row has this payload.
     * The unique index is what makes two concurrent inserts one row.
     */
    private function enqueueOne(string $type, string $json, int $maxAttempts, ?string $actor, string $run, string $now): int
    {
        try {
            $this->db->query(
                "INSERT INTO jobs (type, status, payload, attempts, max_attempts, actor, run_after, created_at, updated_at)
                 SELECT v.type, 'queued', v.payload, 0, v.max_attempts, v.actor, v.run_after, v.created_at, v.updated_at
                 FROM (SELECT ? AS type, ? AS payload, ? AS max_attempts, ? AS actor, ? AS run_after, ? AS created_at, ? AS updated_at) v
                 WHERE NOT EXISTS (SELECT 1 FROM jobs WHERE type = v.type AND payload = v.payload AND status IN ('queued', 'running'))",
                [$type, $json, $maxAttempts, $actor, $run, $now, $now],
            );
        } catch (Throwable $e) {
            $existing = $this->activeEncoded($type, $json);
            if ($existing !== null) {
                return $existing->id;
            }
            throw $e;
        }
        if ($this->db->affectedRows() !== 1) {
            $existing = $this->activeEncoded($type, $json);
            if ($existing !== null) {
                return $existing->id;
            }
            throw new InvalidArgumentException('The job could not be queued.');
        }
        $row = $this->db->query(
            "SELECT id FROM jobs WHERE type = ? AND payload = ? AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1",
            [$type, $json],
        )->getRowArray();
        if (!is_array($row)) {
            throw new InvalidArgumentException('The job could not be queued.');
        }

        return (int)$row['id'];
    }

    public function find(int $id): ?Job
    {
        $row = $this->db->query('SELECT * FROM jobs WHERE id = ?', [$id])->getRowArray();

        return is_array($row) ? self::job($row) : null;
    }

    /** The oldest due job, now leased to $owner; null when none is due. Jobs whose last lease ran out fail first. */
    /**
     * The queued or running job of $type with exactly $payload, if there is one (so a second request for the same
     * work gets that job instead of a new one).
     *
     * @param array<string,mixed> $payload
     */
    public function active(string $type, array $payload): ?Job
    {
        return $this->activeEncoded($type, self::encode($payload));
    }

    private function activeEncoded(string $type, string $json): ?Job
    {
        $row = $this->db->query(
            "SELECT * FROM jobs WHERE type = ? AND payload = ? AND status IN ('queued', 'running') ORDER BY id LIMIT 1",
            [$type, $json],
        )->getRowArray();

        return is_array($row) ? self::job($row) : null;
    }

    public function claim(string $owner): ?Job
    {
        $now = $this->at(0);
        $this->db->query(
            "UPDATE jobs SET status = 'failed', error = ?, lease_owner = NULL, lease_until = NULL, finished_at = ?, updated_at = ?
             WHERE status = 'running' AND lease_until < ? AND attempts >= max_attempts",
            [self::EXPIRED_ERROR, $now, $now, $now],
        );
        $rows = $this->db->query(
            "SELECT id FROM jobs WHERE (status = 'queued' AND run_after <= ?) OR (status = 'running' AND lease_until < ?) ORDER BY id LIMIT " . self::CANDIDATES,
            [$now, $now],
        )->getResultArray();
        foreach ($rows as $row) {
            $job = $this->tryClaim((int)$row['id'], $owner);
            if ($job !== null) {
                return $job;
            }
        }

        return null;
    }

    /** Leases job $id to $owner if it is due or its lease ran out with attempts left; null when another worker won. */
    public function tryClaim(int $id, string $owner): ?Job
    {
        $now = $this->at(0);
        $this->db->query(
            "UPDATE jobs SET status = 'running', lease_owner = ?, lease_until = ?, attempts = attempts + 1, started_at = COALESCE(started_at, ?), updated_at = ?
             WHERE id = ? AND ((status = 'queued' AND run_after <= ?) OR (status = 'running' AND lease_until < ? AND attempts < max_attempts))",
            [$owner, $this->at($this->leaseSeconds), $now, $now, $id, $now, $now],
        );

        return $this->db->affectedRows() === 1 ? $this->find($id) : null;
    }

    /**
     * Saves the checkpoint and renews the lease; false when $job's lease is no longer this worker's.
     *
     * @param array<string,mixed> $state
     */
    public function checkpoint(Job $job, array $state): bool
    {
        return $this->owned($job, 'checkpoint = ?, lease_until = ?, updated_at = ?', [self::encode($state), $this->at($this->leaseSeconds), $this->at(0)]);
    }

    /**
     * Records $result. When a later claim has already marked this attempt failed
     * because the lease ended, that failure is replaced: the worker that finished
     * is the one that has the result, and a failed row is not claimed again.
     *
     * @param array<string,mixed> $result
     */
    public function complete(Job $job, array $result): bool
    {
        $now = $this->at(0);
        $encoded = self::encode($result);
        if ($this->owned($job, "status = 'done', result = ?, error = NULL, lease_owner = NULL, lease_until = NULL, finished_at = ?, updated_at = ?", [$encoded, $now, $now])) {
            return true;
        }
        $this->db->query(
            "UPDATE jobs SET status = 'done', result = ?, error = NULL, lease_owner = NULL, lease_until = NULL, finished_at = ?, updated_at = ?
             WHERE id = ? AND attempts = ? AND status = 'failed' AND error = ? AND result IS NULL",
            [$encoded, $now, $now, $job->id, $job->attempts, self::EXPIRED_ERROR],
        );

        return $this->db->affectedRows() === 1;
    }

    /** Fails the attempt: back to the queue after a back-off when $retry and attempts are left, else failed for good. */
    public function fail(Job $job, string $error, bool $retry): bool
    {
        $now = $this->at(0);
        if ($retry && $job->attempts < $job->maxAttempts) {
            return $this->owned($job, "status = 'queued', error = ?, lease_owner = NULL, lease_until = NULL, run_after = ?, updated_at = ?", [$error, $this->at(self::RETRY_SECONDS * $job->attempts), $now]);
        }

        return $this->owned($job, "status = 'failed', error = ?, lease_owner = NULL, lease_until = NULL, finished_at = ?, updated_at = ?", [$error, $now, $now]);
    }

    /** Hands an interrupted job back to the queue, keeping its checkpoint and giving its attempt back. */
    public function release(Job $job): bool
    {
        return $this->owned($job, "status = 'queued', attempts = attempts - 1, lease_owner = NULL, lease_until = NULL, updated_at = ?", [$this->at(0)]);
    }

    /** Deletes jobs that finished more than $days ago; returns how many. */
    public function prune(int $days): int
    {
        $this->db->query("DELETE FROM jobs WHERE status IN ('done', 'failed') AND finished_at < ?", [$this->at(-86400 * $days)]);

        return $this->db->affectedRows();
    }

    /** @param list<mixed> $binds */
    private function owned(Job $job, string $set, array $binds): bool
    {
        $this->db->query(
            "UPDATE jobs SET $set WHERE id = ? AND status = 'running' AND lease_owner = ? AND attempts = ?",
            [...$binds, $job->id, (string)$job->leaseOwner, $job->attempts],
        );

        return $this->db->affectedRows() === 1;
    }

    private function at(int $offset): string
    {
        return gmdate('Y-m-d H:i:s', $this->clock->unix() + $offset);
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed>|null */
    private static function decode(mixed $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $value = json_decode((string)$json, true);

        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $row */
    private static function job(array $row): Job
    {
        return new Job(
            (int)$row['id'],
            (string)$row['type'],
            (string)$row['status'],
            self::decode($row['payload']) ?? [],
            self::decode($row['checkpoint']),
            self::decode($row['result']),
            $row['error'] === null ? null : (string)$row['error'],
            (int)$row['attempts'],
            (int)$row['max_attempts'],
            $row['actor'] === null ? null : (string)$row['actor'],
            $row['lease_owner'] === null ? null : (string)$row['lease_owner'],
        );
    }
}
