<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;

/**
 * Sign-in and registration throttling (legacy rate_limit(), rate_limit_clear()
 * and RATE_LIMITS in 2.x users.php), in the shared auth_attempts table, so
 * legacy and CI4 count the same attempts. Sign-in counts per client address
 * and account (one client cannot use up every account's limit) and, with a
 * higher cap, per client address for every name (login_address); registration
 * per client address. The key is legacy client_key(): the first 32 hex digits
 * of sha256(address, or address NUL account key). Holds no state.
 */
final class RateLimiter
{
    /** kind => [attempts, window in seconds] */
    public const LIMITS = ['login' => [10, 900], 'login_address' => [30, 900], 'register' => [5, 3600]];

    /** Attempts older than this are removed on every check. */
    public const KEEP_SECONDS = 86400;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db, private readonly string $clientAddress, private readonly int $now)
    {
    }

    /** Legacy rate_bucket(). */
    public function bucket(string $kind, ?string $account): string
    {
        $material = $kind === 'login' ? $this->clientAddress . "\0" . UserRepository::key((string)$account) : self::addressScope($this->clientAddress);

        return substr(hash('sha256', $material), 0, 32);
    }

    /**
     * Legacy address_scope(): per-address limits count an IPv6 client by its
     * /64. IPv4, and IPv6 forms that carry an IPv4 address in their last 32
     * bits (::ffff:0:0/96, ::/96, 64:ff9b::/96), keep the whole address as
     * given. Anything else is returned unchanged.
     */
    public static function addressScope(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $bin = (string)inet_pton($ip);
        if (in_array(substr($bin, 0, 12), [str_repeat("\0", 10) . "\xff\xff", str_repeat("\0", 12), "\x00\x64\xff\x9b" . str_repeat("\0", 8)], true)) {
            return $ip;
        }

        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Throws 429 when $kind was tried too often for this client (and, for sign-in, this account); otherwise records the attempt and returns its row id. */
    public function hit(string $kind, ?string $account = null): int
    {
        [$max, $window] = self::LIMITS[$kind];
        $key = $this->bucket($kind, $account);
        Writes::check($this->db->query('DELETE FROM auth_attempts WHERE at < ?', [$this->now - self::KEEP_SECONDS]), 'auth_attempts prune');
        // Record first, then count every attempt in the window including this one, so requests at the same time
        // see each other and no more than $max get through (at the limit, both may be refused). A refused attempt
        // takes its row back.
        Writes::check($this->db->table('auth_attempts')->insert(['kind' => $kind, 'client' => $key, 'at' => $this->now]), 'auth_attempts insert');
        $mine = (int)$this->db->insertID();
        $result = $this->db->query('SELECT COUNT(*) AS n FROM auth_attempts WHERE kind = ? AND client = ? AND at >= ?', [$kind, $key, $this->now - $window]);
        if (!$result instanceof BaseResult) {
            throw new WriteFailed('Database read failed');
        }
        $n = (int)($result->getRowArray()['n'] ?? 0);
        $result->freeResult();   // SQLite: end the read before the write below
        if ($n > $max) {
            Writes::check($this->db->query('DELETE FROM auth_attempts WHERE id = ?', [$mine]), 'auth_attempts release');
            $scope = $kind === 'login' ? 'for this account from this address' : 'from this address';
            throw new UserError('Too many attempts ' . $scope . ' – please wait ' . (int)ceil($window / 60) . ' minutes and try again.', 429);
        }

        return $mine;
    }

    /** Legacy rate_limit_forget(): take back one recorded attempt (a success's own row in the per-address count). */
    public function forget(int $id): void
    {
        Writes::check($this->db->query('DELETE FROM auth_attempts WHERE id = ?', [$id]), 'auth_attempts forget');
    }

    /** Legacy rate_limit_clear(): after a successful sign-in. */
    public function clear(string $kind, ?string $account = null): void
    {
        Writes::check($this->db->table('auth_attempts')->where('kind', $kind)->where('client', $this->bucket($kind, $account))->delete(), 'auth_attempts clear');
    }
}
