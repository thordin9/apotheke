<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

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
        $this->db->query(
            "INSERT INTO jobs (type, status, payload, attempts, max_attempts, actor, run_after, created_at, updated_at) VALUES (?, 'queued', ?, 0, ?, ?, ?, ?, ?)",
            [$type, $json, $maxAttempts, $actor, $runAfter ?? $now, $now, $now],
        );

        return (int)$this->db->insertID();
    }

    public function find(int $id): ?Job
    {
        $row = $this->db->query('SELECT * FROM jobs WHERE id = ?', [$id])->getRowArray();

        return is_array($row) ? self::job($row) : null;
    }

    /** The oldest due job, now leased to $owner; null when none is due. Jobs whose last lease ran out fail first. */
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

    /** @param array<string,mixed> $result */
    public function complete(Job $job, array $result): bool
    {
        $now = $this->at(0);

        return $this->owned($job, "status = 'done', result = ?, error = NULL, lease_owner = NULL, lease_until = NULL, finished_at = ?, updated_at = ?", [self::encode($result), $now, $now]);
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
