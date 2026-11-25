// S1.2's observable behaviour (plan §5 S1.2, D19-D21): `/login`, `/logout` and the consent gate.
//
// These run the real chain and the real handlers over the real session store, on the same in-worker
// SQLite the store spec uses. What is asserted is the DoD rather than the shape:
//
//   * without cookie consent nothing is loaded, nothing is started and no session cookie leaves (D20);
//   * a sign-in writes the row v3 would have written, and regenerates the id it was served with;
//   * the throttle counts by address *and* account, so a fresh cookie does not reset it (D21);
//   * a pre-rotation session stops authenticating once the epoch moves, and its row is destroyed.
//
// The rows a browser would already have are written with the store's own `writeSession()`, so a test
// states its starting point instead of depending on the request before it. The one test that does go
// page-then-submission is the one whose subject *is* that round trip.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";
import { type RouterConfig, routeRequest } from "../../src/http/router";
import { hashPassword } from "../../src/platform/passwords";
import { CONSENT_COOKIE } from "../../src/session/consent";
import { newToken } from "../../src/session/csrf";
import { openSession } from "../../src/session/request";
import { sessionKey, writeSession } from "../../src/session/store";
import { TEST_LOGIN, TEST_PAGE } from "../helpers/page";

const NOW = 1_764_000_000;
const LIFETIME = 30 * 86_400;
const IDLE = 168 * 3_600;
const PASSWORD = "correct horse battery staple";
const CONSENTED = `${CONSENT_COOKIE}=v1`;

/**
 * A session id of the form v3 stores: 43 characters from `[A-Za-z0-9,-]`. Standard base64's `+`
 * and `/` are mapped to the alphabet's own punctuation, as `src/session/request.ts` does.
 */
const nextId = (): string => {
	const bytes = crypto.getRandomValues(new Uint8Array(32));
	return btoa(String.fromCharCode(...bytes))
		.replace(/\+/g, "-")
		.replace(/\//g, ",")
		.replace(/=+$/, "");
};

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
	// S1.4 ported `SignInPace`: a failed sign-in now waits for the deadline (v3's 1000 ms). This
	// suite makes ten of them in a row, so it configures the deadline away; the default and the
	// clamp are asserted against the PHP fixture in `test/http/api-session.spec.ts`.
	signInDeadlineMs: 0,
};

interface Answer {
	status: number;
	headers: Headers;
	body: string;
	session: Awaited<ReturnType<typeof openSession>>;
}

/** One request, through the real session load, the real chain and the real flush. */
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
	// `index.ts` attaches the session's `Set-Cookie` lines to the answer after the chain; a test that
	// skipped it would assert against a response no browser ever sees.
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

/** What the answer set as cookies, one line each. */
const setCookies = (headers: Headers): readonly string[] => {
	const withSetter = headers as Headers & { getSetCookie?: () => string[] };
	return withSetter.getSetCookie?.() ?? [];
};

const form = (fields: Readonly<Record<string, string>>): string => {
	const body = new URLSearchParams();
	for (const [name, value] of Object.entries(fields)) {
		body.set(name, value);
	}
	return body.toString();
};

/** A sign-in submission, as a browser sends it: urlencoded, from this site. */
const post = (
	cookie: string,
	fields: Readonly<Record<string, string>>,
): RequestInit & { cookie: string } => ({
	method: "POST",
	cookie,
	headers: {
		"content-type": "application/x-www-form-urlencoded",
		"sec-fetch-site": "same-origin",
	},
	body: form(fields),
});

/** The row a page render would have left: a token, and no account yet. */
const anonymousSession = async (sql: Sql): Promise<{ id: string; token: string }> => {
	const id = nextId();
	const token = newToken();
	await writeSession(sql, {
		id,
		values: { uid: null, authEpoch: "", csrf: token, lastActivity: NOW, signedInAt: 0 },
		now: NOW,
		lifetimeSeconds: LIFETIME,
	});
	return { id, token };
};

