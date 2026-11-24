// S1.2b's observable behaviour (plan §5 S1.2b, D22/D24): `/claim`, the hosted first-admin link.
//
// The DoD, asserted rather than described:
//
//   * with `APOTHEKE_HOSTED` false it is 404, and a POST learns nothing either;
//   * on a database with no admin, `/claim` renders v3's page;
//   * a valid token creates exactly one active admin and answers 303 to `/?done=welcome`;
//   * a second use of the same token is refused with v3's answer (410, "no longer valid");
//   * an install that already has an admin answers as v3 does — 410 for a right token and a wrong
//     one alike, the claim row removed, nobody added;
//   * only the token's SHA-256 is stored, and the window is D24's 72 hours.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { CLAIM_HOURS, createClaim, SETTING } from "../../src/auth/first-admin-claim";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";
import { type RouterConfig, routeRequest } from "../../src/http/router";
import { verifyPassword } from "../../src/platform/passwords";
import { CONSENT_COOKIE } from "../../src/session/consent";
import { newToken } from "../../src/session/csrf";
import { sha256Hex } from "../../src/session/digest";
import { openSession } from "../../src/session/request";
import { writeSession } from "../../src/session/store";
import { TEST_LOGIN, TEST_PAGE } from "../helpers/page";

const NOW = 1_764_000_000;
const LIFETIME = 30 * 86_400;
const IDLE = 168 * 3_600;
const PASSWORD = "correct horse battery staple";
const CONSENTED = `${CONSENT_COOKIE}=v1`;
const WRONG = "This claim link is not valid. Ask for a new one.";

const SETTINGS_TABLE =
	"CREATE TABLE IF NOT EXISTS settings (name TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)";
