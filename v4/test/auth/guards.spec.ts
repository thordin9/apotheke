// S1.3's guards (plan §5 S1.3): the `RoleAdmin`/`UserAdmin` last-manager checks, asserted against a
// real database on the backend the unit job runs on.
//
// The DoD's sharpest item is the race, so it is tested as one: two guarded statements in a *single*
// batch, which is the case v3 answers with a transaction lock and v4 must answer with the statement
// itself. The assertion is that exactly one of the two takes effect — not that the code "looks"
// atomic. That is also why every refusal is followed by a read of the row: the answer comes from the
// state (D26), never from `changes`.
//
// The schema is the three tables these functions touch, created here the way claim.spec.ts creates
// its own: 0022's roles, 0018/0022/0028's users, 0013's settings.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { permsJson } from "../../src/auth/permissions";
import {
	create as createRole,
	keyFrom,
	listed,
	managers,
	remove as removeRole,
	update as updateRole,
	updateStatements,
	validPerms,
} from "../../src/auth/roles";
import type { Actor } from "../../src/auth/users";
import { deleteStatements, remove, setRole, setRoleStatements, UserError } from "../../src/auth/users";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";

const NOW = 1_764_000_000;
const ADMIN: Actor = { role: "admin", anon: false };
const MANAGER: Actor = { role: "manager-a", anon: false };

const USERS_TABLE = `CREATE TABLE users (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	username      TEXT NOT NULL,
	username_key  TEXT NOT NULL UNIQUE,
	password_hash TEXT NOT NULL,
	role          TEXT NOT NULL,
	created_at    TEXT NOT NULL,
	last_login_at TEXT,
	status        TEXT NOT NULL DEFAULT 'active',
	session_epoch TEXT NOT NULL DEFAULT ''
)`;
const ROLES_TABLE = `CREATE TABLE roles (
	id         INTEGER PRIMARY KEY AUTOINCREMENT,
	role_key   TEXT NOT NULL UNIQUE,
	name       TEXT NOT NULL,
	perms      TEXT NOT NULL,
	builtin    INTEGER NOT NULL DEFAULT 0,
	created_at TEXT NOT NULL
)`;
const SETTINGS_TABLE =
	"CREATE TABLE settings (name TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)";

/** A database of its own per case, so one case's rows cannot be another's fixture. */
let n = 0;
const fresh = (): Sql => {
	n += 1;
	return durableObjectSql(
		env.INSTALLATION.get(env.INSTALLATION.idFromName(`s1.3-guards-${n}`)) as unknown as InstallationStub,
	);
};

type RoleSpec = readonly [string, string, readonly string[], number];

const MANAGERS: readonly RoleSpec[] = [
	["admin", "Admin", ["view", "users", "settings", "backup"], 1],
	["manager-a", "Manager A", ["view", "users"], 0],
	["manager-b", "Manager B", ["view", "users"], 0],
	["viewer", "Viewer", ["view"], 0],
];

/**
 * Each case's starting state, asserted rather than assumed: the Installation DO boots migrated *and*
 * seeded (plan's standing rule since S1.2b), so the tables already exist and already hold a seeded
 * admin and the four built-in roles. This empties them first, then writes the fixtures — otherwise
 * "the last admin" would be counting a row the test did not create.
 */
const seed = async (sql: Sql, roles: readonly RoleSpec[] = MANAGERS): Promise<void> => {
	await sql.batch([
		{ sql: USERS_TABLE.replace("CREATE TABLE users", "CREATE TABLE IF NOT EXISTS users") },
		{ sql: ROLES_TABLE.replace("CREATE TABLE roles", "CREATE TABLE IF NOT EXISTS roles") },
		{ sql: SETTINGS_TABLE.replace("CREATE TABLE settings", "CREATE TABLE IF NOT EXISTS settings") },
		{ sql: "DELETE FROM users" },
		{ sql: "DELETE FROM roles" },
		{ sql: "DELETE FROM settings" },
	]);
	await sql.batch(
		roles.map(([key, name, perms, builtin]) => ({
			sql: "INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?,?,?,?,?)",
			params: [key, name, permsJson(perms), builtin, "2026-01-01 00:00:00"],
		})),
	);
};

const addUser = async (sql: Sql, name: string, role: string, status = "active"): Promise<number> => {
	await sql.batch([
		{
			sql: "INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?,?,'x',?,?,?)",
			params: [name, name.toLowerCase(), role, status, "2026-01-01 00:00:00"],
		},
	]);
	const rows = await sql.all({ sql: "SELECT id FROM users WHERE username_key = ?", params: [name.toLowerCase()] });
	return Number(rows[0]?.id ?? 0);
};