describe("the consent gate (D20)", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = sqlite(`consent-${nextId()}`);
		await createTables(sql);
	});

	it("refuses a sign-in without consent and starts no session", async () => {
		const answer = await send(sql, "/login", post("", { username: "x", password: "y" }));
		expect(answer.status).toBe(403);
		expect(answer.body).toContain("Cookies are needed to sign in or save changes.");
		expect(setCookies(answer.headers)).toEqual([]);
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM sessions" });
		expect(Number(rows[0]?.n)).toBe(0);
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
		expect(answer.body).toContain(`name="csrf" value="${answer.session.csrf}"`);
		const line = setCookies(answer.headers).join(" ");
		expect(line).toContain("apothekesid=");
		expect(line).not.toContain("Domain=");
		expect(line).toContain("HttpOnly");
		expect(line).toContain("SameSite=Lax");
		const row = await sql.all({ sql: "SELECT data FROM sessions" });
		expect(String(row[0]?.data)).toContain("csrf|s:64:");
		expect(String(row[0]?.data)).not.toContain("uid|");
	});

	it("drops a session that arrives without consent, and expires its cookie", async () => {
		const { id } = await anonymousSession(sql);
		const answer = await send(sql, "/login", { cookie: `apothekesid=${id}` });
		expect(answer.session.consent).toBe(false);
		expect(answer.session.id).toBeNull();
		expect(answer.session.csrf).toBe("");
		expect(setCookies(answer.headers).join(" ")).toContain("apothekesid=deleted;");
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM sessions" });
		expect(Number(rows[0]?.n)).toBe(0);
	});
});

