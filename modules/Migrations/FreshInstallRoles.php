<?php

declare(strict_types=1);

namespace Apotheke\Migrations;

/**
 * The built-in roles a new installation starts with: editors download and restore backups, viewers download them.
 * Migration 0022 (shared with 2.6.x) creates the roles with 2.6.x's defaults; the runners apply these only when
 * that migration ran on an empty database in the same run (no schema_migrations rows before it), so an existing
 * installation's roles are never changed on an upgrade. On D1 each migration is its own batch, so the D1 runner sends
 * these in that migration's batch, and an empty database first gets the MARKER table: a later run that finds it
 * finishes a first install that stopped part way as fresh, and the roles batch drops it. The lists are in the roles table's stored form
 * (Auth\Permissions::stored(): "backup" is backup and restore, "backup-only" is download only); a test keeps them
 * equal to Auth\Permissions::ROLE_DEFAULTS.
 */
final class FreshInstallRoles
{
    /** The migration that creates the roles table. */
    public const ROLES_MIGRATION = '0022';

    /** D1 only: present from an empty database's first batch until the roles migration's batch. */
    public const MARKER = 'apotheke_fresh_install';

    /** @var array<string,list<string>> role key => stored permissions */
    public const PERMS = [
        'editor' => ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels', 'backup'],
        'viewer' => ['view', 'export', 'labels', 'backup-only'],
    ];

    /**
     * Whether a run applies them: the database had no migration before it and the run created the roles table.
     *
     * @param list<string> $done the versions the run applied
     */
    public static function applies(bool $fresh, array $done): bool
    {
        return $fresh && in_array(self::ROLES_MIGRATION, $done, true);
    }

    /** @return list<array{sql:string,params:list<string>}> */
    public static function statements(): array
    {
        $out = [];
        foreach (self::PERMS as $key => $perms) {
            $out[] = ['sql' => 'UPDATE roles SET perms = ? WHERE role_key = ? AND builtin = 1', 'params' => [(string)json_encode($perms), $key]];
        }

        return $out;
    }
}
