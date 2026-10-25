<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\Writes;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\Exceptions\DatabaseException;
use PDOException;

/**
 * The `roles` table (legacy roles(), role_has(), role_name()). Before
 * migration 0022 adds the table, the built-in defaults: a database error
 * (missing table, no connection) falls back to them, as legacy does on a
 * PDOException; any other error propagates. Holds no state:
 * every call reads the table again, so a shared instance is safe across
 * requests.
 */
final class RoleRepository
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return array<string,array{name:string,perms:list<string>}> role key => name and permissions, built-in first */
    public function all(): array
    {
        $rows = null;
        try {
            $result = $this->db->table('roles')->select('role_key, name, perms')
                ->orderBy('builtin', 'DESC')->orderBy('id', 'ASC')->get();
            $rows = $result instanceof BaseResult ? $result->getResultArray() : null;
        } catch (DatabaseException|PDOException) {
            $rows = null;   // before migration 0022, or no connection
        }
        $out = [];
        if ($rows === null) {
            foreach (Permissions::ROLE_DEFAULTS as $key => [$name, $perms]) {
                $out[$key] = ['name' => $name, 'perms' => $perms];
            }

            return $out;
        }
        foreach ($rows as $row) {
            $key = (string)$row['role_key'];
            $perms = json_decode((string)$row['perms'], true);
            $out[$key] = ['name' => (string)$row['name'], 'perms' => Permissions::clean($key, is_array($perms) ? $perms : [])];
        }

        return $out;
    }

    /**
     * Legacy roles() for the roles editor: every role, built-in first, with
     * whether it is built in and how many accounts have it. A failed read
     * throws (the editor needs migration 0022).
     *
     * @return array<string,array{key:string,name:string,builtin:bool,users:int,perms:list<string>}>
     */
    public function listed(): array
    {
        $counts = [];
        foreach (Writes::rows($this->db, 'SELECT role, COUNT(*) AS n FROM users GROUP BY role') as $row) {
            $counts[(string)$row['role']] = (int)$row['n'];
        }
        $out = [];
        foreach (Writes::rows($this->db, 'SELECT role_key, name, perms, builtin FROM roles ORDER BY builtin DESC, id') as $row) {
            $key = (string)$row['role_key'];
            $perms = json_decode((string)$row['perms'], true);
            $out[$key] = ['key' => $key, 'name' => (string)$row['name'], 'builtin' => (bool)$row['builtin'], 'users' => $counts[$key] ?? 0,
                'perms' => Permissions::clean($key, is_array($perms) ? $perms : [])];
        }

        return $out;
    }

    /**
     * Legacy role_create()'s INSERT. The caller has validated every value.
     *
     * @param list<string> $perms
     */
    public function create(string $key, string $name, array $perms, string $now): void
    {
        Writes::check($this->db->query('INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?,?,?,0,?)',
            [$key, $name, (string)json_encode($perms), $now]), 'roles insert');
    }

    /**
     * Legacy role_update()'s UPDATE.
     *
     * @param list<string> $perms
     */
    public function update(string $key, string $name, array $perms): void
    {
        Writes::check($this->db->query('UPDATE roles SET name = ?, perms = ? WHERE role_key = ?', [$name, (string)json_encode($perms), $key]), 'roles update');
    }

    /** Legacy role_delete(): its accounts move to $moveTo and the role goes, in one transaction. */
    public function delete(string $key, string $moveTo): void
    {
        Writes::transaction($this->db, function () use ($key, $moveTo): void {
            Writes::check($this->db->query('UPDATE users SET role = ? WHERE role = ?', [$moveTo, $key]), 'users role move');
            Writes::check($this->db->query('DELETE FROM roles WHERE role_key = ?', [$key]), 'roles delete');
        });
    }

    /** Legacy roles_reset_builtin(): the built-in roles' names and permissions back to the defaults (custom roles stay). */
    public function resetBuiltin(): void
    {
        Writes::transaction($this->db, function (): void {
            foreach (Permissions::ROLE_DEFAULTS as $key => [$name, $perms]) {
                Writes::check($this->db->query('UPDATE roles SET name = ?, perms = ? WHERE role_key = ?', [$name, (string)json_encode($perms), $key]), 'roles reset');
            }
        });
    }

    /** Legacy role_has(): admin has everything; an unknown role has nothing. */
    public function has(string $role, string $perm): bool
    {
        if ($role === 'admin') {
            return true;
        }

        return in_array($perm, $this->all()[$role]['perms'] ?? [], true);
    }

    /** Legacy role_name(). */
    public function name(string $role): string
    {
        return $this->all()[$role]['name'] ?? ucfirst($role);
    }
}
