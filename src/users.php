<?php
declare(strict_types=1);

/*
 * User accounts and roles. With sign-in turned on (Control Panel → Users & sign-in, setting auth_enabled) everybody
 * signs in with a user name and password; the role decides what they may do:
 * the role's permissions (src/roles.php) decide what they may do. Visitors who are not signed in are guests (role
 * "guest") when guest browsing is allowed, otherwise they are sent to the sign-in page. Accounts can register
 * themselves at /register when registrations are open (optionally waiting for an admin's approval).
 * With sign-in off (the "no login" mode for a trusted home network) every visitor is an anonymous admin.
 */

const USERNAME_MAX = 40;
const PASSWORD_MIN = 8;

function user_row(array $r): array
{
    return ['id' => (int)$r['id'], 'username' => (string)$r['username'], 'role' => (string)$r['role'], 'status' => (string)($r['status'] ?? 'active'), 'password_hash' => (string)$r['password_hash'],
        'created_at' => (string)$r['created_at'], 'last_login_at' => $r['last_login_at'] === null ? null : (string)$r['last_login_at']];
}

/** @return array<int,array> sorted by name */
function users_list(): array
{
    return array_map('user_row', db()->query('SELECT * FROM users ORDER BY username_key, id')->fetchAll());
}

function users_count(?string $role = null): int
{
    // an admin only counts once approved
    $st = db()->prepare('SELECT COUNT(*) FROM users' . ($role !== null ? " WHERE role = ? AND status = 'active'" : ''));
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
    if (!isset(account_roles()[$role])) throw new UserError('Choose a role: ' . implode(', ', array_map(fn($r) => mb_strtolower($r['name']), account_roles())) . '.', 422);
    return $role;
}

function user_create(string $name, string $password, string $role, ?string $confirm = null, string $status = 'active'): int
{
    $name = user_name_valid($name);
    $role = user_role_valid($role);
    if ($role === 'admin' && !actor_is_admin()) throw new UserError('Only an admin can grant the admin role.', 403);
    $password = user_password_valid($password, $confirm);
    $id = db_insert(db(), 'INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?,?,?,?,?,?)',
        [$name, user_key($name), password_hash($password, PASSWORD_DEFAULT), $role, $status === 'pending' ? 'pending' : 'active', now_utc()]);
    roles(true);
    return $id;
}

/** Approve a self-registered account waiting for an admin. */
function user_approve(int $id): void
{
    $u = user_get($id) ?? throw new UserError('User not found.', 404);
    guard_admin_target($u);
    db()->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
}

/** Lockout guard: after changing user $id to $role (null = deleted), an active admin must remain. */
function user_guard_admin(array $u, ?string $role): void
{
    if ($u['role'] === 'admin' && $u['status'] === 'active' && $role !== 'admin' && users_count('admin') <= 1) {
        throw new UserError('“' . $u['username'] . '” is the only admin – make someone else an admin first.', 409);
    }
}

/** Change the role; refuses to leave the install without an admin. */
function user_set_role(int $id, string $role): void
{
    $u = user_get($id) ?? throw new UserError('User not found.', 404);
    $role = user_role_valid($role);
    guard_admin_target($u);
    if ($role === 'admin' && !actor_is_admin()) throw new UserError('Only an admin can grant the admin role.', 403);
    user_guard_admin($u, $role);
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    roles(true);
}

/** New password; every session of that user has to sign in again. */
function user_set_password(int $id, string $password, ?string $confirm = null): void
{
    $u = user_get($id) ?? throw new UserError('User not found.', 404);
    guard_admin_target($u);
    $password = user_password_valid($password, $confirm);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
}

function user_rename(int $id, string $name): void
{
    $u = user_get($id) ?? throw new UserError('User not found.', 404);
    guard_admin_target($u);
    $name = user_name_valid($name, $id);
    db()->prepare('UPDATE users SET username = ?, username_key = ? WHERE id = ?')->execute([$name, user_key($name), $id]);
}

function user_delete(int $id): void
{
    $u = user_get($id) ?? throw new UserError('User not found.', 404);
    guard_admin_target($u);
    if ($u['role'] === 'admin' && $u['status'] === 'active' && users_count('admin') <= 1) throw new UserError('“' . $u['username'] . '” is the only admin and cannot be deleted.', 409);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    roles(true);
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
    if (!actor_is_admin()) throw new UserError('Only an admin can turn sign-in off.', 403);
    settings_store(['auth_enabled' => false]);
}

/* ---------- the signed-in user and permissions ---------- */

/** Visitors who are not signed in may look around (as role "guest") – only with sign-in on and guest browsing allowed. */
function guest_browsing(): bool
{
    return auth_enabled() && !setting('auth_guest_disabled');
}

function registration_open(): bool
{
    return auth_enabled() && !setting('auth_register_disabled');
}

/**
 * The current user (from the session); an anonymous admin when sign-in is off; the guest (role "guest", 'anon')
 * while guest browsing is allowed; null when nobody is signed in and guests must sign in.
 */
function current_user(?array $set = null, bool $reset = false): ?array
{
    static $cache = false;
    if ($reset) { $cache = false; return null; }
    if ($set !== null) { $cache = $set; return $set; }
    if ($cache !== false) return $cache;
    if (!auth_enabled()) return $cache = ['id' => null, 'username' => '', 'role' => 'admin', 'status' => 'active', 'guest' => true, 'anon' => false];
    $uid = (int)($_SESSION['uid'] ?? 0);
    $u = $uid > 0 ? user_get($uid) : null;
    if ($u && $u['status'] === 'active' && hash_equals(user_epoch($u), (string)($_SESSION['auth_epoch'] ?? ''))) return $cache = $u + ['guest' => false, 'anon' => false];
    return $cache = guest_browsing() ? ['id' => null, 'username' => '', 'role' => 'guest', 'status' => 'active', 'guest' => true, 'anon' => true] : null;
}

