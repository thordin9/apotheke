<?php
declare(strict_types=1);

/*
 * User accounts and roles. With sign-in turned on (Control Panel → Users & sign-in, setting auth_enabled) everybody
 * signs in with a user name and password; the role decides what they may do:
 *   viewer  – look at everything, export CSV, print labels
 *   editor  – also add, change, move and delete items, locations, categories, photos, stocktakes, imports
 *   admin   – also the Control Panel, users, backup & restore
 * With sign-in off (the "no login" mode for a trusted home network) every visitor is an admin.
 */

const USER_ROLES = ['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer'];
const USER_ROLE_HINTS = ['admin' => 'everything, including settings, users and backups', 'editor' => 'add, change and delete items', 'viewer' => 'look only'];
const ROLE_RANK = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
const USERNAME_MAX = 40;
const PASSWORD_MIN = 8;

function user_row(array $r): array
{
    return ['id' => (int)$r['id'], 'username' => (string)$r['username'], 'role' => (string)$r['role'], 'password_hash' => (string)$r['password_hash'],
        'created_at' => (string)$r['created_at'], 'last_login_at' => $r['last_login_at'] === null ? null : (string)$r['last_login_at']];
}

/** @return array<int,array> sorted by name */
function users_list(): array
{
    return array_map('user_row', db()->query('SELECT * FROM users ORDER BY username_key, id')->fetchAll());
}

function users_count(?string $role = null): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM users' . ($role !== null ? ' WHERE role = ?' : ''));
    $st->execute($role !== null ? [$role] : []);
    return (int)$st->fetchColumn();
}

function user_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? user_row($r) : null;
}

function user_by_name(string $name): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE username_key = ?');
    $st->execute([user_key($name)]);
    $r = $st->fetch();
    return $r ? user_row($r) : null;
}

function user_key(string $name): string
{
    return mb_strtolower(trim($name));
}

function user_name_valid(string $name, ?int $except = null): string
{
    $name = trim($name);
    if ($name === '') throw new UserError('Enter a user name.', 422);
    if (mb_strlen($name) > USERNAME_MAX || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._@-]*$/uD', $name)) {
        throw new UserError('User names use letters, digits and . _ @ - (at most ' . USERNAME_MAX . ' characters, no spaces).', 422);
    }
    $other = user_by_name($name);
    if ($other && $other['id'] !== $except) throw new UserError('The user name “' . $name . '” is taken.', 422);
    return $name;
}

function user_password_valid(string $new, ?string $confirm = null): string
{
    if (mb_strlen($new) < PASSWORD_MIN) throw new UserError('Use at least ' . PASSWORD_MIN . ' characters for the password.', 422);
    if (strlen($new) > 200) throw new UserError('That password is too long.', 422);
    if ($confirm !== null && !hash_equals($new, $confirm)) throw new UserError('The two passwords do not match.', 422);
    return $new;
}

function user_role_valid(string $role): string
{
    if (!isset(USER_ROLES[$role])) throw new UserError('Choose a role: admin, editor or viewer.', 422);
    return $role;
}

function user_create(string $name, string $password, string $role, ?string $confirm = null): int
{
    $name = user_name_valid($name);
    $role = user_role_valid($role);
    $password = user_password_valid($password, $confirm);
    return db_insert(db(), 'INSERT INTO users (username, username_key, password_hash, role, created_at) VALUES (?,?,?,?,?)',
        [$name, user_key($name), password_hash($password, PASSWORD_DEFAULT), $role, now_utc()]);
}

/** Change the role; refuses to leave the install without an admin. */
function user_set_role(int $id, string $role): void
{
    $u = user_get($id) ?? throw new UserError('User not found.', 404);
    $role = user_role_valid($role);
    if ($u['role'] === 'admin' && $role !== 'admin' && users_count('admin') <= 1) throw new UserError('“' . $u['username'] . '” is the only admin – make someone else an admin first.', 409);
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
}

/** New password; every session of that user has to sign in again. */
function user_set_password(int $id, string $password, ?string $confirm = null): void
{
    user_get($id) ?? throw new UserError('User not found.', 404);
    $password = user_password_valid($password, $confirm);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
}

