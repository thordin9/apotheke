<?php

declare(strict_types=1);

namespace Apotheke\Auth;

/**
 * Legacy roles.php's permission list and built-in role defaults. A golden
 * test keeps both equal to legacy PERMISSIONS and ROLE_DEFAULTS.
 */
final class Permissions
{
    /** Needed by nothing: the sign-in pages. */
    public const PUBLIC = 'public';

    /** Any signed-in account, not the guest or the anonymous admin (own account pages). */
    public const SELF = 'self';

    /** A route legacy answers before its permission check (health, branding): the route's `permission` option. */
    public const NONE = 'none';

    /** A routed request whose route records no permission: refused to everyone, the admin included. */
    public const UNMAPPED = 'unmapped';

    /** The backup page: backup or restore (it shows only what the account may do). */
    public const BACKUP_OR_RESTORE = 'backup-or-restore';

    /** @var array<string,array{0:string,1:string}> key => [name, description] */
    public const ALL = [
        'view'      => ['View', 'look at items, locations, history and photos'],
        'create'    => ['Add items', 'new items and copies'],
        'edit'      => ['Edit', 'change items, photos, notes, quantities, locations and categories'],
        'delete'    => ['Delete', 'delete items (undo still works)'],
        'move'      => ['Move', 'change an item’s location'],
        'bulk'      => ['Bulk actions', 'select several items and act on all of them'],
        'lend'      => ['Lend', 'lend and return items'],
        'import'    => ['Import', 'CSV import'],
        'export'    => ['Export', 'CSV export'],
        'stocktake' => ['Stocktake', 'run location stocktakes'],
        'labels'    => ['Labels', 'print QR labels'],
        'settings'  => ['Settings', 'the Control Panel (everything but users)'],
        'users'     => ['Users', 'accounts, roles and sign-in options'],
        'backup'    => ['Backup', 'download a backup of the whole catalog, and the kept ones'],
        'restore'   => ['Restore', 'replace the whole catalog with a backup, for everyone'],
    ];

    /**
     * How the roles table keeps backup and restore. The table is shared with 2.6.x, where "backup" means both, so a
     * stored "backup" stands for both here too: every role that had it before the split has restore as well, and
     * the table is never rewritten for that. A role with only one of them keeps it under a name 2.6.x does not
     * know (it drops unknown names), so a rollback never gives a role more than it has here.
     */
    public const STORED_BACKUP_ONLY = 'backup-only';

    public const STORED_RESTORE_ONLY = 'restore-only';

    /**
     * Role key => [name, permissions]: the built-in roles before migration 0022 and after "reset built-in roles".
     * Editors may download and restore backups, viewers may download them; the guest may do neither.
     *
     * @var array<string,array{0:string,1:list<string>}>
     */
    public const ROLE_DEFAULTS = [
        'admin'  => ['Admin', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels', 'settings', 'users', 'backup', 'restore']],
        'editor' => ['Editor', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels', 'backup', 'restore']],
        'viewer' => ['Viewer', ['view', 'export', 'labels', 'backup']],
        'guest'  => ['Guest', ['view']],
    ];

    /**
     * Legacy role_perms_clean(): admin has every permission; any other role
     * keeps the known permissions it lists, in PERMISSIONS order.
     *
     * @param array<array-key,mixed> $perms
     * @return list<string>
     */
    public static function clean(string $role, array $perms): array
    {
        if ($role === 'admin') {
            return array_keys(self::ALL);
        }

        return array_values(array_filter(array_keys(self::ALL), static fn (string $p): bool => in_array($p, $perms, true)));
    }

    /**
     * A role's permissions as the roles table keeps them (STORED_BACKUP_ONLY): what clean() reads back.
     *
     * @param list<string> $perms
     * @return list<string>
     */
    public static function stored(array $perms): array
    {
        $backup = in_array('backup', $perms, true);
        $restore = in_array('restore', $perms, true);
        $out = [];
        foreach ($perms as $p) {
            if ($p === 'restore') {
                continue;
            }
            $out[] = $p === 'backup' && !$restore ? self::STORED_BACKUP_ONLY : $p;
        }
        if ($restore && !$backup) {
            $out[] = self::STORED_RESTORE_ONLY;
        }

        return $out;
    }

    /**
     * The permissions a stored list stands for: "backup" is backup and restore (as on 2.6.x), STORED_BACKUP_ONLY
     * and STORED_RESTORE_ONLY one of them.
     *
     * @param array<array-key,mixed> $stored
     * @return list<mixed>
     */
    public static function fromStored(array $stored): array
    {
        $out = [];
        foreach (array_values($stored) as $p) {
            if ($p === 'backup') {
                array_push($out, 'backup', 'restore');
            } elseif ($p === self::STORED_BACKUP_ONLY) {
                $out[] = 'backup';
            } elseif ($p === self::STORED_RESTORE_ONLY || $p === 'restore') {
                $out[] = 'restore';
            } else {
                $out[] = $p;
            }
        }

        return $out;
    }
}
