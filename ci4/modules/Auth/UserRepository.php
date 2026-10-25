<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\Writes;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\Exceptions\DatabaseException;
use PDOException;

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
     * cannot be read (before migration 0018, or a database error; #93). Any
     * other error is a bug and is not hidden.
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
        } catch (DatabaseException|PDOException) {
            return null;
        }
        if (!$result instanceof BaseResult) {
            return null;
        }
        $row = $result->getRowArray();

        return is_array($row) ? self::row($row) : null;
    }

    /**
     * The account with this name (legacy user_by_name(): the trimmed,
     * lower-cased key), or null. A failed read throws (WriteFailed): sign-in
     * and registration must not read a broken table as "no account".
     *
     * @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}|null
     */
    public function findByName(string $name): ?array
    {
        $rows = Writes::rows($this->db, 'SELECT * FROM users WHERE username_key = ?', [self::key($name)]);

        return $rows === [] ? null : self::row($rows[0]);
    }

    /** Legacy user_key(). */
    public static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** Number of accounts (legacy users_count()). A failed read throws. */
    public function count(): int
    {
        return (int)(Writes::rows($this->db, 'SELECT COUNT(*) AS n FROM users')[0]['n'] ?? 0);
    }

    /** A new account (legacy user_create()'s INSERT); returns its id. The caller has validated every value. */
    public function create(string $name, string $hash, string $role, string $status, string $now): int
    {
        Writes::check($this->db->table('users')->insert([
            'username' => $name, 'username_key' => self::key($name), 'password_hash' => $hash,
            'role' => $role, 'status' => $status, 'created_at' => $now,
        ]), 'users insert');

        return (int)$this->db->insertID();
    }

    /** Legacy login(): the sign-in time. */
    public function touchLastLogin(int $id, string $now): void
    {
        Writes::check($this->db->table('users')->where('id', $id)->update(['last_login_at' => $now]), 'users last login');
    }

    /** Legacy user_verify(): a stronger hash for the same password. The session epoch is not renewed (as legacy). */
    public function rehash(int $id, string $hash): void
    {
        Writes::check($this->db->table('users')->where('id', $id)->update(['password_hash' => $hash]), 'users rehash');
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}
     */
    private static function row(array $row): array
    {
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
        Writes::check($this->db->table('users')->where('id', $id)->update(['password_hash' => $hash, 'session_epoch' => $epoch]), 'users password');

        return $epoch;
    }

    /** Store a new session epoch (legacy user_renew_epoch()). Returns it. */
    public function renewSessionEpoch(int $id): string
    {
        $epoch = $this->epoch->fresh();
        Writes::check($this->db->table('users')->where('id', $id)->update(['session_epoch' => $epoch]), 'users session epoch');

        return $epoch;
    }
}
