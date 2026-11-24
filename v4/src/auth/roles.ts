// `RoleAdmin` and `RoleRepository` (plan §5 S1.3): the roles editor and its last-manager check.
//
// Ported from `modules/Auth/RoleAdmin.php` and `RoleRepository.php`. The messages and statuses are
// v3's; the part that had to change shape is the guard. v3 computes `user_managers_count()` inside a
// transaction that rolls back when it throws (L-110, a unit lock and no row lock). A v4 write unit is
// one batch with no rollback (§10.2), so the same decision travels **in the statement**: the predicate
// is part of the `UPDATE`/`DELETE` and is evaluated by the database against the state it can see, not
// by a count this file read a round trip earlier. Two concurrent edits therefore cannot both remove
// the last manager — the second one's statement finds no manager left and changes no row.
//
// That is why the manager test is SQL over `roles.perms` and not a parsed list: `instr(perms, '"users"')`
// is exact because `permsJson()` is the only writer of that column, so the canonical element is either
// present or it is not. The refusal is decided afterwards by reading the row back (D26).

import { type Sql, type Statement, statement, utcNow } from "../db/sql";
import { ALL_KEYS, clean, fromStored, NOT_FOR_GUESTS, permsJson, ROLE_DEFAULTS, USERS_PERM } from "./permissions";
import { cleanLine, UserError } from "./users";

/** `RoleAdmin::KEY_MAX` / `NAME_MAX`. */
export const KEY_MAX = 30;
export const NAME_MAX = 60;

export interface RoleRow {
	readonly key: string;
	readonly name: string;
	readonly builtin: boolean;
	readonly users: number;
	readonly perms: readonly string[];
}

/** `Permissions::fromStored()` then `clean()`: what a stored `perms` column means for a role. */
const permsOf = (key: string, raw: unknown): string[] => {
	let parsed: unknown = [];
	if (typeof raw === "string") {
		try {
			parsed = JSON.parse(raw);
		} catch {
			parsed = [];
		}
	}
	return clean(key, fromStored(Array.isArray(parsed) ? parsed : []).map(String));
};

/** `RoleRepository::listed()`: every role, built-in first, with how many accounts have it. */
export const listed = async (sql: Sql): Promise<RoleRow[]> => {
	const counts = new Map<string, number>();
	for (const row of await sql.all(statement("SELECT role, COUNT(*) AS n FROM users GROUP BY role"))) {
		counts.set(String(row.role ?? ""), Number(row.n ?? 0));
	}
	const out: RoleRow[] = [];
	const rows = await sql.all(statement("SELECT role_key, name, perms, builtin FROM roles ORDER BY builtin DESC, id"));
	for (const row of rows) {
		const key = String(row.role_key ?? "");
		out.push({
			key,
			name: String(row.name ?? ""),
			builtin: Number(row.builtin ?? 0) === 1,
			users: counts.get(key) ?? 0,
			perms: permsOf(key, row.perms),
		});
	}
	if (out.length === 0) {
		// Before migration 0022 the table did not exist and v3 fell back to the defaults; a read that
		// returns nothing here means the same thing, and the editor must still be able to answer.
		return ROLE_DEFAULTS.map(([key, name, perms]) => ({
			key,
			name,
			builtin: true,
			users: counts.get(key) ?? 0,
			perms: clean(key, perms),
		}));
	}
	return out;
};

/** `RoleRepository::has()`: admin has everything; an unknown role has nothing. */
export const has = (role: RoleRow | undefined, perm: string): boolean => {
	if (role === undefined) {
		return false;
	}
	return role.key === "admin" || role.perms.includes(perm);
};

/** `RoleRepository::name()`. */
export const roleName = (roles: readonly RoleRow[], role: string): string =>
	roles.find((row) => row.key === role)?.name ?? role;

/** `RoleAdmin::keyFrom()`: `strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'))`, cut at 30. */
export const keyFrom = (name: string): string =>
	name
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, "-")
		.replace(/^-+|-+$/g, "")
		.slice(0, KEY_MAX);

