// Users & sign-in (plan §5 S1.3, increment 2a): the page, and the users half of its POST surface.
//
// Asserted through the real session load, the real filter chain and the real handlers, over a
// migrated database whose starting state each case writes and then asserts (the standing rule since
// S1.2b: the Installation DO boots migrated *and* seeded, so "there is one admin" is only true if the
// case put it there).
//
// The DoD here is v3's, not a shape of ours:
//
//   * the page renders for someone whose role has `users`, and a viewer's request is refused;
//   * a POST without the session's token answers v3's 419, so a create cannot happen without it;
//   * a create writes the account and redirects to `?done=user-added#users`;
//   * a refusal re-renders *the page* with v3's message and v3's status — 422 for a taken name, 409
//     for the last admin — rather than an error page of our own;
//   * the roles POSTs still answer 501, which is what the generated route table says.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { permsJson } from "../../src/auth/permissions";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";
import { type RouterConfig, routeRequest } from "../../src/http/router";
import { hashPassword } from "../../src/platform/passwords";
import { CONSENT_COOKIE } from "../../src/session/consent";
import { newToken } from "../../src/session/csrf";
import { sessionEpoch } from "../../src/session/epoch";
import { openSession } from "../../src/session/request";
import { writeSession } from "../../src/session/store";
import { TEST_LOGIN, TEST_PAGE } from "../helpers/page";

const NOW = 1_764_000_000;
const LIFETIME = 30 * 86_400;
const IDLE = 168 * 3_600;
const CONSENTED = `${CONSENT_COOKIE}=v1`;
const PASSWORD = "correct horse battery staple";

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

const SESSIONS_TABLE = `CREATE TABLE sessions (
	id           TEXT NOT NULL PRIMARY KEY,
	data         TEXT NOT NULL,
	expires_at   INTEGER NOT NULL,
	lock_token   TEXT NOT NULL DEFAULT '',
	locked_until INTEGER NOT NULL DEFAULT 0
)`;

const ATTEMPTS_TABLE = `CREATE TABLE auth_attempts (
	id     INTEGER PRIMARY KEY AUTOINCREMENT,
	kind   TEXT NOT NULL,
	client TEXT NOT NULL,
	at     INTEGER NOT NULL
)`;

let n = 0;
const fresh = (): Sql => {
	n += 1;
	return durableObjectSql(
		env.INSTALLATION.get(env.INSTALLATION.idFromName(`s1.3b-users-${n}`)) as unknown as InstallationStub,
	);
};

const CONFIG: RouterConfig = {
	publicUrl: "",
	https: false,
	hosted: false,
	maxUploadMb: 20,
	page: { ...TEST_PAGE, csrf: "", consent: false },
	login: TEST_LOGIN,
	signInEnabled: true,
};

const ROLES: ReadonlyArray<readonly [string, string, readonly string[], number]> = [
	["admin", "Admin", ["view", "users", "settings", "backup", "restore"], 1],
	["editor", "Editor", ["view", "create", "edit"], 1],
	["viewer", "Viewer", ["view"], 1],
	["guest", "Guest", ["view"], 1],
];

/** The roles, the settings rows the page reads, and nothing else: asserted before every case. */
const seed = async (sql: Sql): Promise<void> => {
	await sql.batch([
		{ sql: USERS_TABLE.replace("CREATE TABLE users", "CREATE TABLE IF NOT EXISTS users") },
		{ sql: ROLES_TABLE.replace("CREATE TABLE roles", "CREATE TABLE IF NOT EXISTS roles") },
		{ sql: SETTINGS_TABLE.replace("CREATE TABLE settings", "CREATE TABLE IF NOT EXISTS settings") },
		{ sql: SESSIONS_TABLE.replace("CREATE TABLE sessions", "CREATE TABLE IF NOT EXISTS sessions") },
		{ sql: ATTEMPTS_TABLE.replace("CREATE TABLE auth_attempts", "CREATE TABLE IF NOT EXISTS auth_attempts") },
		{ sql: "DELETE FROM users" },
		{ sql: "DELETE FROM roles" },
		{ sql: "DELETE FROM settings" },
	]);
	await sql.batch(
		ROLES.map(([key, name, perms, builtin]) => ({
			sql: "INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?,?,?,?,?)",
			params: [key, name, permsJson(perms), builtin, "2026-01-01 00:00:00"],
		})),
	);
	await sql.batch([
		{
			sql: "INSERT INTO settings (name, value, updated_at) VALUES ('time_format', '\"H:i\"', '2026-01-01 00:00:00')",
		},
		{
			sql: "INSERT INTO settings (name, value, updated_at) VALUES ('timezone', '\"UTC\"', '2026-01-01 00:00:00')",
		},
	]);
};