const USERS_TABLE = `CREATE TABLE IF NOT EXISTS users (
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
const SESSIONS_TABLE = `CREATE TABLE IF NOT EXISTS sessions (
	id           TEXT NOT NULL PRIMARY KEY,
	data         TEXT NOT NULL,
	expires_at   INTEGER NOT NULL,
	lock_token   TEXT NOT NULL DEFAULT '',
	locked_until INTEGER NOT NULL DEFAULT 0
)`;
const CLAIM_ROW = "INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)";
const ADMIN_ROW =
	"INSERT INTO users (username, username_key, password_hash, role, created_at) VALUES (?, ?, 'x', 'admin', '2026-01-01 00:00:00')";
const ATTEMPTS_TABLE =
	"CREATE TABLE IF NOT EXISTS auth_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, client TEXT NOT NULL, at INTEGER NOT NULL)";

/** A session id of the form v3 stores: 43 characters from `[A-Za-z0-9,-]`. */
const nextId = (): string => {
	const bytes = crypto.getRandomValues(new Uint8Array(32));
	return btoa(String.fromCharCode(...bytes))
		.replace(/\+/g, "-")
		.replace(/\//g, ",")
		.replace(/=+$/, "");
};

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

/** The tables this file needs: 0013's settings, 0018/0022/0028's users, 0034's sessions, 0026's attempts. */
const createTables = async (sql: Sql): Promise<void> => {
	await sql.batch([
		{ sql: SETTINGS_TABLE },
		{ sql: USERS_TABLE },
		{ sql: SESSIONS_TABLE },
		{ sql: ATTEMPTS_TABLE },
	]);
};

/**
 * The `Installation` Durable Object boots migrated and seeded — `APP_PASSWORD` is set in the test vars,
 * so `prepare()` creates the `admin` account — and this slice's subject is the *unclaimed* install. So
 * every test starts from the same place: no accounts, no sessions, no attempts, no claim row.
 */
const reset = async (sql: Sql): Promise<void> => {
	await sql.batch([
		{ sql: "DELETE FROM users" },
		{ sql: "DELETE FROM sessions" },
		{ sql: "DELETE FROM auth_attempts" },
		{ sql: "DELETE FROM settings WHERE name = ?", params: [SETTING] },
	]);
};

const config = (hosted: boolean): RouterConfig => ({
	publicUrl: "",
	https: false,
	hosted,
	maxUploadMb: 20,
	page: { ...TEST_PAGE, csrf: "", consent: false },
	login: TEST_LOGIN,
	signInEnabled: true,
});

interface Answer {
	readonly status: number;
	readonly headers: Headers;
	readonly body: string;
	readonly session: Awaited<ReturnType<typeof openSession>>;
}

const FORM_HEADERS = {
	"content-type": "application/x-www-form-urlencoded",
	"sec-fetch-site": "same-origin",
};

const form = (fields: Readonly<Record<string, string>>): string => {
	const body = new URLSearchParams();
	for (const [name, value] of Object.entries(fields)) {
		body.set(name, value);
	}
	return body.toString();
};

/** A form submission as a browser sends it: the consent cookie, the session's id, its token. */
const post = (
	session: { readonly cookie: string; readonly token: string },
	fields: Readonly<Record<string, string>>,
): RequestInit & { cookie: string } => ({
	method: "POST",
	cookie: session.cookie,
	headers: FORM_HEADERS,
	body: form({ csrf: session.token, ...fields }),
});

/** One request, through the real session load, the real chain and the real flush. */
const send = async (
	sql: Sql,
	hosted: boolean,
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
	const routed = await routeRequest(request, config(hosted), session);
	await session.flush();
	let response = routed.response;
	const lines = session.cookieLines();
	if (lines.length > 0) {
		const withCookies = new Headers(response.headers);
		for (const line of lines) {
			withCookies.append("set-cookie", line);
		}
		response = new Response(response.body, { status: response.status, headers: withCookies });
	}
	return { status: response.status, headers: response.headers, body: await response.clone().text(), session };
};

/** A claim submission through the hosted Worker. */
const claim = async (
	sql: Sql,
	hosted: boolean,
	session: { readonly cookie: string; readonly token: string },
	fields: Readonly<Record<string, string>>,
): Promise<Answer> => send(sql, hosted, "/claim", post(session, fields));

/** The session a page render would have left: a token, no account. */
const anonymousSession = async (
	sql: Sql,
	csrf: string = newToken(),
): Promise<{ cookie: string; token: string }> => {
	const id = nextId();
	const token = csrf;
	await writeSession(sql, {
		id,
		values: { uid: null, authEpoch: "", csrf: token, lastActivity: NOW, signedInAt: 0 },
		now: NOW,
		lifetimeSeconds: LIFETIME,
	});
	return { cookie: `${CONSENTED}; apothekesid=${id}`, token };
};

/** A claim row as `create()` writes it, for a test that starts after the link was made. */
const storeClaim = async (sql: Sql, token: string, expires: number): Promise<void> => {
	const value = JSON.stringify({ sha256: await sha256Hex(token), expires });
	await sql.batch([{ sql: CLAIM_ROW, params: [SETTING, value, "2026-01-01 00:00:00"] }]);
};

const addAdmin = async (sql: Sql, name: string): Promise<void> => {
	await sql.batch([{ sql: ADMIN_ROW, params: [name, name.toLowerCase()] }]);
};

const settingsValue = async (sql: Sql): Promise<unknown> => {
	const rows = await sql.all({ sql: "SELECT value FROM settings WHERE name = ?", params: [SETTING] });
	return rows[0]?.value;
};

const users = async (sql: Sql): Promise<readonly Record<string, unknown>[]> =>
	sql.all({ sql: "SELECT id, username, role, status, password_hash FROM users ORDER BY id" });

/** The fields a claim POST carries, so a test names only the token and the name. */
const fields = (token: string, username: string): Record<string, string> => ({
	token,
	username,
	new: PASSWORD,
	confirm: PASSWORD,
});

describe("the claim gate (D22)", () => {
	let sql: Sql;
	beforeEach(async () => {
		sql = sqlite(`claim-${nextId()}`);
		await createTables(sql);
		await reset(sql);
	});

	it("is 404 off a hosted install, and a POST adds nobody", async () => {
		const page = await send(sql, false, "/claim");
		expect(page.status).toBe(404);
		expect(page.body).toContain("Page not found.");

		await storeClaim(sql, "not-a-claim", NOW + 3600);
		const session = await anonymousSession(sql);
		const answer = await claim(sql, false, session, fields("not-a-claim", "owner"));
		expect(answer.status).toBe(404);
		expect(await users(sql)).toEqual([]);
	});
});

describe("a hosted install with no admin (D22)", () => {
	let sql: Sql;
	beforeEach(async () => {
		sql = sqlite(`claim-${nextId()}`);
		await createTables(sql);
		await reset(sql);
	});

	it("renders v3's page, with the token field and no store", async () => {
		const answer = await send(sql, true, "/claim");
		expect(answer.status).toBe(200);
		expect(answer.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(answer.headers.get("cache-control")).toBe("no-store");
		expect(answer.body).toContain('id="claim-token"');
		expect(answer.body).toContain('id="claim-form"');
		expect(answer.body).toContain("Set up your Apotheke");
		expect(answer.body).toContain("This account becomes the admin. The link works once.");
	});

	it("takes the link, makes one active admin and answers 303", async () => {
		const made = await createClaim(sql, NOW);
		expect(made.token).toMatch(/^[A-Za-z0-9_-]{43}$/);
		// Only the hash is stored, and the window is D24's 72 hours.
		const stored = String(await settingsValue(sql));
		expect(stored).not.toContain(made.token);
		const claimRow = { sha256: await sha256Hex(made.token), expires: NOW + CLAIM_HOURS * 3600 };
		expect(JSON.parse(stored)).toEqual(claimRow);

		const session = await anonymousSession(sql);
		const answer = await claim(sql, true, session, fields(made.token, "owner"));
		expect(answer.status).toBe(303);
		expect(answer.headers.get("location")).toBe("/?done=welcome");

		const rows = await users(sql);
		expect(rows.map((row) => [row.username, row.role, row.status])).toEqual([["owner", "admin", "active"]]);
		expect(await verifyPassword(PASSWORD, String(rows[0]?.password_hash))).toBe(true);
		expect(await settingsValue(sql)).toBeUndefined();
		// Signed in by the claim itself: the session carries the account the claim made. Not a literal
		// id — this database has already had a seeded admin, and AUTOINCREMENT does not restart.
		expect(answer.session.identity?.userId).toBe(Number(rows[0]?.id));
	});

	it("refuses a second use of the same token with v3's answer", async () => {
		const made = await createClaim(sql, NOW);
		const first = await anonymousSession(sql);
		expect((await claim(sql, true, first, fields(made.token, "owner"))).status).toBe(303);

		const second = await anonymousSession(sql);
		const twice = await claim(sql, true, second, fields(made.token, "again"));
		expect(twice.status).toBe(410);
		expect(twice.body).toContain("This claim link is no longer valid.");
		expect((await users(sql)).map((row) => row.username)).toEqual(["owner"]);
	});

	it("refuses a wrong token with 403 and says nothing about the right one", async () => {
		const made = await createClaim(sql, NOW);
		const session = await anonymousSession(sql);
		const answer = await claim(sql, true, session, fields(`${made.token}x`, "owner"));
		expect(answer.status).toBe(403);
		expect(answer.body).toContain(WRONG);
		expect(await users(sql)).toEqual([]);
	});

	it("refuses an expired token with 410", async () => {
		await storeClaim(sql, "expired-token", NOW - 1);
		const session = await anonymousSession(sql);
		const answer = await claim(sql, true, session, fields("expired-token", "owner"));
		expect(answer.status).toBe(410);
		expect(answer.body).toContain("This claim link has expired. Ask for a new one.");
		expect(await users(sql)).toEqual([]);
	});
});

describe("a hosted install that already has an admin (D22)", () => {
	let sql: Sql;
	beforeEach(async () => {
		sql = sqlite(`claim-${nextId()}`);
		await createTables(sql);
		await reset(sql);
		await addAdmin(sql, "boss");
	});

	it("answers a right token and a wrong one alike, removes the claim and adds nobody", async () => {
		const answers: { status: number; body: string }[] = [];
		// One token for both submissions: the page prints it, so two sessions would differ by it alone.
		const csrf = newToken();
		for (const which of ["right", "wrong"]) {
			await storeClaim(sql, "the-token", NOW + 86_400);
			const session = await anonymousSession(sql, csrf);
			const token = which === "right" ? "the-token" : "enohT";
			const answer = await claim(sql, true, session, fields(token, "owner"));
			answers.push({ status: answer.status, body: answer.body });
			expect(await settingsValue(sql), `${which}: the claim is removed`).toBeUndefined();
		}
		expect(answers[0]?.status).toBe(410);
		expect(answers[0]?.body).toContain("This claim link is no longer valid.");
		expect(answers[1]).toEqual(answers[0]);
		expect((await users(sql)).map((row) => row.username)).toEqual(["boss"]);
	});

	it("refuses create() with 409 while an active admin exists", async () => {
		await expect(createClaim(sql, NOW)).rejects.toMatchObject({
			status: 409,
			message: "An active admin already exists, so no claim was made.",
		});
	});
});
