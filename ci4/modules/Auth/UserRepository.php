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
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * One account (legacy user_get()), or null when there is none or the table
     * is missing (before migration 0018).
     *
     * @return array{id:int,username:string,role:string,status:string,password_hash:string}|null
     */
    public function find(int $id): ?array
    {
        try {
            $result = $this->db->table('users')->select('id, username, role, status, password_hash')->where('id', $id)->get();
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
        ];
    }

    public function setPasswordHash(int $id, string $hash): void
    {
        if ($this->db->table('users')->where('id', $id)->update(['password_hash' => $hash]) === false) {
            throw new RuntimeException('Could not save the account.');
        }
    }
}
