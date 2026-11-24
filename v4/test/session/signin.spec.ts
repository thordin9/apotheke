// S1.2's observable behaviour (plan §5 S1.2, D19-D21): `/login`, `/logout` and the consent gate.
//
// These run the real chain and the real handlers over the real session store, on the same in-worker
// SQLite the store spec uses. What is asserted is the DoD rather than the shape:
//
//   * without cookie consent nothing is loaded, nothing is started and no session cookie leaves (D20);
//   * a sign-in writes the row v3 would have written, and regenerates the id it was served with;
//   * the throttle counts by address *and* account, so a fresh cookie does not reset it (D21);
//   * a pre-rotation session stops authenticating once the epoch moves, and its row is destroyed.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";
import { routeRequest, type RouterConfig } from "../../src/http/router";
import { hashPassword } from "../../src/platform/passwords";
import { CONSENT_COOKIE } from "../../src/session/consent";
import { sessionKey } from "../../src/session/store";
import { openSession } from "../../src/session/request";
import { TEST_LOGIN, TEST_PAGE } from "../helpers/page";

const NOW = 1_764_000_000;
const LIFETIME = 30 * 86_400;
const IDLE = 168 * 3_600;
const PASSWORD = "correct horse battery staple";

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

/** The three tables this file needs: 0034's sessions, 0026's users, 0022's auth_attempts. */
const createTables = async (sql: Sql): Promise<void> => {
	await sql.batch([
		{
			sql: `CREATE TABLE IF NOT EXISTS sessions (
				id           TEXT NOT NULL PRIMARY KEY,
				data         TEXT NOT NULL,
				expires_at   INTEGER NOT NULL,
				lock_token   TEXT NOT NULL DEFAULT '',
				locked_until INTEGER NOT NULL DEFAULT 0
			)`,
		},
		{
			sql: `CREATE TABLE IF NOT EXISTS users (
				id            INTEGER PRIMARY KEY AUTOINCREMENT,
				username      TEXT NOT NULL,
				username_key  TEXT NOT NULL UNIQUE,
				password_hash TEXT NOT NULL,
				role          TEXT NOT NULL,
				created_at    TEXT NOT NULL,
				last_login_at TEXT,
				status        TEXT NOT NULL DEFAULT 'active',
				session_epoch TEXT NOT NULL DEFAULT ''
			)`,
		},
		{
			sql: `CREATE TABLE IF NOT EXISTS auth_attempts (
				id     INTEGER PRIMARY KEY AUTOINCREMENT,
				kind   TEXT NOT NULL,
				client TEXT NOT NULL,
				at     INTEGER NOT NULL
			)`,
		},
	]);
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

/** One request, through the real session load, the real chain and the real flush. */
const send = async (
	sql: Sql,
	path: string,
	init: RequestInit & { readonly cookie?: string } = {},
): Promise<{ status: number; headers: Headers; body: string; session: Awaited<ReturnType<typeof openSession>> }> => {
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
	// `index.ts` attaches the session's `Set-Cookie` lines to the answer after the chain; a test that
	// skipped it would assert against a response no browser ever sees.
	let response = routed.response;
	const lines = session.cookieLines();
	if (lines.length > 0) {
		const headers = new Headers(response.headers);
		for (const line of lines) {
			headers.append("set-cookie", line);
		}
		response = new Response(response.body, { status: response.status, headers });
	}
	return {
		status: response.status,
		headers: response.headers,
		body: await response.clone().text(),
		session,
	};
};

/** The Set-Cookie values a response carries, split out of the header map. */
const setCookies = (headers: Headers): readonly string[] => {
	const withSetter = headers as Headers & { getSetCookie?: () => string[] };
	return withSetter.getSetCookie?.() ?? [];
};

const form = (fields: Readonly<Record<string, string>>): URLSearchParams => {
	const body = new URLSearchParams();
	for (const [name, value] of Object.entries(fields)) {
		body.set(name, value);
	}
	return body;
};

/** A sign-in submission, as a browser sends it: urlencoded, from this site. */
const post = (cookie: string, fields: Readonly<Record<string, string>>): RequestInit & { cookie: string } => ({
	method: "POST",
	cookie,
	headers: {
		"content-type": "application/x-www-form-urlencoded",
		"sec-fetch-site": "same-origin",
	},
	body: form(fields).toString(),
});

const CONSENTED = `${CONSENT_COOKIE}=v1`;

describe("the consent gate (D20)", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = sqlite(`consent-${Math.random()}`);
		await createTables(sql);
	});

	it("refuses a sign-in without consent and starts no session", async () => {
		const answer = await send(sql, "/login", post("", { username: "x", password: "y" }));
		expect(answer.status).toBe(403);
		expect(answer.body).toContain("Cookies are needed to sign in or save changes.");
		expect(setCookies(answer.headers)).toEqual([]);
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM sessions" });
		expect(rows[0]?.n).toBe(0);
	});

	it("renders the page without consent, with no token and no cookie", async () => {
		const answer = await send(sql, "/login");
		expect(answer.status).toBe(200);
		expect(answer.body).toContain('name="csrf" value=""');
		expect(setCookies(answer.headers)).toEqual([]);
	});

	it("starts an anonymous session for the token when consent is present", async () => {
		const answer = await send(sql, "/login", { cookie: CONSENTED });
		expect(answer.status).toBe(200);
		expect(answer.session.csrf).toHaveLength(64);
		const line = setCookies(answer.headers).join(" ");
		expect(line).toContain("apothekesid=");
		expect(line).not.toContain("Domain=");
		expect(line).toContain("HttpOnly");
		expect(line).toContain("SameSite=Lax");
		const row = await sql.all({ sql: "SELECT data FROM sessions" });
		expect(String(row[0]?.data)).toContain("csrf|s:64:");
		expect(String(row[0]?.data)).not.toContain("uid|");
	});
});

