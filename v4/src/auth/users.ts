// `UserAdmin` and the `Accounts` checks it uses (plan §5 S1.3): accounts on Users & sign-in.
//
// Ported from `modules/Auth/UserAdmin.php` (itself legacy `user_create()`, `user_approve()`,
// `user_set_role()`, `user_set_password()`, `user_rename()`, `user_delete()`), with v3's messages and
// statuses — a request that is refused here gets the same line on both runtimes.
//
// The part that is *not* a transcription is the last-admin guard. v3 asserts inside a transaction that
// rolls back; a v4 write unit is one batch with no rollback (§10.2), so the guard travels **in the
// statement** — exactly v3's own conditional SQL, which it already had for the parallel case
// (M-1764). The decision is therefore taken by the statement against the state it sees at that
// moment, not by a count this file read a round trip earlier. After the batch, the row is read back
// and *that* decides whether the write happened (D26: a statement result is not an answer).

import { type Sql, statement, type Statement, utcNow } from "../db/sql";
import { hashPassword } from "../platform/passwords";
import { clean, ROLE_DEFAULTS } from "./permissions";

/** `Accounts::USERNAME_MAX` / `Accounts::PASSWORD_MIN`. */
export const USERNAME_MAX = 40;
export const PASSWORD_MIN = 8;

/** A refusal with v3's `UserError` message and status. */
export class UserError extends Error {
	constructor(
		message: string,
		readonly status: number,
	) {
		super(message);
		this.name = "UserError";
	}
}

/** `Accounts::nameValid()`'s one message for a bad shape. */
export const SHAPE = `User names use letters, digits and . _ @ - (at most ${USERNAME_MAX} characters, no spaces).`;

/** `UserRepository::key()`: `mb_strtolower(trim($name))`. */
export const accountKey = (raw: string): string => raw.trim().toLowerCase();

/** `Text::cleanLine()`: control characters out, trimmed, at most `max` characters. */
export const cleanLine = (raw: string, max: number): string => {
	let out = "";
	for (const ch of raw) {
		const code = ch.codePointAt(0) ?? 0;
		if (code < 0x20 || code === 0x7f) {
			continue;
		}
		out += ch;
	}
	return [...out].slice(0, max).join("");
};

/** `Accounts::nameValid()`: NUL refused before trimming, then the shape, then the name being free. */
export const validName = async (sql: Sql, raw: string, excludeId?: number): Promise<string> => {
	if (raw.includes("\0")) {
		throw new UserError(SHAPE, 422);
	}
	const name = raw.trim();
	if (name === "") {
		throw new UserError("Enter a user name.", 422);
	}
	if ([...name].length > USERNAME_MAX || !/^[\p{L}\p{N}][\p{L}\p{N}._@-]*$/u.test(name)) {
		throw new UserError(SHAPE, 422);
	}
	const rows = await sql.all(statement("SELECT id FROM users WHERE username_key = ?", [accountKey(name)]));
	const other = rows[0];
	if (other !== undefined && Number(other.id) !== (excludeId ?? -1)) {
		throw new UserError(`The user name \u201c${name}\u201d is taken.`, 422);
	}
	return name;
};

/** `Accounts::passwordValid()`: characters for the minimum, bytes for the maximum, as PHP counts them. */
export const validPassword = (next: string, confirm: string): string => {
	if ([...next].length < PASSWORD_MIN) {
		throw new UserError(`Use at least ${PASSWORD_MIN} characters for the password.`, 422);
	}
	if (new TextEncoder().encode(next).length > 200) {
		throw new UserError("That password is too long.", 422);
	}
	if (next.includes("\0")) {
		throw new UserError("Passwords cannot contain a null character.", 422);
	}
	if (next !== confirm) {
		throw new UserError("The two passwords do not match.", 422);
	}
	return next;
};

/** `SessionEpoch::fresh()`: `bin2hex(random_bytes(8))`, sixteen hex characters. */
export const freshEpoch = (): string => {
	const bytes = new Uint8Array(8);
	crypto.getRandomValues(bytes);
	let out = "";
	for (const byte of bytes) {
		out += byte.toString(16).padStart(2, "0");
	}
	return out;
};

/** The account row the admin surface reads. */
export interface UserRow {
	readonly id: number;
	readonly username: string;
	readonly role: string;
	readonly status: string;
	readonly passwordHash: string;
	readonly sessionEpoch: string;
}

