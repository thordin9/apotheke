<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use Apotheke\Core\Database\BatchExecutor;
use RuntimeException;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Throwable;

/**
 * PHP session storage on the installation database, used only when
 * DB_DRIVER=d1. Locking is one atomic UPDATE and a later SELECT: D1 has no
 * BEGIN, and a read after a queued write in the same batch is refused. SQL
 * never starts with a comment (M-1844). Session ids and data never appear in
 * log or error text.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private const ID = '/^[A-Za-z0-9,-]{22,256}$/D';

    private string $lockToken = '';

    private string $lockedId = '';

    /** @var \Closure():int */
    private readonly \Closure $now;

    /** @var \Closure(int):void */
    private readonly \Closure $wait;

    /**
     * @param (\Closure():int)|null $now
     * @param (\Closure(int):void)|null $wait microseconds
     */
    public function __construct(
        private readonly BatchExecutor $exec,
        private readonly int $lifetime,
        ?\Closure $now = null,
        ?\Closure $wait = null,
        private readonly int $lockSeconds = 10,
        private readonly int $lockAttempts = 50,
        private readonly int $lockWaitUs = 20_000,
    ) {
        if ($lifetime < 1 || $this->lockSeconds < 1 || $this->lockAttempts < 1) {
            throw new RuntimeException('Session lifetime is invalid.');
        }
        $this->now = $now ?? static fn (): int => time();
        $this->wait = $wait ?? static function (int $us): void { usleep($us); };
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->release();

        return true;
    }

    public function read(string $id): string
    {
        if (!$this->valid($id)) {
            return '';
        }
        try {
            return $this->lockAndRead($id);
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && $e->getMessage() === 'Could not read the session.') {
                throw $e;
            }
            throw new RuntimeException('Could not read the session.');
        }
    }

    public function write(string $id, string $data): bool
    {
        if (!$this->valid($id)) {
            return false;
        }
        try {
            $this->store($id, $data);
            $this->clearLock();

            return true;
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && $e->getMessage() === 'Could not store the session.') {
                throw $e;
            }
            throw new RuntimeException('Could not store the session.');
        }
    }

    public function destroy(string $id): bool
    {
        if (!$this->valid($id)) {
            return true;
        }
        try {
            $this->exec->run([['sql' => 'DELETE FROM sessions WHERE id = ?', 'params' => [$id]]]);
            if ($this->lockedId === $id) {
                $this->clearLock();
            }

            return true;
        } catch (Throwable) {
            throw new RuntimeException('Could not remove the session.');
        }
    }

    public function gc(int $max_lifetime): int
    {
        try {
            $this->exec->run([['sql' => 'DELETE FROM sessions WHERE expires_at <= ?', 'params' => [$this->now()]]]);

            return 0;
        } catch (Throwable) {
            throw new RuntimeException('Could not expire idle sessions.');
        }
    }

    public function validateId(string $id): bool
    {
        return $this->exists($id);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        if (!$this->valid($id)) {
            return false;
        }
        try {
            $now = $this->now();
            $this->exec->run([[
                'sql' => 'UPDATE sessions SET expires_at = ? WHERE id = ? AND expires_at > ?',
                'params' => [$now + $this->lifetime, $id, $now],
            ]]);

            return true;
        } catch (Throwable) {
            throw new RuntimeException('Could not store the session.');
        }
    }

    public function exists(string $id): bool
    {
        if (!$this->valid($id)) {
            return false;
        }
        try {
            $rows = $this->exec->query('SELECT 1 AS ok FROM sessions WHERE id = ? AND expires_at > ?', [$id, $this->now()]);

            return $rows !== [];
        } catch (Throwable) {
            throw new RuntimeException('Could not read the session.');
        }
    }

    private function lockAndRead(string $id): string
    {
        $token = bin2hex(random_bytes(16));
        for ($i = 0; $i < $this->lockAttempts; $i++) {
            $now = $this->now();
            $this->exec->run([[
                'sql' => 'UPDATE sessions SET lock_token = ?, locked_until = ? WHERE id = ? AND expires_at > ? AND (lock_token = ? OR locked_until <= ?)',
                'params' => [$token, $now + $this->lockSeconds, $id, $now, '', $now],
            ]]);
            $rows = $this->exec->query('SELECT data, lock_token, expires_at FROM sessions WHERE id = ?', [$id]);
            if ($rows === [] || (int)$rows[0]['expires_at'] <= $now) {
                return '';
            }
            if ((string)$rows[0]['lock_token'] === $token) {
                $this->lockToken = $token;
                $this->lockedId = $id;

                return (string)$rows[0]['data'];
            }
            if ($i + 1 < $this->lockAttempts) {
                $this->wait();
            }
        }

        throw new RuntimeException('Could not read the session.');
    }

    private function store(string $id, string $data): void
    {
        $now = $this->now();
        $expires = $now + $this->lifetime;
        $token = $this->lockedId === $id ? $this->lockToken : '';
        $this->exec->run([[
            'sql' => 'INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?) '
                . 'ON CONFLICT(id) DO UPDATE SET data = excluded.data, expires_at = excluded.expires_at, lock_token = excluded.lock_token, locked_until = excluded.locked_until '
                . 'WHERE sessions.lock_token = ? OR sessions.lock_token = ? OR sessions.locked_until <= ?',
            'params' => [$id, $data, $expires, '', 0, $token, '', $now],
        ]]);
        $rows = $this->exec->query('SELECT data, lock_token FROM sessions WHERE id = ?', [$id]);
        if ($rows === [] || (string)$rows[0]['data'] !== $data || (string)$rows[0]['lock_token'] !== '') {
            throw new RuntimeException('Could not store the session.');
        }
    }

    private function release(): void
    {
        if ($this->lockedId === '' || $this->lockToken === '') {
            $this->clearLock();

            return;
        }
        $id = $this->lockedId;
        $token = $this->lockToken;
        $this->clearLock();
        try {
            $this->exec->run([[
                'sql' => 'UPDATE sessions SET lock_token = ?, locked_until = ? WHERE id = ? AND lock_token = ?',
                'params' => ['', 0, $id, $token],
            ]]);
        } catch (Throwable) {
            // The request is finished; a later request can take an expired lock.
        }
    }

    private function clearLock(): void
    {
        $this->lockToken = '';
        $this->lockedId = '';
    }

    private function valid(string $id): bool
    {
        return preg_match(self::ID, $id) === 1;
    }

    private function now(): int
    {
        return ($this->now)();
    }

    private function wait(): void
    {
        ($this->wait)($this->lockWaitUs);
    }
}