describe("POST /login", () => {
	let sql: Sql;
	let session: { id: string; token: string };
	/** The account's own id: `AUTOINCREMENT` does not restart between these tests, so it is read. */
	let userId: number;

	beforeEach(async () => {
		sql = sqlite(`signin-${nextId()}`);
		await createTables(sql);
		const hash = await hashPassword(PASSWORD);
		await sql.batch([
			{
				sql: "INSERT INTO users (username, username_key, password_hash, role, created_at, status, session_epoch) VALUES (?,?,?,?,?,?,?)",
				params: ["Ada", "ada", hash, "admin", "2026-01-01 00:00:00", "active", ""],
			},
		]);
		const inserted = await sql.all({ sql: "SELECT id FROM users WHERE username_key = 'ada'" });
		userId = Number(inserted[0]?.id ?? 0);
		session = await anonymousSession(sql);
	});

	const submit = (fields: Readonly<Record<string, string>>) =>
		send(sql, "/login", post(`${CONSENTED}; apothekesid=${session.id}`, { ...fields, csrf: session.token }));

	it("signs in: 303 to /, a row with the account and the epoch, and a new id", async () => {
		const answer = await submit({ username: "Ada", password: PASSWORD });
		expect(answer.status, answer.body.slice(0, 200)).toBe(303);
		expect(answer.headers.get("location")).toBe("/");
		// `session_regenerate_id(true)`: the id the page was served with is not the signed-in one.
		expect(answer.session.id).not.toBe(session.id);
		const row = await sql.all({
			sql: "SELECT id, data, expires_at, lock_token, locked_until FROM sessions WHERE id = ?",
			params: [await sessionKey(answer.session.id ?? "")],
		});
		const data = String(row[0]?.data);
		expect(data).toContain(`uid|i:${userId};`);
		expect(data).toContain("auth_epoch|s:16:");
		expect(data).toContain(`last_activity|i:${NOW};`);
		expect(data).toContain(`signed_in_at|i:${NOW};`);
		// The column types v3 writes, and the id is the hash of the cookie value, never the value.
		expect(String(row[0]?.id)).toHaveLength(64);
		expect(Number(row[0]?.expires_at)).toBe(NOW + LIFETIME);
		expect(row[0]?.lock_token).toBe("");
		expect(row[0]?.locked_until).toBe(0);
		// The id it replaced is gone.
		const old = await sql.all({
			sql: "SELECT COUNT(*) AS n FROM sessions WHERE id = ?",
			params: [await sessionKey(session.id)],
		});
		expect(Number(old[0]?.n)).toBe(0);
	});

	it("refuses a wrong password with the same message as an unknown name", async () => {
		const answer = await submit({ username: "Ada", password: "nope" });
		expect(answer.status, answer.body.slice(0, 200)).toBe(401);
		expect(answer.body).toContain("Wrong user name or password.");
		const unknown = await submit({ username: "Nobody", password: PASSWORD });
		expect(unknown.status, unknown.body.slice(0, 200)).toBe(401);
		expect(unknown.body).toContain("Wrong user name or password.");
	});

	it("419s a submission whose token is not the session's", async () => {
		const answer = await send(
			sql,
			"/login",
			post(`${CONSENTED}; apothekesid=${session.id}`, {
				username: "Ada",
				password: PASSWORD,
				csrf: "0".repeat(64),
			}),
		);
		expect(answer.status).toBe(419);
		expect(answer.body).toContain("Your session expired. Reload the page and try again.");
	});

	it("takes the token the page printed, end to end", async () => {
		// The one test whose subject is the round trip: page render, then the form it printed.
		const page = await send(sql, "/login", { cookie: CONSENTED });
		expect(page.status).toBe(200);
		const printed = page.session.csrf;
		expect(printed).toHaveLength(64);
		const answer = await send(
			sql,
			"/login",
			post(`${CONSENTED}; apothekesid=${page.session.id ?? ""}`, {
				username: "Ada",
				password: PASSWORD,
				csrf: printed,
			}),
		);
		expect(answer.status, answer.body.slice(0, 200)).toBe(303);
	});

	it("counts by address and account, so a fresh session does not reset it (D21)", async () => {
		for (let attempt = 0; attempt < 10; attempt += 1) {
			const answer = await submit({ username: "Ada", password: "nope" });
			expect(answer.status, `attempt ${attempt + 1}`).toBe(401);
		}
		// The eleventh is over the limit: 10 per 900 s, recorded before it is counted.
		const refused = await submit({ username: "Ada", password: "nope" });
		expect(refused.status, refused.body.slice(0, 200)).toBe(429);
		expect(refused.headers.get("retry-after")).toBe("900");
		// A brand-new session is free to mint, which is exactly why the counter does not hang off one.
		const fresh = await anonymousSession(sql);
		const again = await send(
			sql,
			"/login",
			post(`${CONSENTED}; apothekesid=${fresh.id}`, {
				username: "Ada",
				password: "nope",
				csrf: fresh.token,
			}),
		);
		expect(again.status, again.body.slice(0, 200)).toBe(429);
	});

	it("stops authenticating a pre-rotation session once the epoch moves, and destroys its row", async () => {
		const signed = await submit({ username: "Ada", password: PASSWORD });
		expect(signed.status, signed.body.slice(0, 200)).toBe(303);
		const id = signed.session.id ?? "";
		expect(id).not.toBe("");
		// The account's epoch moves: a password change, or "sign out everywhere".
		await sql.batch([
			{ sql: "UPDATE users SET session_epoch = 'ffffffffffffffff' WHERE id = ?", params: [userId] },
		]);
		// A request that carries it: `/login` again, which loads the session before the chain.
		const after = await send(sql, "/login", { cookie: `${CONSENTED}; apothekesid=${id}` });
		expect(after.session.identity).toBeNull();
		const gone = await sql.all({
			sql: "SELECT COUNT(*) AS n FROM sessions WHERE id = ?",
			params: [await sessionKey(id)],
		});
		expect(Number(gone[0]?.n)).toBe(0);
	});
});