/** The account a change is made *by*: `AdminGuard`'s `ActorGate`, as the permission check resolved it. */
export interface Actor {
	readonly role: string;
	readonly anon: boolean;
}

const ROW = "SELECT id, username, role, status, password_hash, session_epoch FROM users WHERE id = ?";

const rowOf = (row: Record<string, unknown> | undefined): UserRow | null =>
	row === undefined
		? null
		: {
				id: Number(row.id ?? 0),
				username: String(row.username ?? ""),
				role: String(row.role ?? ""),
				status: String(row.status ?? "active"),
				passwordHash: String(row.password_hash ?? ""),
				sessionEpoch: String(row.session_epoch ?? ""),
			};

export const find = async (sql: Sql, id: number): Promise<UserRow | null> =>
	rowOf((await sql.all(statement(ROW, [id])))[0]);

export const findByName = async (sql: Sql, name: string): Promise<UserRow | null> =>
	rowOf(
		(await sql.all(statement("SELECT id, username, role, status, password_hash, session_epoch FROM users WHERE username_key = ?", [accountKey(name)])))[0],
	);

const get = async (sql: Sql, id: number): Promise<UserRow> => {
	const user = await find(sql, id);
	if (user === null) {
		throw new UserError("User not found.", 404);
	}
	return user;
};

/** `UserAdmin::all()`, in the repository's order (`username_key`, then id). */
export const all = async (
	sql: Sql,
): Promise<Array<{ id: number; username: string; role: string; status: string; createdAt: string; lastLoginAt: string | null }>> => {
	const rows = await sql.all(
		statement("SELECT id, username, role, status, created_at, last_login_at FROM users ORDER BY username_key, id"),
	);
	return rows.map((row) => ({
		id: Number(row.id ?? 0),
		username: String(row.username ?? ""),
		role: String(row.role ?? ""),
		status: String(row.status ?? "active"),
		createdAt: String(row.created_at ?? ""),
		lastLoginAt: row.last_login_at === null || row.last_login_at === undefined ? null : String(row.last_login_at),
	}));
};

/** `UserAdmin::activeAdmins()`: accounts that are admin *and* approved. */
export const activeAdmins = async (sql: Sql): Promise<number> => {
	const rows = await sql.all(statement("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND status = 'active'"));
	return Number(rows[0]?.n ?? 0);
};

/** The roles an account may be given: every role but guest, in the roles table's order. */
export interface RoleList {
	readonly order: readonly string[];
	readonly names: ReadonlyMap<string, string>;
}

/** `UserAdmin::accountRoles()`: every role but guest. `roles.listed()` is the caller's read. */
export const accountRoles = (listed: ReadonlyArray<{ key: string; name: string }>): RoleList => {
	const account = listed.filter((role) => role.key !== "guest");
	return { order: account.map((role) => role.key), names: new Map(account.map((role) => [role.key, role.name])) };
};

/** `UserAdmin::roleValid()`: any role but guest, or v3's 422 naming the roles it would accept. */
export const roleValid = (role: string, list: RoleList): string => {
	if (list.order.includes(role)) {
		return role;
	}
	const names = list.order.map((key) => (list.names.get(key) ?? key).toLowerCase());
	throw new UserError(`Choose a role: ${names.join(", ")}.`, 422);
};

/** The built-in order, for a caller whose roles read failed the way v3's falls back. */
export const defaultRoles = (): RoleList => accountRoles(ROLE_DEFAULTS.map(([key, value]) => ({ key, name: value })));

/** `AdminGuard::target()`: only an admin changes an admin account. */
const guardTarget = (actor: Actor, target: UserRow): void => {
	if (target.role === "admin" && actor.role !== "admin" && !actor.anon) {
		throw new UserError("Only an admin can change an admin account.", 403);
	}
};

/** `AdminGuard::grant()`: only an admin grants the admin role. */
const guardGrant = (actor: Actor, role: string): void => {
	if (role === "admin" && actor.role !== "admin" && !actor.anon) {
		throw new UserError("Only an admin can grant the admin role.", 403);
	}
};

/**
 * `UserAdmin::create()` — legacy `user_create()`, status active. Returns the new id; the row is read
 * back rather than trusting `lastRowId`, which the single-process backend does not report (D26).
 */