/** `RoleAdmin::validPerms()`: only known keys, in `ALL` order, or v3's 422. */
export const validPerms = (perms: readonly string[]): string[] => {
	const known = new Set(ALL_KEYS);
	for (const perm of perms) {
		if (!known.has(perm)) {
			throw new UserError(`Unknown permission \u201c${perm === "" ? "?" : perm}\u201d.`, 422);
		}
	}
	return clean("", perms);
};

/** `EXISTS (active account other than $key that is an admin or a role holding users)`. */
const OTHER_MANAGER = (keyParam: string): string =>
	`EXISTS (SELECT 1 FROM users u WHERE u.status = 'active' AND u.role <> ${keyParam}`
	+ ` AND (u.role = 'admin' OR EXISTS (SELECT 1 FROM roles r2 WHERE r2.role_key = u.role AND instr(r2.perms, ${USERS_PERM}) > 0)))`;

/** `NOT EXISTS (any active account that is an admin or a role holding users)`: v3's `managers() > 0`. */
const NO_MANAGER = "NOT EXISTS (SELECT 1 FROM users u WHERE u.status = 'active'"
	+ ` AND (u.role = 'admin' OR EXISTS (SELECT 1 FROM roles r3 WHERE r3.role_key = u.role AND instr(r3.perms, ${USERS_PERM}) > 0)))`;

/** The predicate `updateLocked()` uses: this change may not leave the install without a manager. */
const UPDATE_SAFE = `(? = 1 OR instr(perms, ${USERS_PERM}) = 0 OR ${OTHER_MANAGER("?")} OR ${NO_MANAGER})`;

/**
 * The predicate `deleteLocked()` uses: its accounts move to `?`, which must be able to manage users.
 * The deleted role's own permissions are read through a subselect and not as `perms`, because this
 * predicate is also carried by the `UPDATE users` that moves them — and `users` has no `perms` column.
 */
const DELETE_SAFE = `(instr((SELECT r5.perms FROM roles r5 WHERE r5.role_key = ?), ${USERS_PERM}) = 0 OR ${OTHER_MANAGER("?")}`
	+ ` OR (EXISTS (SELECT 1 FROM users u2 WHERE u2.status = 'active' AND u2.role = ?)`
	+ ` AND instr((SELECT r4.perms FROM roles r4 WHERE r4.role_key = ?), ${USERS_PERM}) > 0) OR ${NO_MANAGER})`;

/** `RoleAdmin::create()`: a new custom role. Returns its key; the row is read back, not counted. */
export const create = async (sql: Sql, rawName: string, rawPerms: readonly string[], now: number): Promise<string> => {
	const name = cleanLine(rawName, NAME_MAX);
	if (name === "") {
		throw new UserError("Enter a name for the role.", 422);
	}
	const key = keyFrom(name);
	if (key === "") {
		throw new UserError("Use letters or digits in the role name.", 422);
	}
	const roles = await listed(sql);
	for (const role of roles) {
		if (role.key === key || role.name.toLowerCase() === name.toLowerCase()) {
			throw new UserError(`A role \u201c${role.name}\u201d exists already.`, 409);
		}
	}
	const perms = validPerms(rawPerms);
	await sql.batch([
		statement("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?,?,?,0,?)", [
			key,
			name,
			permsJson(perms),
			utcNow(new Date(now * 1000)),
		]),
	]);
	const after = (await listed(sql)).find((role) => role.key === key);
	if (after === undefined || after.name !== name) {
		throw new UserError("The role was not saved.", 503);
	}
	return key;
};

/** `RoleAdmin::updateLocked()`'s write: the row changes only when a manager would remain. */
export const updateStatements = (key: string, name: string, perms: readonly string[]): readonly Statement[] => [
	statement(`UPDATE roles SET name = ?, perms = ? WHERE role_key = ? AND ${UPDATE_SAFE}`, [
		name,
		permsJson(perms),
		key,
		perms.includes("users") ? 1 : 0,
		key,
	]),
];

