<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use CodeIgniter\Database\BaseConnection;

/**
 * Sign-in and registration throttling (legacy rate_limit(), rate_limit_clear()
 * and RATE_LIMITS in src/users.php), in the shared auth_attempts table, so
 * legacy and CI4 count the same attempts. Sign-in counts per client address
 * and account (one client cannot use up every account's limit); registration
 * per client address. The key is legacy client_key(): the first 32 hex digits
 * of sha256(address, or address NUL account key). Holds no state.
 */
final class RateLimiter
{
    /** kind => [attempts, window in seconds] */
    public const LIMITS = ['login' => [10, 900], 'register' => [5, 3600]];

    /** Attempts older than this are removed on every check. */
    public const KEEP_SECONDS = 86400;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db, private readonly string $clientAddress, private readonly int $now)
    {
    }

    /** Legacy rate_bucket(). */
    public function bucket(string $kind, ?string $account): string
    {
        $material = $kind === 'login' ? $this->clientAddress . "\0" . UserRepository::key((string)$account) : $this->clientAddress;

        return substr(hash('sha256', $material), 0, 32);
    }

    /** Throws 429 when $kind was tried too often for this client (and, for sign-in, this account); otherwise records the attempt. */
    public function hit(string $kind, ?string $account = null): void
    {
        [$max, $window] = self::LIMITS[$kind];
        $key = $this->bucket($kind, $account);
        Writes::check($this->db->query('DELETE FROM auth_attempts WHERE at < ?', [$this->now - self::KEEP_SECONDS]), 'auth_attempts prune');
        $n = (int)(Writes::rows($this->db, 'SELECT COUNT(*) AS n FROM auth_attempts WHERE kind = ? AND client = ? AND at >= ?', [$kind, $key, $this->now - $window])[0]['n'] ?? 0);
        if ($n >= $max) {
            $scope = $kind === 'login' ? 'for this account from this address' : 'from this address';
            throw new UserError('Too many attempts ' . $scope . ' – please wait ' . (int)ceil($window / 60) . ' minutes and try again.', 429);
        }
        Writes::check($this->db->table('auth_attempts')->insert(['kind' => $kind, 'client' => $key, 'at' => $this->now]), 'auth_attempts insert');
    }

    /** Legacy rate_limit_clear(): after a successful sign-in. */
    public function clear(string $kind, ?string $account = null): void
    {
        Writes::check($this->db->table('auth_attempts')->where('kind', $kind)->where('client', $this->bucket($kind, $account))->delete(), 'auth_attempts clear');
    }
}
