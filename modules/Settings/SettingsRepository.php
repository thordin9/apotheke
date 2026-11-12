<?php

declare(strict_types=1);

namespace Apotheke\Settings;

use Apotheke\Core\Database\ReadCache;
use Apotheke\Core\Database\RequestReads;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Storage\LeaseDenied;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Throwable;

/**
 * The `settings` table (name → JSON value) through the Query Builder.
 * Works on SQLite and PostgreSQL. Holds no state of its own: the rows, the
 * roles and the account count are remembered in the request's ReadCache only
 * (Services::readCache(); none when it is not given). Rewrites run as one unit
 * of work (ADR-002) and read the rows fresh.
 */
final class SettingsRepository
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

    /**
     * Raw rows. An empty array when the table is missing (mid-migration),
     * like legacy settings_all().
     *
     * @return array<string,string> name => JSON value
     */
    public function rows(bool $fresh = false): array
    {
        // Null: the read failed (no table yet, no connection), never remembered, so a later call reads again.
        $read = function (): ?array {
            $rows = $this->select(fn () => $this->db->table('settings')->select('name, value')->get(), true);
            if ($rows === null) {
                return null;
            }
            $out = [];
            foreach ($rows as $row) {
                $out[(string)$row['name']] = (string)$row['value'];
            }

            return $out;
        };

        return ($fresh ? $read() : $this->cache->remember($this->db, RequestReads::SETTINGS, ['settings'], $read, self::read(...))) ?? [];
    }

    /** Whether a transaction is open on the connection. */
    public function inTransaction(): bool
    {
        return (int)$this->db->transDepth > 0;
    }

    /**
     * Replace these rows in one unit of work (delete, then insert, like legacy
     * settings_store()). A failed statement, or a failed commit, rolls back
     * and throws WriteFailed (M-25: the connection has DBDebug off).
     *
     * @param array<string,string> $encoded name => JSON value
     * @param \Closure():void|null $guard runs inside this unit, before its writes
     */
    public function replace(array $encoded, string $now, ?\Closure $guard = null): void
    {
        if ($encoded !== []) {
            $this->rewrite(static fn (): array => $encoded, $now, $guard);
        }
    }

    /**
     * Read the rows and replace the ones $plan returns, in one unit of work
     * (legacy secret_rotate(), ADR-002). A UserError from $plan fails the unit
     * and is thrown as it is; any other failure is WriteFailed, as replace().
     *
     * @param \Closure(array<string,string>):array<string,string> $plan raw rows => rows to write
     * @param \Closure():void|null $guard runs inside this unit, before its writes, and a lost
     *        restore fence rolls the unit back with the settings rows
     */
    public function rewrite(\Closure $plan, string $now, ?\Closure $guard = null): void
    {
        try {
            $this->units->run('settings', function (UnitOfWork $uow) use ($plan, $now, $guard): void {
                if ($guard !== null) {
                    $guard();
                }
                foreach ($plan($this->rows(true)) as $name => $value) {
                    $uow->write('DELETE FROM settings WHERE name = ?', [$name], 'settings delete');
                    $uow->write('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)', [$name, $value, $now], 'settings insert');
                }
            });
        } catch (UserError|LeaseDenied $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new WriteFailed('Could not save the settings.', 0, $e);
        }
    }

    /**
     * Roles an account can have (legacy account_roles(): every role but
     * guest), built-in first. Null before migration 0022 adds the table.
     *
     * @return array<string,string>|null role key => name
     */
    public function accountRoles(): ?array
    {
        // The same rows RoleRepository::all() reads (one read for both in a request).
        $rows = $this->cache->remember($this->db, RequestReads::ROLES, ['roles'], fn (): ?array => $this->select(fn () => $this->db->table('roles')
            ->select('role_key, name, perms')->orderBy('builtin', 'DESC')->orderBy('id', 'ASC')->get(), true), self::read(...));
        if ($rows === null) {
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            if ((string)$row['role_key'] !== 'guest') {
                $out[(string)$row['role_key']] = (string)$row['name'];
            }
        }

        return $out;
    }

    /**
     * Number of accounts, for the `list` CLI form. 0 before migration 0018.
     * Moves to the Auth module's user model in the Auth PR.
     */
    public function userCount(): int
    {
        $n = $this->cache->remember($this->db, RequestReads::USER_COUNT, ['users'], function (): ?int {
            $rows = $this->select(fn () => $this->db->table('users')->select('COUNT(*) AS n')->get(), true);

            return $rows === null ? null : (int)($rows[0]['n'] ?? 0);
        }, self::read(...));

        return $n ?? 0;
    }

    /** Whether a read succeeded (ReadCache keeps only those). */
    private static function read(mixed $value): bool
    {
        return $value !== null;
    }

    /**
     * Run a SELECT. A missing table gives [] (or null with $nullWhenMissing):
     * with DBDebug off the driver returns false, with it on it throws.
     *
     * @param callable():(BaseResult<object|resource, object|resource>|false) $query
     * @return list<array<string,mixed>>|null
     */
    private function select(callable $query, bool $nullWhenMissing = false): ?array
    {
        try {
            $result = $query();
        } catch (Throwable) {
            $result = false;
        }
        if (!$result instanceof BaseResult) {
            return $nullWhenMissing ? null : [];
        }
        /** @var list<array<string,mixed>> $rows */
        $rows = $result->getResultArray();

        return $rows;
    }
}