function user_rename(int $id, string $name): void
{
    user_get($id) ?? throw new UserError('User not found.', 404);
    $name = user_name_valid($name, $id);
    db()->prepare('UPDATE users SET username = ?, username_key = ? WHERE id = ?')->execute([$name, user_key($name), $id]);
}

function user_delete(int $id): void
{
    $u = user_get($id) ?? throw new UserError('User not found.', 404);
    if ($u['role'] === 'admin' && users_count('admin') <= 1) throw new UserError('“' . $u['username'] . '” is the only admin and cannot be deleted.', 409);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}

/** Check a user name + password; returns the user or null (always spends the same hashing time). */
function user_verify(string $name, string $password): ?array
{
    $u = user_by_name($name);
    if (!$u) { password_verify($password, '$2y$10$usesomesillystringforsaltqqqqqqqqqqqqqqqqqqqqqqqqqqqqq'); return null; }
    if (!password_verify($password, $u['password_hash'])) return null;
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    return $u;
}

/** Sessions remember this fingerprint; a new password for the user invalidates them. */
function user_epoch(array $u): string
{
    return substr(hash('sha256', $u['password_hash'] . '|' . $u['id']), 0, 16);
}

/* ---------- sign-in mode ---------- */

function auth_enabled(): bool
{
    if (!setting('auth_enabled')) return false;
    if (users_count() > 0) return true;
    // APP_PASSWORD (env/config) on an install without accounts: it becomes the password of the account "admin"
    $pw = (string)cfg()['app_password'];
    if ($pw === '' || mb_strlen($pw) < 1) return false;
    db_insert(db(), 'INSERT INTO users (username, username_key, password_hash, role, created_at) VALUES (?,?,?,?,?)',
        ['admin', 'admin', password_hash($pw, PASSWORD_DEFAULT), 'admin', now_utc()]);
    return true;
}

/** Turn sign-in on. Without any account yet, $name/$password create the first admin. */
function auth_turn_on(): void
{
    if (users_count('admin') === 0) throw new UserError('Create an admin account first.', 422);
    settings_store(['auth_enabled' => true]);
}

function auth_turn_off(): void
{
    settings_store(['auth_enabled' => false]);
}

/* ---------- the signed-in user and permissions ---------- */

/** The current user (from the session), a guest admin when sign-in is off, or null when nobody is signed in. */
function current_user(?array $set = null, bool $reset = false): ?array
{
    static $cache = false;
    if ($reset) { $cache = false; return null; }
    if ($set !== null) { $cache = $set; return $set; }
    if ($cache !== false) return $cache;
    if (!auth_enabled()) return $cache = ['id' => null, 'username' => '', 'role' => 'admin', 'guest' => true];
    $uid = (int)($_SESSION['uid'] ?? 0);
    $u = $uid > 0 ? user_get($uid) : null;
    return $cache = ($u && hash_equals(user_epoch($u), (string)($_SESSION['auth_epoch'] ?? ''))) ? $u + ['guest' => false] : null;
}

/** Name recorded in the history (null with sign-in off). */
function current_actor(): ?string
{
    if (PHP_SAPI === 'cli') return null;
    $u = current_user();
    return $u && !$u['guest'] ? $u['username'] : null;
}

function user_can(string $need, ?array $u = null): bool
{
    $u ??= current_user();
    return $u !== null && (ROLE_RANK[$u['role']] ?? 0) >= (ROLE_RANK[$need] ?? 99);
}

/**
 * The role a request needs. GET pages are for viewers, except the forms that only make sense for editors;
 * every POST changes something and needs an editor, apart from per-device preferences and signing out.
 * The Control Panel, users and backups are for admins; /account (your own password) is for everybody.
 */
function route_role(string $method, string $path): string
{
    if (preg_match('#^/(settings|backup)(/|\.zip$|$)#', $path)) return 'admin';
    if (str_starts_with($path, '/account')) return 'viewer';
    if ($method === 'POST') return in_array($path, ['/theme', '/logout', '/recent/clear'], true) ? 'viewer' : 'editor';
    if ($path === '/new' || str_starts_with($path, '/import') || preg_match('#^/item/\d+/(edit|merge)$#', $path)) return 'editor';
    return 'viewer';
}
