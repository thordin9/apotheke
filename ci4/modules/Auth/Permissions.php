<?php

declare(strict_types=1);

namespace Apotheke\Auth;

/**
 * Legacy src/roles.php's permission list and built-in role defaults. A golden
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
        'backup'    => ['Backup', 'download backups and restore them'],
    ];

    /** @var array<string,array{0:string,1:list<string>}> role key => [name, permissions], before migration 0022 */
    public const ROLE_DEFAULTS = [
        'admin'  => ['Admin', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels', 'settings', 'users', 'backup']],
        'editor' => ['Editor', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels']],
        'viewer' => ['Viewer', ['view', 'export', 'labels']],
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
}