const roleRow = async (sql: Sql, key: string) => (await listed(sql)).find((row) => row.key === key);

const rolesOf = (entries: ReadonlyArray<readonly [string, string]>): { order: string[]; names: Map<string, string> } => ({
	order: entries.map(([key]) => key),
	names: new Map(entries),
});

/** Every account role a caller may hand out, as the page would read it. */
const ACCOUNT_ROLES = rolesOf([
	["admin", "Admin"],
	["manager-a", "Manager A"],
	["manager-b", "Manager B"],
	["viewer", "Viewer"],
]);

/** v3's three messages, verbatim. */
const ONLY_ADMIN = "\u201cadmin\u201d is the only admin \u2013 make someone else an admin first.";
const ONLY_ADMIN_DELETE = "\u201cadmin\u201d is the only admin and cannot be deleted.";
const LAST_MANAGER =
	"Nobody could manage users any more \u2013 keep the Users permission or make someone an admin first.";
const LAST_MANAGER_DELETE = "Nobody could manage users any more \u2013 make someone an admin first.";

describe("the last admin cannot be demoted or deleted", () => {
	let sql: Sql;
	let id: number;

	beforeEach(async () => {
		sql = fresh();
		await seed(sql);
		id = await addUser(sql, "admin", "admin");
	});

	it("refuses with v3's message and leaves the row alone", async () => {
		await expect(setRole(sql, ADMIN, id, "viewer", ACCOUNT_ROLES)).rejects.toThrow(ONLY_ADMIN);
		const rows = await sql.all({ sql: "SELECT role FROM users WHERE id = ?", params: [id] });
		expect(rows[0]?.role).toBe("admin");
	});

	it("refuses the delete with v3's other message", async () => {
		await expect(remove(sql, ADMIN, id)).rejects.toThrow(ONLY_ADMIN_DELETE);
		const rows = await sql.all({ sql: "SELECT id FROM users WHERE id = ?", params: [id] });
		expect(rows).toHaveLength(1);
	});

	it("allows the demotion once another active admin exists", async () => {
		await addUser(sql, "second", "admin");
		await setRole(sql, ADMIN, id, "viewer", ACCOUNT_ROLES);
		const rows = await sql.all({ sql: "SELECT role FROM users WHERE id = ?", params: [id] });
		expect(rows[0]?.role).toBe("viewer");
	});

	it("does not count a pending admin as the remaining one", async () => {
		await addUser(sql, "pending", "admin", "pending");
		await expect(remove(sql, ADMIN, id)).rejects.toThrow(ONLY_ADMIN_DELETE);
	});

	it("lets exactly one of two guarded demotions in ONE batch through", async () => {
		const second = await addUser(sql, "second", "admin");
		// The race, as one unit: both statements see the same batch. Whichever the database applies
		// first removes one of the two admins, so the other statement's EXISTS no longer holds.
		await sql.batch([...setRoleStatements(id, "viewer"), ...setRoleStatements(second, "viewer")]);
		const admins = await sql.all({ sql: "SELECT id FROM users WHERE role = 'admin' AND status = 'active'" });
		expect(admins).toHaveLength(1);
	});

	it("does not let two guarded deletes in one batch remove both admins", async () => {
		const second = await addUser(sql, "second", "admin");
		await sql.batch([...deleteStatements(id), ...deleteStatements(second)]);
		const admins = await sql.all({ sql: "SELECT id FROM users WHERE role = 'admin' AND status = 'active'" });
		expect(admins).toHaveLength(1);
	});

	it("still deletes an account that is not an admin", async () => {
		const viewer = await addUser(sql, "v", "viewer");
		await remove(sql, ADMIN, viewer);
		const rows = await sql.all({ sql: "SELECT id FROM users WHERE id = ?", params: [viewer] });
		expect(rows).toHaveLength(0);
	});
});

