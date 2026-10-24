<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use RuntimeException;
use Throwable;

/**
 * The `users` table, as far as the session checks need it. Works on SQLite and
 * PostgreSQL. Holds no state of its own.
 */
final class UserRepository
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db, private readonly SessionEpoch $epoch = new SessionEpoch())
    {
    }

    /**
     * One account (legacy user_get()), or null when there is none or the table
     * is missing (before migration 0018).
     *
     * session_epoch is '' when the account has none or migration 0028 has not
     * run yet (the row is read whole, so a missing column is not an error).
     *
     * @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}|null
     */
    public function find(int $id): ?array
    {
        try {
            $result = $this->db->table('users')->select('*')->where('id', $id)->get();
        } catch (Throwable) {
            return null;
        }
        if (!$result instanceof BaseResult) {
            return null;
        }
        $row = $result->getRowArray();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'role' => (string)$row['role'],
            'status' => (string)$row['status'],
            'password_hash' => (string)$row['password_hash'],
            'session_epoch' => is_scalar($row['session_epoch'] ?? null) ? (string)$row['session_epoch'] : '',
        ];
    }

    /**
     * A new password hash, which also stores a new session epoch (legacy
     * user_set_password() on main): every session of the account is signed
     * out. Returns the new epoch for a caller that keeps its own session.
     */
    public function setPasswordHash(int $id, string $hash): string
    {
        $epoch = $this->epoch->fresh();
        if ($this->db->table('users')->where('id', $id)->update(['password_hash' => $hash, 'session_epoch' => $epoch]) === false) {
            throw new RuntimeException('Could not save the account.');
        }

        return $epoch;
    }

    /** Store a new session epoch (legacy user_renew_epoch()). Returns it. */
    public function renewSessionEpoch(int $id): string
    {
        $epoch = $this->epoch->fresh();
        if ($this->db->table('users')->where('id', $id)->update(['session_epoch' => $epoch]) === false) {
            throw new RuntimeException('Could not save the account.');
        }

        return $epoch;
    }
}