/** `RoleAdmin::update()` — legacy `role_update()`. The admin role and a manager-less install are v3's refusals. */
export const update = async (sql: Sql, key: string, rawName: string | null, rawPerms: readonly string[]): Promise<void> => {
	const roles = await listed(sql);
	const role = roles.find((row) => row.key === key);
	if (role === undefined) {
		throw new UserError("Role not found.", 404);
	}
	if (key === "admin") {
		throw new UserError("The admin role always has every permission.", 409);
	}
	let perms = validPerms(rawPerms);
	if (key === "guest") {
		perms = perms.filter((perm) => !NOT_FOR_GUESTS.includes(perm));
	}
	const name = rawName === null || role.builtin ? role.name : cleanLine(rawName, NAME_MAX);
	if (name === "") {
		throw new UserError("Enter a name for the role.", 422);
	}
	for (const other of roles) {
		if (other.key !== key && other.name.toLowerCase() === name.toLowerCase()) {
			throw new UserError(`A role \u201c${other.name}\u201d exists already.`, 409);
		}
	}
	await sql.batch(updateStatements(key, name, perms));
	const after = (await listed(sql)).find((row) => row.key === key);
	if (after === undefined || after.name !== name || after.perms.join(",") !== perms.join(",")) {
		throw new UserError(
			"Nobody could manage users any more \u2013 keep the Users permission or make someone an admin first.",
			409,
		);
	}
};

/** `RoleAdmin::deleteLocked()`'s write: the accounts move only when the role's deletion is allowed. */
export const deleteStatements = (key: string, moveTo: string): readonly Statement[] => [
	statement(`UPDATE users SET role = ? WHERE role = ? AND ${DELETE_SAFE}`, [moveTo, key, key, key, key, key]),
	statement(`DELETE FROM roles WHERE role_key = ? AND ${DELETE_SAFE}`, [key, key, key, key, key]),
];

/** `RoleAdmin::delete()` — legacy `role_delete()`; its accounts move to `moveTo` in the same batch. */
export const remove = async (sql: Sql, key: string, moveTo: string): Promise<void> => {
	const roles = await listed(sql);
	const role = roles.find((row) => row.key === key);
	if (role === undefined) {
		throw new UserError("Role not found.", 404);
	}
	if (role.builtin) {
		throw new UserError("Built-in roles cannot be deleted.", 409);
	}
	if (moveTo === key || moveTo === "guest" || !roles.some((row) => row.key === moveTo)) {
		throw new UserError("Choose another role for its accounts.", 422);
	}
	// Read before the delete: afterwards the setting no longer validates (v3 reads its per-request copy).
	const register = await sql.all(statement("SELECT value FROM settings WHERE name = 'auth_register_role'"));
	const before = register[0]?.value;
	await sql.batch([
		...deleteStatements(key, moveTo),
		// `RoleAdmin::delete()` stores the fallback after the commit, when the register role was this one.
		statement(
			"UPDATE settings SET value = '\"viewer\"' WHERE name = 'auth_register_role' AND value = ?",
			[typeof before === "string" ? before : ""],
		),
	]);
	const after = await listed(sql);
	if (after.some((row) => row.key === key)) {
		throw new UserError("Nobody could manage users any more \u2013 make someone an admin first.", 409);
	}
};

/** The dashboard's `managers()` count, for the page and for a test that wants the number, not the rule. */
export const managers = async (sql: Sql): Promise<number> => {
	const rows = await sql.all(
		statement(
			"SELECT COUNT(*) AS n FROM users u WHERE u.status = 'active'"
				+ " AND (u.role = 'admin' OR EXISTS (SELECT 1 FROM roles r WHERE r.role_key = u.role AND instr(r.perms, "
				+ USERS_PERM
				+ ") > 0))",
		),
	);
	return Number(rows[0]?.n ?? 0);
};