describe("the last manager cannot lose the Users permission", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = fresh();
		await seed(sql);
	});

	it("refuses with v3's message, and the role keeps its permissions", async () => {
		await addUser(sql, "boss", "manager-a");
		await expect(updateRole(sql, "manager-a", null, ["view"])).rejects.toThrow(LAST_MANAGER);
		expect((await roleRow(sql, "manager-a"))?.perms).toEqual(["view", "users"]);
	});

	it("allows it when another role still manages users", async () => {
		await addUser(sql, "boss", "manager-a");
		await addUser(sql, "other", "manager-b");
		await updateRole(sql, "manager-a", null, ["view"]);
		expect((await roleRow(sql, "manager-a"))?.perms).toEqual(["view"]);
	});

	it("does not let two role edits in one batch strip both managers", async () => {
		await addUser(sql, "a", "manager-a");
		await addUser(sql, "b", "manager-b");
		await sql.batch([
			...updateStatements("manager-a", "Manager A", ["view"]),
			...updateStatements("manager-b", "Manager B", ["view"]),
		]);
		expect(await managers(sql)).toBe(1);
		// Exactly one of the two edits took effect: one role still holds `users`, and it is the one
		// whose account is the remaining manager.
		const holding = (await listed(sql)).filter((row) => row.perms.includes("users")).map((row) => row.key);
		expect(holding).toHaveLength(2); // the admin role (nobody holds it) and the surviving manager
		expect(holding).toContain("admin");
		const stripped = (await listed(sql)).filter((row) => !row.perms.includes("users"));
		expect(stripped.map((row) => row.key).sort()).toEqual(["manager-a", "manager-b", "viewer"].filter((key) => !holding.includes(key)));
	});

	it("refuses deleting a role whose accounts are the only managers", async () => {
		await addUser(sql, "boss", "manager-a");
		await expect(removeRole(sql, "manager-a", "viewer")).rejects.toThrow(LAST_MANAGER_DELETE);
		expect(await roleRow(sql, "manager-a")).toBeTruthy();
		const rows = await sql.all({ sql: "SELECT role FROM users WHERE username_key = 'boss'" });
		expect(rows[0]?.role).toBe("manager-a");
	});

	it("deletes it and moves its accounts when the receiving role manages users", async () => {
		await addUser(sql, "boss", "manager-a");
		await removeRole(sql, "manager-a", "manager-b");
		expect(await roleRow(sql, "manager-a")).toBeUndefined();
		const rows = await sql.all({ sql: "SELECT role FROM users WHERE username_key = 'boss'" });
		expect(rows[0]?.role).toBe("manager-b");
	});

	it("never lets the guest role hold users, settings or backup", async () => {
		await updateRole(sql, "guest", null, ["view", "users", "settings", "backup", "restore"]);
		expect((await roleRow(sql, "guest"))?.perms).toEqual(["view"]);
	});

	it("refuses the admin role and an unknown permission with v3's words", async () => {
		await expect(updateRole(sql, "admin", null, ["view"])).rejects.toThrow(
			"The admin role always has every permission.",
		);
		await expect(updateRole(sql, "viewer", null, ["nonsense"])).rejects.toThrow(
			"Unknown permission \u201cnonsense\u201d.",
		);
	});
});

describe("the roles editor's own rules", () => {
	it("keys a name the way legacy does", () => {
		expect(keyFrom("Stock Room Manager")).toBe("stock-room-manager");
		expect(keyFrom("  —  ")).toBe("");
		expect(keyFrom("a".repeat(40))).toHaveLength(30);
	});

	it("orders permissions as the list does, whatever came in", () => {
		expect(validPerms(["labels", "view", "labels"])).toEqual(["view", "labels"]);
	});

	it("creates, refuses a duplicate name, and reads the new role back", async () => {
		const sql = fresh();
		await seed(sql);
		const key = await createRole(sql, "Stock Room", ["view", "stocktake"], NOW);
		expect(key).toBe("stock-room");
		expect((await roleRow(sql, key))?.perms).toEqual(["view", "stocktake"]);
		await expect(createRole(sql, "stock room", ["view"], NOW)).rejects.toThrow(
			"A role \u201cStock Room\u201d exists already.",
		);
	});

	it("refuses a name with no letters or digits", async () => {
		const sql = fresh();
		await seed(sql);
		await expect(createRole(sql, "—", ["view"], NOW)).rejects.toThrow("Use letters or digits in the role name.");
	});

	it("lets a manager change a non-admin account, and refuses an admin one", async () => {
		const sql = fresh();
		await seed(sql);
		await addUser(sql, "boss", "manager-a");
		const admin = await addUser(sql, "root", "admin");
		const viewer = await addUser(sql, "v", "viewer");
		await setRole(sql, MANAGER, viewer, "manager-b", ACCOUNT_ROLES);
		const rows = await sql.all({ sql: "SELECT role FROM users WHERE id = ?", params: [viewer] });
		expect(rows[0]?.role).toBe("manager-b");
		await expect(setRole(sql, MANAGER, admin, "viewer", ACCOUNT_ROLES)).rejects.toThrow(
			"Only an admin can change an admin account.",
		);
		await expect(setRole(sql, MANAGER, viewer, "admin", ACCOUNT_ROLES)).rejects.toThrow(
			"Only an admin can grant the admin role.",
		);
	});
});

describe("UserError carries v3's status", () => {
	it("is the same class every refusal uses", () => {
		const error = new UserError("x", 409);
		expect(error).toBeInstanceOf(Error);
		expect(error.status).toBe(409);
		expect(error.name).toBe("UserError");
	});
});