const addUser = async (sql: Sql, name: string, role: string, epoch = "e1"): Promise<number> => {
	const hash = await hashPassword(PASSWORD);
	await sql.batch([
		{
			sql: "INSERT INTO users (username, username_key, password_hash, role, status, created_at, session_epoch) VALUES (?,?,?,?,'active','2026-01-01 00:00:00',?)",
			params: [name, name.toLowerCase(), hash, role, epoch],
		},
	]);
	const rows = await sql.all({
		sql: "SELECT id FROM users WHERE username_key = ?",
		params: [name.toLowerCase()],
	});
	return Number(rows[0]?.id ?? 0);
};

/** A signed-in session row for an account, with the epoch the store will check it against. */
const signedIn = async (sql: Sql, id: number): Promise<{ cookie: string; token: string }> => {
	const rows = await sql.all({
		sql: "SELECT id, password_hash, session_epoch FROM users WHERE id = ?",
		params: [id],
	});
	const row = rows[0];
	if (row === undefined) {
		throw new Error("no account");
	}
	const epoch = await sessionEpoch({
		id: Number(row.id),
		passwordHash: String(row.password_hash),
		sessionEpoch: String(row.session_epoch),
	});
	const bytes = crypto.getRandomValues(new Uint8Array(32));
	const sid = btoa(String.fromCharCode(...bytes))
		.replace(/\+/g, "-")
		.replace(/\//g, ",")
		.replace(/=+$/, "");
	const token = newToken();
	await writeSession(sql, {
		id: sid,
		values: { uid: id, authEpoch: epoch, csrf: token, lastActivity: NOW, signedInAt: NOW },
		now: NOW,
		lifetimeSeconds: LIFETIME,
	});
	return { cookie: `${CONSENTED}; apothekesid=${sid}`, token };
};

interface Answer {
	readonly status: number;
	readonly headers: Headers;
	readonly body: string;
}

/** One request through the real session load, chain, handler and flush. */
const send = async (
	sql: Sql,
	path: string,
	init: RequestInit & { readonly cookie?: string } = {},
): Promise<Answer> => {
	const headers = new Headers(init.headers ?? {});
	if (init.cookie !== undefined) {
		headers.set("cookie", init.cookie);
	}
	const request = new Request(`http://localhost${path}`, { ...init, headers });
	const session = await openSession({
		sql,
		request,
		now: NOW,
		secure: false,
		limits: { lifetimeSeconds: LIFETIME, idleSeconds: IDLE },
	});
	const routed = await routeRequest(request, CONFIG, session);
	await session.flush();
	return {
		status: routed.response.status,
		headers: routed.response.headers,
		body: await routed.response.text(),
	};
};

/** A POST as a browser sends it: urlencoded, same-origin, with the session's cookie. */
const submit = (
	sql: Sql,
	cookie: string,
	path: string,
	fields: Readonly<Record<string, string>>,
): Promise<Answer> => {
	const body = new URLSearchParams();
	for (const [name, value] of Object.entries(fields)) {
		body.set(name, value);
	}
	return send(sql, path, {
		method: "POST",
		cookie,
		headers: { "content-type": "application/x-www-form-urlencoded", "sec-fetch-site": "same-origin" },
		body: body.toString(),
	});
};

/**
 * A POST with repeated fields, which is how the roles editor's `perms[]` checkboxes arrive; `headers`
 * carries the `X-Requested-With: fetch` that makes `role()` answer JSON.
 */
const submitMany = (
	sql: Sql,
	cookie: string,
	path: string,
	fields: ReadonlyArray<readonly [string, string]>,
	headers: Readonly<Record<string, string>> = {},
): Promise<Answer> => {
	const body = new URLSearchParams();
	for (const [name, value] of fields) {
		body.append(name, value);
	}
	return send(sql, path, {
		method: "POST",
		cookie,
		headers: {
			"content-type": "application/x-www-form-urlencoded",
			"sec-fetch-site": "same-origin",
			...headers,
		},
		body: body.toString(),
	});
};

describe("the Users & sign-in page", () => {
	let sql: Sql;
	let adminId: number;
	let admin: { cookie: string; token: string };

	beforeEach(async () => {
		sql = fresh();
		await seed(sql);
		adminId = await addUser(sql, "admin", "admin");
		admin = await signedIn(sql, adminId);
		const counted = await sql.all({ sql: "SELECT COUNT(*) AS n FROM users" });
		expect(Number(counted[0]?.n)).toBe(1);
	});

	it("renders for a role that has `users`, with the accounts and the roles editor", async () => {
		const answer = await send(sql, "/settings/security", { cookie: admin.cookie });
		expect(answer.status).toBe(200);
		expect(answer.body).toContain("<title>Users &amp; sign-in \u00b7 Settings \u00b7 Apotheke</title>");
		expect(answer.body).toContain('<section class="set-card" id="users">');
		expect(answer.body).toContain('<ul class="rolelist" id="rolelist">');
		expect(answer.body).toContain("<b>admin</b> <em>(you)</em>");
		expect(answer.body).toContain('name="csrf"');
		expect(answer.body).not.toContain('id="set-errors"');
	});

	it("answers a signed-out visitor with v3's redirect to /login", async () => {
		const answer = await send(sql, "/settings/security", { cookie: CONSENTED });
		expect(answer.status).toBe(303);
		expect(answer.headers.get("location")).toBe("/login");
	});

	it("refuses a role without `users`, and names the permission", async () => {
		const viewerId = await addUser(sql, "viewer", "viewer");
		const viewer = await signedIn(sql, viewerId);
		const answer = await send(sql, "/settings/security", { cookie: viewer.cookie });
		expect(answer.status).toBe(403);
		expect(answer.body).toContain(
			"Your role (Viewer) does not allow this \u2013 it needs the \u201cUsers\u201d permission. Ask an admin.",
		);
	});

	it("renders the toast a ?done= asks for", async () => {
		const answer = await send(sql, "/settings/security?done=user-added", { cookie: admin.cookie });
		expect(answer.body).toContain('<div class="toast" role="status">User added</div>');
	});
});

describe("the users POST surface", () => {
	let sql: Sql;
	let adminId: number;
	let admin: { cookie: string; token: string };

	beforeEach(async () => {
		sql = fresh();
		await seed(sql);
		adminId = await addUser(sql, "admin", "admin");
		admin = await signedIn(sql, adminId);
	});

	it("refuses a write without the session's token, at v3's 419", async () => {
		const answer = await submit(sql, admin.cookie, "/settings/users", {
			username: "bea",
			role: "viewer",
			new: PASSWORD,
			confirm: PASSWORD,
		});
		expect(answer.status).toBe(419);
		expect(answer.body).toContain("Your session expired. Reload the page and try again.");
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM users" });
		expect(Number(rows[0]?.n)).toBe(1);
	});

	it("creates an account and redirects with v3's toast", async () => {
		const answer = await submit(sql, admin.cookie, "/settings/users", {
			csrf: admin.token,
			username: "bea",
			role: "viewer",
			new: PASSWORD,
			confirm: PASSWORD,
		});
		expect(answer.status).toBe(303);
		expect(answer.headers.get("location")).toBe("/settings/security?done=user-added#users");
		const rows = await sql.all({ sql: "SELECT username, role, status FROM users ORDER BY id" });
		expect(rows.map((row) => [row.username, row.role, row.status])).toEqual([
			["admin", "admin", "active"],
			["bea", "viewer", "active"],
		]);
	});

	it("re-renders the page with v3's message and status when the name is taken", async () => {
		const answer = await submit(sql, admin.cookie, "/settings/users", {
			csrf: admin.token,
			username: "admin",
			role: "viewer",
			new: PASSWORD,
			confirm: PASSWORD,
		});
		expect(answer.status).toBe(422);
		expect(answer.body).toContain('id="users-err"');
		expect(answer.body).toContain("The user name \u201cadmin\u201d is taken.");
		expect(answer.body).toContain('<section class="set-card" id="users">');
	});

	it("refuses the last admin's demotion at 409, on the page", async () => {
		const answer = await submit(sql, admin.cookie, `/settings/users/${adminId}/role`, {
			csrf: admin.token,
			role: "viewer",
		});
		expect(answer.status).toBe(409);
		expect(answer.body).toContain(
			"\u201cadmin\u201d is the only admin \u2013 make someone else an admin first.",
		);
		const rows = await sql.all({ sql: "SELECT role FROM users WHERE id = ?", params: [adminId] });
		expect(rows[0]?.role).toBe("admin");
	});

	it("refuses deleting your own account at 409", async () => {
		const answer = await submit(sql, admin.cookie, `/settings/users/${adminId}/delete`, {
			csrf: admin.token,
		});
		expect(answer.status).toBe(409);
		expect(answer.body).toContain("You cannot delete your own account while signed in with it.");
	});

	it("renames and approves, redirecting to the section's own anchor", async () => {
		const pendingId = await addUser(sql, "cy", "viewer");
		await sql.batch([{ sql: "UPDATE users SET status = 'pending' WHERE id = ?", params: [pendingId] }]);
		const approved = await submit(sql, admin.cookie, `/settings/users/${pendingId}/approve`, {
			csrf: admin.token,
		});
		expect(approved.status).toBe(303);
		expect(approved.headers.get("location")).toBe("/settings/security?done=user-approved#users");
		const renamed = await submit(sql, admin.cookie, `/settings/users/${pendingId}/rename`, {
			csrf: admin.token,
			username: "cora",
		});
		expect(renamed.status).toBe(303);
		const rows = await sql.all({
			sql: "SELECT username, status FROM users WHERE id = ?",
			params: [pendingId],
		});
		expect(rows[0]?.username).toBe("cora");
		expect(rows[0]?.status).toBe("active");
	});

	it("answers a bare /settings/users/{id} with v3's 404 on the page", async () => {
		const answer = await submit(sql, admin.cookie, `/settings/users/${adminId}`, { csrf: admin.token });
		expect(answer.status).toBe(404);
		expect(answer.body).toContain("Page not found.");
	});
});

describe("the roles POST surface", () => {
	let sql: Sql;
	let adminId: number;
	let admin: { cookie: string; token: string };

	beforeEach(async () => {
		sql = fresh();
		await seed(sql);
		adminId = await addUser(sql, "admin", "admin");
		admin = await signedIn(sql, adminId);
	});

	const roleRow = async (
		key: string,
	): Promise<{ name: string; perms: string[]; builtin: number } | undefined> => {
		const rows = await sql.all({
			sql: "SELECT name, perms, builtin FROM roles WHERE role_key = ?",
			params: [key],
		});
		const row = rows[0];
		return row === undefined
			? undefined
			: {
					name: String(row.name),
					perms: JSON.parse(String(row.perms)) as string[],
					builtin: Number(row.builtin),
				};
	};

	it("refuses a roles write without the session's token, at v3's 419", async () => {
		const answer = await submit(sql, admin.cookie, "/settings/roles", { name: "Manager" });
		expect(answer.status).toBe(419);
		expect(answer.body).toContain("Your session expired. Reload the page and try again.");
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM roles" });
		expect(Number(rows[0]?.n)).toBe(4);
		expect(await roleRow("manager")).toBeUndefined();
	});

	it("creates a role from its `perms[]` list and redirects to its card", async () => {
		const answer = await submitMany(sql, admin.cookie, "/settings/roles", [
			["csrf", admin.token],
			["name", "Manager"],
			["perms[]", "view"],
			["perms[]", "users"],
		]);
		expect(answer.status).toBe(303);
		expect(answer.headers.get("location")).toBe("/settings/security?done=role-added#rc-manager");
		expect(await roleRow("manager")).toEqual({ name: "Manager", perms: ["view", "users"], builtin: 0 });
	});

	it("re-renders the page under Roles when the name is taken, at v3's 409", async () => {
		const answer = await submitMany(sql, admin.cookie, "/settings/roles", [
			["csrf", admin.token],
			["name", "Editor"],
		]);
		expect(answer.status).toBe(409);
		expect(answer.body).toContain('id="roles-err"');
		expect(answer.body).toContain("A role \u201cEditor\u201d exists already.");
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM roles" });
		expect(Number(rows[0]?.n)).toBe(4);
	});

	it("saves a role's permissions, redirecting to its card", async () => {
		const answer = await submitMany(sql, admin.cookie, "/settings/roles/editor", [
			["csrf", admin.token],
			["perms[]", "view"],
			["perms[]", "export"],
		]);
		expect(answer.status).toBe(303);
		expect(answer.headers.get("location")).toBe("/settings/security?done=role-saved#rc-editor");
		expect(await roleRow("editor")).toEqual({ name: "Editor", perms: ["view", "export"], builtin: 1 });
	});

	it("refuses changing the admin role at v3's 409", async () => {
		const answer = await submitMany(sql, admin.cookie, "/settings/roles/admin", [
			["csrf", admin.token],
			["perms[]", "view"],
		]);
		expect(answer.status).toBe(409);
		expect(answer.body).toContain("The admin role always has every permission.");
	});

	it("answers an unknown role with v3's 404 on the page", async () => {
		const answer = await submitMany(sql, admin.cookie, "/settings/roles/nope", [
			["csrf", admin.token],
			["perms[]", "view"],
		]);
		expect(answer.status).toBe(404);
		expect(answer.body).toContain("Role not found.");
	});

	it("never gives the guest role the permissions v3 withholds from it", async () => {
		const answer = await submitMany(sql, admin.cookie, "/settings/roles/guest", [
			["csrf", admin.token],
			["perms[]", "view"],
			["perms[]", "users"],
			["perms[]", "backup"],
		]);
		expect(answer.status).toBe(303);
		expect(await roleRow("guest")).toEqual({ name: "Guest", perms: ["view"], builtin: 1 });
	});

	it("deletes a role into the one its accounts move to, and moves them", async () => {
		await submitMany(sql, admin.cookie, "/settings/roles", [
			["csrf", admin.token],
			["name", "Packer"],
			["perms[]", "view"],
		]);
		const packerId = await addUser(sql, "polly", "packer");
		const answer = await submitMany(sql, admin.cookie, "/settings/roles/packer/delete", [
			["csrf", admin.token],
			["move_to", "viewer"],
		]);
		expect(answer.status).toBe(303);
		expect(answer.headers.get("location")).toBe("/settings/security?done=role-deleted#roles");
		expect(await roleRow("packer")).toBeUndefined();
		const rows = await sql.all({ sql: "SELECT role FROM users WHERE id = ?", params: [packerId] });
		expect(rows[0]?.role).toBe("viewer");
	});

	it("refuses deleting a built-in role, and a guest move target, at v3's statuses", async () => {
		const builtin = await submitMany(sql, admin.cookie, "/settings/roles/editor/delete", [
			["csrf", admin.token],
			["move_to", "viewer"],
		]);
		expect(builtin.status).toBe(409);
		expect(builtin.body).toContain("Built-in roles cannot be deleted.");
		await submitMany(sql, admin.cookie, "/settings/roles", [
			["csrf", admin.token],
			["name", "Packer"],
		]);
		const guestTarget = await submitMany(sql, admin.cookie, "/settings/roles/packer/delete", [
			["csrf", admin.token],
			["move_to", "guest"],
		]);
		expect(guestTarget.status).toBe(422);
		expect(guestTarget.body).toContain("Choose another role for its accounts.");
		expect(await roleRow("packer")).toBeDefined();
	});

	it("refuses deleting the role that is the install's only manager, at v3's 409", async () => {
		await submitMany(sql, admin.cookie, "/settings/roles", [
			["csrf", admin.token],
			["name", "Ops"],
			["perms[]", "view"],
			["perms[]", "users"],
		]);
		const opsId = await addUser(sql, "olive", "ops");
		// Nobody but ops can manage users: the seeded admin account is removed so the count can reach 0.
		await sql.batch([{ sql: "DELETE FROM users WHERE id = ?", params: [adminId] }]);
		const ops = await signedIn(sql, opsId);
		const answer = await submitMany(sql, ops.cookie, "/settings/roles/ops/delete", [
			["csrf", ops.token],
			["move_to", "viewer"],
		]);
		expect(answer.status).toBe(409);
		expect(answer.body).toContain("Nobody could manage users any more \u2013 make someone an admin first.");
		expect(await roleRow("ops")).toBeDefined();
		const rows = await sql.all({ sql: "SELECT role FROM users WHERE id = ?", params: [opsId] });
		expect(rows[0]?.role).toBe("ops");
	});

	it("answers a fetch as JSON: {ok, key} on success, {ok: false, error} on a refusal", async () => {
		const FETCH = { "x-requested-with": "fetch" };
		const created = await submitMany(
			sql,
			admin.cookie,
			"/settings/roles",
			[
				["csrf", admin.token],
				["name", "Manager"],
				["perms[]", "view"],
			],
			FETCH,
		);
		expect(created.status).toBe(200);
		expect(created.headers.get("content-type")).toBe("application/json; charset=utf-8");
		expect(created.body).toBe('{"ok":true,"key":"manager"}');
		const refused = await submitMany(
			sql,
			admin.cookie,
			"/settings/roles",
			[
				["csrf", admin.token],
				["name", "Editor"],
			],
			FETCH,
		);
		expect(refused.status).toBe(409);
		expect(refused.body).toBe('{"ok":false,"error":"A role \u201cEditor\u201d exists already."}');
	});
});
