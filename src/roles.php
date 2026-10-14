<?php
declare(strict_types=1);

/*
 * Roles and permissions. Every route and action needs one permission (see route_perm()); a role is a named set of
 * permissions stored in `roles` (migration 0022). Built-in roles: admin (always everything, cannot be changed or
 * deleted), editor, viewer and guest (what visitors who are not signed in may do while guest browsing is allowed).
 * Admins add their own roles in Control Panel → Users & sign-in.
 */

const PERMISSIONS = [
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
const ROLE_DEFAULTS = [
    'admin'  => ['Admin', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels', 'settings', 'users', 'backup']],
    'editor' => ['Editor', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels']],
    'viewer' => ['Viewer', ['view', 'export', 'labels']],
    'guest'  => ['Guest', ['view']],
];
const ROLE_KEY_MAX = 30;

/** All roles, built-in first: key => ['key','name','perms'=>[...],'builtin'=>bool,'users'=>n]. Cached per request. */
function roles(bool $reset = false): array
{
    static $cache = null;
    if ($reset) { $cache = null; return []; }
    if ($cache !== null) return $cache;
    $out = [];
    try {
        $n = db()->query('SELECT role, COUNT(*) AS n FROM users GROUP BY role')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (db()->query('SELECT role_key, name, perms, builtin FROM roles ORDER BY builtin DESC, id')->fetchAll() as $r) {
            $perms = json_decode((string)$r['perms'], true);
            $out[$r['role_key']] = ['key' => $r['role_key'], 'name' => $r['name'], 'builtin' => (bool)$r['builtin'], 'users' => (int)($n[$r['role_key']] ?? 0),
                'perms' => role_perms_clean($r['role_key'], is_array($perms) ? $perms : [])];
        }
    } catch (PDOException) {
        // before migration 0022
        foreach (ROLE_DEFAULTS as $k => [$name, $perms]) $out[$k] = ['key' => $k, 'name' => $name, 'builtin' => true, 'users' => 0, 'perms' => $perms];
    }
    return $cache = $out;
}

function role_perms_clean(string $key, array $perms): array
{
    if ($key === 'admin') return array_keys(PERMISSIONS);
    return array_values(array_filter(array_keys(PERMISSIONS), fn($p) => in_array($p, $perms, true)));
}

function role_get(string $key): ?array
{
    return roles()[$key] ?? null;
}

function role_name(string $key): string
{
    return roles()[$key]['name'] ?? ucfirst($key);
}

/** Roles an account can have (everything but guest). */
function account_roles(): array
{
    return array_filter(roles(), fn($r) => $r['key'] !== 'guest');
}

/** Does $role grant $perm? Admin always; an unknown role grants nothing. */
function role_has(string $role, string $perm): bool
{
    if ($role === 'admin') return true;
    return in_array($perm, roles()[$role]['perms'] ?? [], true);
}

function role_key_from(string $name): string
{
    $k = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
    return substr($k, 0, ROLE_KEY_MAX);
}

function role_validate_perms(array $perms): array
{
    foreach ($perms as $p) if (!is_string($p) || !isset(PERMISSIONS[$p])) throw new UserError('Unknown permission “' . (is_string($p) ? $p : '?') . '”.', 422);
    return role_perms_clean('', $perms);
}

/** Active accounts that may manage users (admins and any custom role with the users permission). */
function user_managers_count(?array $override = null): int
{
    $c = 0;
    foreach (db()->query("SELECT role FROM users WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $r) {
        $perms = $override !== null && array_key_exists($r, $override) ? $override[$r] : (roles()[$r]['perms'] ?? []);
        if ($r === 'admin' || in_array('users', $perms, true)) $c++;
    }
    return $c;
}

function role_create(string $name, array $perms): string
{
    $name = clean_line($name, 60);
    if ($name === '') throw new UserError('Enter a name for the role.', 422);
    $key = role_key_from($name);
    if ($key === '') throw new UserError('Use letters or digits in the role name.', 422);
    foreach (roles() as $r) if ($r['key'] === $key || mb_strtolower($r['name']) === mb_strtolower($name)) throw new UserError('A role “' . $r['name'] . '” exists already.', 409);
    db()->prepare('INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?,?,?,0,?)')->execute([$key, $name, json_encode(role_validate_perms($perms)), now_utc()]);
    roles(true);
    return $key;
}

/** Change a role's name (custom roles only) and permissions. Admin cannot be changed; lockout is refused. */
function role_update(string $key, ?string $name, array $perms): void
{
    $r = role_get($key) ?? throw new UserError('Role not found.', 404);
    if ($key === 'admin') throw new UserError('The admin role always has every permission.', 409);
    $perms = role_validate_perms($perms);
    if ($key === 'guest') $perms = array_values(array_diff($perms, ['users', 'settings', 'backup']));
    if (in_array('users', $r['perms'], true) && !in_array('users', $perms, true) && user_managers_count() > 0 && user_managers_count([$key => $perms]) === 0) {
        throw new UserError('Nobody could manage users any more – keep the Users permission or make someone an admin first.', 409);
    }
    $name = $name === null || $r['builtin'] ? $r['name'] : clean_line($name, 60);
    if ($name === '') throw new UserError('Enter a name for the role.', 422);
    foreach (roles() as $o) if ($o['key'] !== $key && mb_strtolower($o['name']) === mb_strtolower($name)) throw new UserError('A role “' . $o['name'] . '” exists already.', 409);
    db()->prepare('UPDATE roles SET name = ?, perms = ? WHERE role_key = ?')->execute([$name, json_encode($perms), $key]);
    roles(true);
}

/** Delete a custom role; its accounts move to $moveTo (default viewer). */
function role_delete(string $key, string $moveTo = 'viewer'): void
{
    $r = role_get($key) ?? throw new UserError('Role not found.', 404);
    if ($r['builtin']) throw new UserError('Built-in roles cannot be deleted.', 409);
    if ($moveTo === $key || !isset(account_roles()[$moveTo])) throw new UserError('Choose another role for its accounts.', 422);
    $after = roles()[$moveTo]['perms'];
    $over = [$key => $after];
    if (user_managers_count() > 0 && user_managers_count($over) === 0) throw new UserError('Nobody could manage users any more – make someone an admin first.', 409);
    db_tx(function (PDO $pdo) use ($key, $moveTo) {
        $pdo->prepare('UPDATE users SET role = ? WHERE role = ?')->execute([$moveTo, $key]);
        $pdo->prepare('DELETE FROM roles WHERE role_key = ?')->execute([$key]);
    });
    roles(true);
    $set = setting('auth_register_role');
    if ($set === $key) settings_store(['auth_register_role' => 'viewer']);
}

/** Restore the built-in roles' permissions (CLI recovery). */
function roles_reset_builtin(): void
{
    $st = db()->prepare('UPDATE roles SET name = ?, perms = ? WHERE role_key = ?');
    foreach (ROLE_DEFAULTS as $k => [$name, $perms]) $st->execute([$name, json_encode($perms), $k]);
    roles(true);
}

/* ---------- which permission a request needs ---------- */

/**
 * The permission a request needs: one of PERMISSIONS, 'self' (any signed-in account: /account, sign out) or
 * 'public' (no check: sign-in, registration). Handlers check finer cases (moving an item while editing it).
 */
function route_perm(string $method, string $path, array $post = []): string
{
    $get = $method !== 'POST';
    if (in_array($path, ['/login', '/register'], true)) return 'public';
    if ($path === '/logout' || str_starts_with($path, '/account')) return 'self';
    if (preg_match('#^/settings/(security|users|roles|password)(/|$)#', $path)) return 'users';
    if (preg_match('#^/settings(/|\.|$)#', $path)) return 'settings';
    if (preg_match('#^/backup(/|\.zip$|$)#', $path)) return 'backup';
    if (str_starts_with($path, '/import')) return 'import';
    if ($path === '/export.csv') return 'export';
    if (str_starts_with($path, '/stocktake')) return $get ? 'view' : 'stocktake';   // reports are for everyone who may look
    if ($path === '/labels' || preg_match('#^/item/\d+/label$#D', $path)) return 'labels';
    if ($path === '/new' || $path === '/autofill/suggest') return 'create';
    if (preg_match('#^/item/\d+/duplicate$#D', $path)) return 'create';
    if (preg_match('#^/item/\d+/(lend|return)$#D', $path) || $path === '/lent' && !$get) return 'lend';
    if ($path === '/bulk') return 'bulk';
    if (preg_match('#^/item/\d+/delete$#D', $path)) return 'delete';
    if ($path === '/save' && !$get) return (int)($post['id'] ?? 0) > 0 ? 'edit' : 'create';
    if (preg_match('#^/item/\d+/merge$#D', $path)) return 'delete';
    if (preg_match('#^/item/\d+/(edit|photos/\d+/edit)$#D', $path)) return 'edit';
    if ($get) return 'view';
    // per-device preferences
    if (in_array($path, ['/theme', '/recent/clear'], true)) return 'view';
    return 'edit';
}

/** Inside a handler: refuse with 403 unless the current user has $perm. */
function require_perm(string $perm): void
{
    if (!user_can($perm)) throw new UserError('Your role (' . role_name(current_user()['role'] ?? 'guest') . ') does not allow this – it needs the “' . (PERMISSIONS[$perm][0] ?? $perm) . '” permission. Ask an admin.', 403);
}

/** Extra permission a bulk action needs on top of "bulk". */
function bulk_perm(string $action): string
{
    return match ($action) { 'labels' => 'labels', 'delete' => 'delete', 'move' => 'move', default => 'edit' };
}