/** Signed in with an account (not the anonymous admin or a guest)? */
function signed_in(?array $u = null): bool
{
    $u ??= current_user();
    return $u !== null && !$u['guest'];
}

/** Name recorded in the history (null with sign-in off). */
function current_actor(): ?string
{
    if (PHP_SAPI === 'cli') return null;
    $u = current_user();
    return $u && !$u['guest'] ? $u['username'] : null;
}

/** May the current user (or $u) do $perm (one of PERMISSIONS, or 'self' = signed in with an account, 'public')? */
function user_can(string $perm, ?array $u = null): bool
{
    if ($perm === 'public') return true;
    $u ??= current_user();
    if ($u === null) return false;
    if ($perm === 'self') return !$u['guest'];
    return role_has($u['role'], $perm);
}

/** An admin account, or the command line. Granting admin, turning sign-in off, and changing an admin account need this. */
function actor_is_admin(): bool
{
    $u = current_user();
    if ($u === null) return PHP_SAPI === 'cli';
    return ($u['role'] ?? '') === 'admin';
}

/** A non-admin cannot change an admin account (role, name, password, approval, or deletion). The command line can. */
function guard_admin_target(array $u): void
{
    if (($u['role'] ?? '') === 'admin' && !actor_is_admin()) {
        throw new UserError('Only an admin can change an admin account.', 403);
    }
}

/* ---------- rate limiting (sign-in, registration) ---------- */

const RATE_LIMITS = ['login' => [10, 900], 'register' => [5, 3600]];

/**
 * Address of the browser. X-Forwarded-For counts only when “Trust the reverse proxy” is on and the
 * connection came from a configured proxy (trusted_proxies). The client is then the rightmost hop
 * that is not itself a configured proxy. Otherwise this is REMOTE_ADDR.
 * @param list<string> $proxies
 */
function client_address_from(string $remote, string $forwarded, bool $trustProxy, array $proxies): string
{
    $remote = trim($remote);
    if ($remote === '') $remote = 'cli';
    $pick = static function (string $ip): string {
        $norm = config_ip_normalize($ip);
        return $norm ?? $ip;
    };
    if (!$trustProxy || $proxies === [] || !config_proxy_allows($remote, $proxies)) return $pick($remote);
    $hops = [];
    foreach (explode(',', $forwarded) as $part) {
        $part = trim($part);
        if ($part !== '') $hops[] = $part;
    }
    $hops[] = $remote;
    $untrusted = [];
    foreach ($hops as $hop) if (!config_proxy_allows($hop, $proxies)) $untrusted[] = $hop;
    if ($untrusted === []) return $pick($remote);
    return $pick($untrusted[array_key_last($untrusted)]);
}

function client_address(): string
{
    try { $trust = (bool)setting('trust_proxy'); } catch (Throwable) { $trust = false; }
    $proxies = cfg()['trusted_proxies'] ?? [];
    return client_address_from(
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        $trust,
        is_array($proxies) ? $proxies : []
    );
}

/** Sign-in includes the account, so one client cannot use up the limit for every account. */
function client_key(?string $account = null): string
{
    $material = $account === null ? client_address() : client_address() . "\0" . $account;
    return substr(hash('sha256', $material), 0, 32);
}

function rate_bucket(string $kind, ?string $account): string
{
    return client_key($kind === 'login' ? user_key((string)$account) : null);
}

/** Throws 429 when $kind was tried too often for this client (and, for sign-in, this account); otherwise records the attempt. */
function rate_limit(string $kind, ?string $account = null): void
{
    [$max, $window] = RATE_LIMITS[$kind];
    $now = app_time();
    $key = rate_bucket($kind, $account);
    $pdo = db();
    $pdo->prepare('DELETE FROM auth_attempts WHERE at < ?')->execute([$now - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM auth_attempts WHERE kind = ? AND client = ? AND at >= ?');
    $st->execute([$kind, $key, $now - $window]);
    if ((int)$st->fetchColumn() >= $max) {
        $scope = $kind === 'login' ? 'for this account from this address' : 'from this address';
        throw new UserError('Too many attempts ' . $scope . ' – please wait ' . (int)ceil($window / 60) . ' minutes and try again.', 429);
    }
    $pdo->prepare('INSERT INTO auth_attempts (kind, client, at) VALUES (?,?,?)')->execute([$kind, $key, $now]);
}

function rate_limit_clear(string $kind, ?string $account = null): void
{
    db()->prepare('DELETE FROM auth_attempts WHERE kind = ? AND client = ?')->execute([$kind, rate_bucket($kind, $account)]);
}

/** Self-registration: creates an account with the configured role (pending when approval is required). */
function user_register(string $name, string $password, string $confirm): array
{
    if (!registration_open()) throw new UserError('Registration is closed.', 403);
    $role = (string)setting('auth_register_role');
    if (!isset(account_roles()[$role]) || $role === 'admin') $role = 'viewer';
    $pending = (bool)setting('auth_register_approval');
    $id = user_create($name, $password, $role, $confirm, $pending ? 'pending' : 'active');
    return user_get($id);
}
