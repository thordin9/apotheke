<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\ReadCache;
use Apotheke\Core\Database\RequestReads;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\Exceptions\DatabaseException;
use PDOException;

/**
 * The `roles` table (legacy roles(), role_has(), role_name()). Before
 * migration 0022 adds the table, the built-in defaults: a database error
 * (missing table, no connection) falls back to them, as legacy does on a
 * PDOException; any other error propagates. Holds no state: all() is
 * remembered only in the request's ReadCache (Services::readCache()) until the
 * next write to roles, so a shared instance is safe across requests.
 */
final class RoleRepository
{
    private readonly UnitsOfWork $units;

    private readonly ReadCache $cache;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(private readonly BaseConnection $db, ?UnitsOfWork $units = null, ?ReadCache $cache = null)
    {
        $this->units = UnitsOfWork::forSite($db, $units);
        $this->cache = $cache ?? ReadCache::off();
    }

    /** @return array<string,array{name:string,perms:list<string>}> role key => name and permissions, built-in first */
    public function all(): array
    {
        // The same rows SettingsRepository::accountRoles() reads (one read for both in a request).
        $rows = $this->cache->remember($this->db, RequestReads::ROLES, ['roles'], function (): ?array {
            try {
                $result = $this->db->table('roles')->select('role_key, name, perms')
                    ->orderBy('builtin', 'DESC')->orderBy('id', 'ASC')->get();

                return $result instanceof BaseResult ? $result->getResultArray() : null;
            } catch (DatabaseException|PDOException) {
                return null;   // before migration 0022, or no connection
            }
        }, static fn (?array $rows): bool => $rows !== null);   // a failed read is read again next time
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
            $out[$key] = ['name' => (string)$row['name'], 'perms' => Permissions::clean($key, Permissions::fromStored(is_array($perms) ? $perms : []))];
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
                'perms' => Permissions::clean($key, Permissions::fromStored(is_array($perms) ? $perms : []))];
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
        $this->units->run('roles', function (UnitOfWork $uow) use ($key, $name, $perms, $now): void {
            $uow->write('INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?,?,?,0,?)',
                [$key, $name, (string)json_encode(Permissions::stored($perms)), $now], 'roles insert');
        }, $key);
    }

    /**
     * Legacy role_update()'s UPDATE.
     *
     * @param list<string> $perms
     */
    public function update(string $key, string $name, array $perms): void
    {
        $this->units->run('roles', function (UnitOfWork $uow) use ($key, $name, $perms): void {
            $uow->write('UPDATE roles SET name = ?, perms = ? WHERE role_key = ?', [$name, (string)json_encode(Permissions::stored($perms)), $key], 'roles update');
        }, $key);
    }

    /** Legacy role_delete(): its accounts move to $moveTo and the role goes, as one unit of work (ADR-002, batch). */
    public function delete(string $key, string $moveTo): void
    {
        $this->units->run('roles', function (UnitOfWork $uow) use ($key, $moveTo): void {
            $uow->write('UPDATE users SET role = ? WHERE role = ?', [$moveTo, $key], 'users role move');
            $uow->write('DELETE FROM roles WHERE role_key = ?', [$key], 'roles delete');
        });
    }

    /** Legacy roles_reset_builtin(): the built-in roles' names and permissions back to the defaults (custom roles stay), as one batch. */
    public function resetBuiltin(): void
    {
        $this->units->run('roles', function (UnitOfWork $uow): void {
            foreach (Permissions::ROLE_DEFAULTS as $key => [$name, $perms]) {
                $uow->write('UPDATE roles SET name = ?, perms = ? WHERE role_key = ?', [$name, (string)json_encode(Permissions::stored($perms)), $key], 'roles reset');
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