export const create = async (
	sql: Sql,
	actor: Actor,
	input: { username: string; password: string; confirm: string; role: string; roles: RoleList },
	now: number,
): Promise<number> => {
	const username = await validName(sql, input.username);
	const role = roleValid(input.role, input.roles);
	guardGrant(actor, role);
	const password = validPassword(input.password, input.confirm);
	const hash = await hashPassword(password);
	const stamp = utcNow(new Date(now * 1000));
	await sql.batch([
		statement(
			"INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?,?,?,?,'active',?)",
			[username, accountKey(username), hash, role, stamp],
		),
	]);
	const created = await findByName(sql, username);
	if (created === null || created.passwordHash !== hash) {
		throw new UserError("The account was not saved.", 503);
	}
	return created.id;
};

/** `UserAdmin::approve()` — legacy `user_approve()`. */
export const approve = async (sql: Sql, actor: Actor, id: number): Promise<void> => {
	const user = await get(sql, id);
	guardTarget(actor, user);
	await sql.batch([statement("UPDATE users SET status = 'active' WHERE id = ?", [id])]);
};

/**
 * `UserAdmin::setRole()`'s write, as the statement v3 already used: the row changes only when the
 * account was not an active admin, or the new role is admin, or **another active admin remains**.
 * The `EXISTS` is evaluated by the statement inside the batch — that is the whole point of it.
 */
export const setRoleStatements = (id: number, role: string): readonly Statement[] => [
	statement(
		"UPDATE users SET role = ? WHERE id = ? AND ("
			+ " ? = 'admin' OR role <> 'admin' OR status <> 'active'"
			+ " OR EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?))",
		[role, id, role, id],
	),
];

/** `UserAdmin::setRole()` — legacy `user_set_role()`, refusing to leave the install without an admin. */
export const setRole = async (sql: Sql, actor: Actor, id: number, role: string, roles: RoleList): Promise<void> => {
	const user = await get(sql, id);
	const next = roleValid(role, roles);
	guardTarget(actor, user);
	guardGrant(actor, next);
	await sql.batch(setRoleStatements(id, next));
	const after = await find(sql, id);
	if (after === null || after.role !== next) {
		throw new UserError(`\u201c${user.username}\u201d is the only admin \u2013 make someone else an admin first.`, 409);
	}
};

/** `UserAdmin::setPassword()`: every session of that account is signed out (a new epoch). */
export const setPassword = async (sql: Sql, actor: Actor, id: number, password: string, confirm: string): Promise<string> => {
	const user = await get(sql, id);
	guardTarget(actor, user);
	const epoch = freshEpoch();
	const hash = await hashPassword(validPassword(password, confirm));
	await sql.batch([statement("UPDATE users SET password_hash = ?, session_epoch = ? WHERE id = ?", [hash, epoch, id])]);
	const after = await find(sql, id);
	if (after === null || after.passwordHash !== hash || after.sessionEpoch !== epoch) {
		throw new UserError("The password was not saved.", 503);
	}
	return epoch;
};

/** `UserAdmin::rename()` — legacy `user_rename()`. */
export const rename = async (sql: Sql, actor: Actor, id: number, raw: string): Promise<void> => {
	const user = await get(sql, id);
	guardTarget(actor, user);
	const username = await validName(sql, raw, id);
	await sql.batch([statement("UPDATE users SET username = ?, username_key = ? WHERE id = ?", [username, accountKey(username), id])]);
};

/** `UserAdmin::delete()`'s write: the last active admin stays, decided by the statement. */
export const deleteStatements = (id: number): readonly Statement[] => [
	statement(
		"DELETE FROM users WHERE id = ? AND ("
			+ " role <> 'admin' OR status <> 'active'"
			+ " OR EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?))",
		[id, id],
	),
];

/** `UserAdmin::delete()` — legacy `user_delete()`. */
export const remove = async (sql: Sql, actor: Actor, id: number): Promise<void> => {
	const user = await get(sql, id);
	guardTarget(actor, user);
	await sql.batch(deleteStatements(id));
	const after = await find(sql, id);
	if (after !== null) {
		throw new UserError(`\u201c${user.username}\u201d is the only admin and cannot be deleted.`, 409);
	}
};

/** `UserAdmin::roleValid()`'s sibling for the page: the roles a new account may take, names included. */
export const roleChoices = (list: RoleList): Array<{ key: string; name: string }> =>
	list.order.map((key) => ({ key, name: list.names.get(key) ?? key }));

/** `Permissions::clean()` for a role a caller is about to store (re-exported for the routes). */
export { clean as cleanPermissions };