describe("POST /login", () => {
	let sql: Sql;
	let hash: string;

	beforeEach(async () => {
		sql = sqlite(`signin-${Math.random()}`);
		await createTables(sql);
		hash = await hashPassword(PASSWORD);
		await sql.batch([
			{
				sql: "INSERT INTO users (username, username_key, password_hash, role, created_at, status, session_epoch) VALUES (?,?,?,?,?,?,?)",
				params: ["Ada", "ada", hash, "admin", "2026-01-01 00:00:00", "active", ""],
			},
		]);
	});

	/** The token a GET /login hands out, and the cookie that carries it. */
	const tokenAndCookie = async (): Promise<{ token: string; cookie: string }> => {
		const answer = await send(sql, "/login", { cookie: CONSENTED });
		const line = setCookies(answer.headers).join(" ");
		const at = line.indexOf("apothekesid=");
		const value = line.slice(at + "apothekesid=".length).split(";")[0] ?? "";
		return { token: answer.session.csrf, cookie: `${CONSENTED}; apothekesid=${value}` };
	};

	it("signs in: 303 to /, a row with the account and the epoch, and a new id", async () => {
		const { token, cookie } = await tokenAndCookie();
		const answer = await send(
			sql,
			"/login",
			post(cookie, { username: "Ada", password: PASSWORD, csrf: token }),
		);
		expect(answer.status).toBe(303);
		expect(answer.headers.get("location")).toBe("/");
		// `session_regenerate_id(true)`: the id the page was served with is not the signed-in one.
		expect(answer.session.id).not.toBe(cookie.split("apothekesid=")[1]);
		const row = await sql.all({ sql: "SELECT id, data, expires_at, lock_token, locked_until FROM sessions" });
		const data = String(row[0]?.data);
		expect(data).toContain("uid|i:1;");
		expect(data).toContain("auth_epoch|s:16:");
		expect(data).toContain(`last_activity|i:${NOW};`);
		expect(data).toContain(`signed_in_at|i:${NOW};`);
		// The column types v3 writes: the id is the hash of the cookie value, never the value.
		expect(String(row[0]?.id)).toHaveLength(64);
		expect(String(row[0]?.id)).toBe(await sessionKey(answer.session.id ?? ""));
		expect(Number(row[0]?.expires_at)).toBe(NOW + LIFETIME);
		expect(row[0]?.lock_token).toBe("");
		expect(row[0]?.locked_until).toBe(0);
	});

	it("refuses a wrong password with the same message as an unknown name", async () => {
		const { token, cookie } = await tokenAndCookie();
		const wrong = await send(sql, "/login", post(cookie, { username: "Ada", password: "nope", csrf: token }));
		expect(wrong.status).toBe(401);
		expect(wrong.body).toContain("Wrong user name or password.");
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM auth_attempts" });
		expect(Number(rows[0]?.n)).toBe(2); // login_address and login, both recorded
	});

	it("counts by address and account, so a fresh session does not reset it (D21)", async () => {
		let token = "";
		let cookie = "";
		// Eleven failures from one address for one account, each with the token it was served.
		for (let attempt = 0; attempt < 11; attempt += 1) {
			const fresh = await tokenAndCookie();
			token = fresh.token;
			cookie = fresh.cookie;
			await send(sql, "/login", post(cookie, { username: "Ada", password: "nope", csrf: token }));
		}
		const last = await send(sql, "/login", post(cookie, { username: "Ada", password: "nope", csrf: token }));
		expect(last.status).toBe(429);
		expect(last.headers.get("retry-after")).toBe("900");
		// A brand-new cookie is free to mint, which is exactly why the counter does not hang off one.
		const elsewhere = await tokenAndCookie();
		const again = await send(
			sql,
			"/login",
			post(elsewhere.cookie, { username: "Ada", password: "nope", csrf: elsewhere.token }),
		);
		expect(again.status).toBe(429);
	});

	it("stops authenticating a pre-rotation session once the epoch moves, and destroys its row", async () => {
		const { token, cookie } = await tokenAndCookie();
		const signed = await send(sql, "/login", post(cookie, { username: "Ada", password: PASSWORD, csrf: token }));
		expect(signed.status).toBe(303);
		const id = signed.session.id ?? "";
		// The account's epoch moves: a password change, or "sign out everywhere".
		await sql.batch([{ sql: "UPDATE users SET session_epoch = 'ffffffffffffffff' WHERE id = 1" }]);
		const after = await send(sql, "/login", { cookie: `${CONSENTED}; apothekesid=${id}` });
		expect(after.session.identity).toBeNull();
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM sessions WHERE id = ?", params: [await sessionKey(id)] });
		expect(Number(rows[0]?.n)).toBe(0);
	});
});
