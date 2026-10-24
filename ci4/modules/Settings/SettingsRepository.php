<?php

declare(strict_types=1);

namespace Apotheke\Settings;

use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * The `settings` table (name → JSON value) through the Query Builder.
 * Works on SQLite and PostgreSQL. Holds no state of its own.
 */
final class SettingsRepository
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Raw rows. An empty array when the table is missing (mid-migration),
     * like legacy settings_all().
     *
     * @return array<string,string> name => JSON value
     */
    public function rows(): array
    {
        $result = $this->select(fn () => $this->db->table('settings')->select('name, value')->get());
        $out = [];
        foreach ($result as $row) {
            $out[(string)$row['name']] = (string)$row['value'];
        }

        return $out;
    }

    /**
     * Replace these rows in one transaction (delete, then insert, like legacy
     * settings_store()).
     *
     * @param array<string,string> $encoded name => JSON value
     */
    public function replace(array $encoded, string $now): void
    {
        if ($encoded === []) {
            return;
        }
        $this->db->transBegin();
        try {
            foreach ($encoded as $name => $value) {
                $deleted = $this->db->table('settings')->where('name', $name)->delete();
                $inserted = $this->db->table('settings')->insert(['name' => $name, 'value' => $value, 'updated_at' => $now]);
                if ($deleted === false || $inserted === false) {
                    throw new RuntimeException('Could not save the settings.');
                }
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e instanceof RuntimeException && $e->getMessage() === 'Could not save the settings.'
                ? $e : new RuntimeException('Could not save the settings.', 0, $e);
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
        $rows = $this->select(fn () => $this->db->table('roles')->select('role_key, name')
            ->orderBy('builtin', 'DESC')->orderBy('id', 'ASC')->get(), true);
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
        $rows = $this->select(fn () => $this->db->table('users')->select('COUNT(*) AS n')->get());

        return (int)($rows[0]['n'] ?? 0);
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
