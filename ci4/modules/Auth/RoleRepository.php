<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Throwable;

/**
 * The `roles` table (legacy roles(), role_has(), role_name()). Before
 * migration 0022 adds the table, the built-in defaults. Holds no state:
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
        } catch (Throwable) {
            $rows = null;
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
