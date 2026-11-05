<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\Exceptions\DatabaseException;
use PDOException;

/**
 * The `users` table, as far as the session checks need it. Works on SQLite and
 * PostgreSQL. Holds no state of its own. Writes run as units of work (ADR-002);
 * RoleAdmin and UserAdmin take the active-admins unit lock (no row lock) for L-110.
 */
final class UserRepository
{
    private readonly UnitsOfWork $units;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(private readonly BaseConnection $db, private readonly SessionEpoch $epoch = new SessionEpoch(), ?UnitsOfWork $units = null)
    {
        $this->units = UnitsOfWork::forSite($db, $units);
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
     * When account $id was made, or null when there is no such account: with the id, an identity no later account
     * shares (N-1594). A failed read throws (WriteFailed, N-1636): a database error is not "no account".
     */
    public function createdAt(int $id): ?string
    {
        try {
            $rows = Writes::rows($this->db, 'SELECT created_at FROM users WHERE id = ?', [$id]);
        } catch (DatabaseException|PDOException $e) {
            throw new WriteFailed('Database read failed', 0, $e);
        }
        $made = $rows[0]['created_at'] ?? null;

        return is_scalar($made) ? (string)$made : null;
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
        if (str_contains($name, "\0")) {
            return null;   // no account name has one, anywhere (N-160, N-221); the driver would cut the bound value at it
        }
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

    /**
     * Every account sorted by name (legacy users_list()), for the Users page.
     * A failed read throws.
     *
     * @return list<array{id:int,username:string,role:string,status:string,created_at:string,last_login_at:?string}>
     */
    public function all(): array
    {
        $out = [];
        foreach (Writes::rows($this->db, 'SELECT id, username, role, status, created_at, last_login_at FROM users ORDER BY username_key, id') as $row) {
            $out[] = ['id' => (int)$row['id'], 'username' => (string)$row['username'], 'role' => (string)$row['role'],
                'status' => (string)$row['status'], 'created_at' => (string)$row['created_at'],
                'last_login_at' => $row['last_login_at'] === null ? null : (string)$row['last_login_at']];
        }

        return $out;
    }

    /** Active accounts with this role (legacy users_count($role): an admin counts once approved). */
    public function countActive(string $role): int
    {
        return (int)(Writes::rows($this->db, "SELECT COUNT(*) AS n FROM users WHERE role = ? AND status = 'active'", [$role])[0]['n'] ?? 0);
    }

    /** @return list<string> the role of every active account (legacy user_managers_count()) */
    public function activeRoles(): array
    {
        return array_map(static fn (array $r): string => (string)$r['role'], Writes::rows($this->db, "SELECT role FROM users WHERE status = 'active'"));
    }

    /**
     * $fn in one transaction (Writes::transaction; nesting is flat). Prefer
     * underActiveAdminGuard() for admin changes (unit lock, no row lock).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function atomically(callable $fn): mixed
    {
        return Writes::transaction($this->db, $fn);
    }

    /**
     * Run $fn under the active-admins unit lock (L-110): one unit, no extra
     * row lock. RoleAdmin and UserAdmin use this so last-admin checks and the
     * write serialize with each other.
     *
     * @template T
     * @param callable(\Apotheke\Core\Database\UnitOfWork):T $fn
     * @return T
     */
    public function underActiveAdminGuard(callable $fn): mixed
    {
        return $this->units->run('users', $fn, 'active-admins');
    }


    /** Legacy user_approve(). */
    public function approve(int $id): void
    {
        // N-1769: same unit key as admin changes so a caller that skips the guard still serialises.
        $this->units->run('users', function (UnitOfWork $uow) use ($id): void {
            $uow->write("UPDATE users SET status = 'active' WHERE id = ?", [$id], 'users approve');
        }, 'active-admins');
    }

    /** Legacy user_set_role(). */
    public function setRole(int $id, string $role): void
    {
        $this->units->run('users', function (UnitOfWork $uow) use ($id, $role): void {
            // M-1764 / N-1769: active-admins key; demotion keeps another active admin.
            $uow->write(
                "UPDATE users SET role = ? WHERE id = ? AND ("
                . " ? = 'admin' OR role <> 'admin' OR status <> 'active'"
                . " OR EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?))",
                [$role, $id, $role, $id],
                'users role',
                1,
                static fn (): UserError => new UserError('That change would leave the install without an admin.', 409),
            );
        }, 'active-admins');
    }

    /** Legacy user_rename(). The caller has validated the name. */
    public function rename(int $id, string $name): void
    {
        $this->units->run('users', function (UnitOfWork $uow) use ($id, $name): void {
            $uow->write('UPDATE users SET username = ?, username_key = ? WHERE id = ?', [$name, self::key($name), $id], 'users rename');
        }, (string)$id);
    }

    /** Legacy user_delete(). */
    public function delete(int $id): void
    {
        $this->units->run('users', function (UnitOfWork $uow) use ($id): void {
            $uow->write(
                "DELETE FROM users WHERE id = ? AND ("
                . " role <> 'admin' OR status <> 'active'"
                . " OR EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?))",
                [$id, $id],
                'users delete',
                1,
                static fn (): UserError => new UserError('That change would leave the install without an admin.', 409),
            );
        }, 'active-admins');
    }

    /** A new account (legacy user_create()'s INSERT); returns its id. The caller has validated every value. */
    public function create(string $name, string $hash, string $role, string $status, string $now): int
    {
        $ref = $this->units->run('users', function (UnitOfWork $uow) use ($name, $hash, $role, $status, $now): Ref {
            return $uow->insert('users', [
                'username' => $name, 'username_key' => self::key($name), 'password_hash' => $hash,
                'role' => $role, 'status' => $status, 'created_at' => $now,
            ], 'users insert');
        });

        return $ref->id();
    }

    /** Legacy login(): the sign-in time. */
    public function touchLastLogin(int $id, string $now): void
    {
        $this->units->run('users', function (UnitOfWork $uow) use ($id, $now): void {
            $uow->update('users', ['last_login_at' => $now], ['id' => $id], 'users last login');
        }, (string)$id);
    }

    /** Legacy user_verify(): a stronger hash for the same password. The session epoch is not renewed (as legacy). */
    public function rehash(int $id, string $hash): void
    {
        $this->units->run('users', function (UnitOfWork $uow) use ($id, $hash): void {
            $uow->update('users', ['password_hash' => $hash], ['id' => $id], 'users rehash');
        }, (string)$id);
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
        $this->units->run('users', function (UnitOfWork $uow) use ($id, $hash, $epoch): void {
            $uow->update('users', ['password_hash' => $hash, 'session_epoch' => $epoch], ['id' => $id], 'users password');
        }, (string)$id);

        return $epoch;
    }

    /** Store a new session epoch (legacy user_renew_epoch()). Returns it. */
    public function renewSessionEpoch(int $id): string
    {
        $epoch = $this->epoch->fresh();
        $this->units->run('users', function (UnitOfWork $uow) use ($id, $epoch): void {
            $uow->update('users', ['session_epoch' => $epoch], ['id' => $id], 'users session epoch');
        }, (string)$id);

        return $epoch;
    }
}
