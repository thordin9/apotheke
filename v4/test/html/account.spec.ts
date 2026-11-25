// `/account`, `POST /account` and `POST /account/signout` (plan §5 S1.5): v3's answers, replayed.
//
// The oracle is `test/fixtures/php-account.json`: 35 real HTTP answers captured from the PHP app by
// `scripts/gen-account-fixture.sh`, each with the request that produced it and the state it was asked
// from. This file restates none of them — it builds the same starting state, sends the same request
// through the real session load, filter chain, handler and flush, and compares status, the pinned
// headers and the body *bytes*. So the page (shell, header, menu, card, toast), the re-render after
// every refusal, the 419s and the 303s are all asserted without being typed here.
//
// Two sessions of the same viewer are replayed (`a` and `b`): "sign out everywhere" has to leave one
// device signed in and end the other, and that is only visible with two jars. Three more (`c`, `d`,
// `e`) are the fresh clients the capture used for the old/new password, the admin and the throttle.
//
// Three things are normalised, mirroring the writer: every CSRF token (the fixture says `<csrf>`, and
// the replay fills in its own), the session cookie's value, and its expiry. The token is the one value
// the replay cannot derive from a normalised body — so each jar reads it from the page it was just
// served, which is what a browser does with the form it is about to post.
//
// The clock advances one minute per request: the page carries no timestamp, so the fixture does not
// see it, but `keepSignIn` (the sign-in time a password change must keep) is only observable when the
// change happens later than the sign-in.

import { env } from "cloudflare:workers";
import { beforeAll, describe, expect, it } from "vitest";
import { changeOwnPassword, signOutEverywhere } from "../../src/auth/account";
import { permsJson, ROLE_DEFAULTS } from "../../src/auth/permissions";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import { type Sql, statement } from "../../src/db/sql";
import type { Ctx } from "../../src/http/filters";
import { type RouterConfig, routeRequest } from "../../src/http/router";
import type { AppConfig } from "../../src/platform/config";
import { hashPassword } from "../../src/platform/passwords";
import { CONSENT_COOKIE } from "../../src/session/consent";
import { openSession } from "../../src/session/request";
import { sessionKey } from "../../src/session/store";
import { readPageData } from "../../src/settings";
import fixtureJson from "../fixtures/php-account.json";

const NOW = 1_764_000_000;
const STEP_SECONDS = 60;
const LIFETIME = 30 * 86_400;
const IDLE = 168 * 3_600;

interface Setup {
	readonly op: "user" | "set";
	readonly username?: string;
	readonly role?: string;
	readonly status?: string;
	readonly name?: string;
	readonly value?: string;
}

interface FixtureStep {
	readonly name: string;
	readonly jar: "none" | "a" | "b" | "c" | "d" | "e";
	readonly setup: readonly Setup[];
	readonly request: {
		readonly method: string;
		readonly path: string;
		readonly body: string | null;
	};
	readonly response: {
		readonly status: number;
		readonly headers: Readonly<Record<string, string | readonly string[]>>;
		readonly body: string;
	};
}

const fixture = fixtureJson as unknown as {
	readonly appPassword: string;
	readonly newPassword: string;
	readonly accounts: readonly { readonly username: string; readonly role: string; readonly status: string }[];
	readonly settings: Readonly<Record<string, string>>;
	readonly sources: Readonly<Record<string, string>>;
	readonly steps: readonly FixtureStep[];
};

const PASSWORD = fixture.appPassword;

/** The app's configuration, as `loadConfig()` builds it for a self-hosted install with a password. */
const APP_CONFIG: AppConfig = {
	hosted: false,
	appPassword: PASSWORD,
	publicUrl: "",
	currency: "EUR",
	timezone: "UTC",
	pageSize: 60,
	expirySoonDays: 30,
	uploads: { maxMb: 20, fullMax: 1600, thumbMax: 480 },
};

/* ------------------------------------------------------------------------------------------------
 * The state the capture was asked from.
 * ---------------------------------------------------------------------------------------------- */

let counter = 0;
const fresh = (): Sql => {
	counter += 1;
	return durableObjectSql(
		env.INSTALLATION.get(
			env.INSTALLATION.idFromName(`s1.5-account-${counter}`),
		) as unknown as InstallationStub,
	);
};

const seed = async (sql: Sql): Promise<void> => {
	await sql.batch([
		{ sql: "DELETE FROM users" },
		{ sql: "DELETE FROM roles" },
		{ sql: "DELETE FROM sessions" },
		{ sql: "DELETE FROM auth_attempts" },
		{ sql: "DELETE FROM settings" },
		{ sql: "DELETE FROM sqlite_sequence WHERE name IN ('users','roles')" },
	]);
	await sql.batch(
		Object.entries(fixture.settings).map(([name, value]) => ({
			sql: "INSERT INTO settings (name, value, updated_at) VALUES (?, ?, '2026-01-01 00:00:00')",
			params: [name, value],
		})),
	);
	await sql.batch(
		ROLE_DEFAULTS.map(([key, name, perms]) => ({
			sql: "INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?,?,?,1,'2026-01-01 00:00:00')",
			params: [key, name, permsJson(perms)],
		})),
	);
};

const addUser = async (sql: Sql, username: string, role: string, status: string): Promise<number> => {
	await sql.batch([
		{
			sql: `INSERT INTO users (username, username_key, password_hash, role, status, created_at, session_epoch)
				VALUES (?,?,?,?,?,'2026-01-01 00:00:00','')`,
			params: [username, username.trim().toLowerCase(), await hashPassword(PASSWORD), role, status],
		},
	]);
	const rows = await sql.all({ sql: "SELECT id FROM users WHERE username_key = ?", params: [username] });
	return Number(rows[0]?.id ?? 0);
};

const setSetting = async (sql: Sql, name: string, value: string): Promise<void> => {
	await sql.batch([
		{
			sql: "UPDATE settings SET value = ?, updated_at = '2026-01-01 00:00:00' WHERE name = ?",
			params: [value, name],
		},
	]);
};

/** The router's configuration, read the way `index.ts` reads it. */
const configFor = async (sql: Sql, deadlineMs = 0): Promise<RouterConfig> => {
	const data = await readPageData(sql, APP_CONFIG, "/account");
	return {
		publicUrl: "",
		https: false,
		hosted: false,
		maxUploadMb: APP_CONFIG.uploads.maxMb,
		page: data.page,
		login: { registrationOpen: data.registrationOpen, guestBrowsing: data.guestBrowsing },
		signInEnabled: data.signInEnabled,
		// v3 pads a failed sign-in (and a wrong current password) to 1000 ms; the replay sets 0 so it
		// is not seconds of waiting. One test below sets a deadline and measures it.
		signInDeadlineMs: deadlineMs,
	};
};

/* ------------------------------------------------------------------------------------------------
 * The client: five cookie jars, each with its own session and token.
 * ---------------------------------------------------------------------------------------------- */

const SESSION_VALUE = /((?:__Host-)?apothekesid)=[^;]+/;
const EXPIRES = /expires=[^;]+/i;
const normaliseCookie = (line: string): string =>
	line.replace(SESSION_VALUE, "$1=<id>").replace(EXPIRES, "expires=<date>");

class Jar {
	session: string | null = null;
	token = "";
	readonly seen: string[] = [];

	/** What a browser does with `Set-Cookie`: store, or drop on `Max-Age=0`. */
	apply(lines: readonly string[]): void {
		for (const line of lines) {
			const [pair] = line.split(";");
			const at = (pair ?? "").indexOf("=");
			if (at === -1) {
				continue;
			}
			if ((pair ?? "").slice(0, at).trim() !== "apothekesid") {
				continue;
			}
			const value = (pair ?? "").slice(at + 1).trim();
			this.session = /max-age=0/i.test(line) ? null : value;
			if (this.session !== null && !this.seen.includes(this.session)) {
				this.seen.push(this.session);
			}
		}
	}

	/** Every jar carries consent; the session cookie is added once one has been set. */
	cookie(): string {
		return this.session === null
			? `${CONSENT_COOKIE}=v1`
			: `${CONSENT_COOKIE}=v1; apothekesid=${this.session}`;
	}
}

interface Answer {
	readonly status: number;
	readonly headers: Readonly<Record<string, string | readonly string[]>>;
	readonly body: string;
}

/** The headers the fixture pins, spelled as it spells them. */
const PINNED = ["content-type", "cache-control", "location", "retry-after"] as const;

const pinnedHeaders = (
	response: Response,
	cookies: readonly string[],
): Record<string, string | readonly string[]> => {
	const headers: Record<string, string | readonly string[]> = {};
	for (const name of PINNED) {
		const value = response.headers.get(name);
		if (value !== null) {
			headers[name] = value;
		}
	}
	if (cookies.length > 0) {
		headers["set-cookie"] = cookies.map(normaliseCookie);
	}
	return headers;
};

/**
 * The body as the fixture spells it: every token `<csrf>`, and the asset build's cache key — which
 * fingerprints the built files (`assets:check` pins those), not the page. The error page v3 renders for a 419 also
 * carries the user's role classes on `<body>`; v4's error page does not (it is rendered where no actor
 * was resolved), so that one attribute is compared as empty on both sides — the sentence, the shell and
 * every byte around it are compared as sent.
 */
const ASSET_VERSION = /\?v=[0-9a-f]{8}/g;

const normaliseBody = (html: string, token: string, status: number): string => {
	const out = (token === "" ? html : html.split(token).join("<csrf>")).replace(ASSET_VERSION, "?v=<hash>");
	return status === 419 ? out.replace(/<body class="[^"]*"/, '<body class=""') : out;
};

interface Sent {
	readonly answer: Answer;
	readonly raw: string;
}

const send = async (sql: Sql, jar: Jar, step: FixtureStep, now: number, deadlineMs = 0): Promise<Sent> => {
	const body = step.request.body === null ? null : step.request.body.split("<csrf>").join(jar.token);
	const headers: Record<string, string> = { cookie: jar.cookie() };
	if (body !== null) {
		headers["content-type"] = "application/x-www-form-urlencoded";
	}
	const request = new Request(`http://localhost${step.request.path}`, {
		method: step.request.method,
		headers,
		...(body === null ? {} : { body }),
	});
	const session = await openSession({
		sql,
		request,
		now,
		secure: false,
		limits: { lifetimeSeconds: LIFETIME, idleSeconds: IDLE },
	});
	const routed = await routeRequest(request, await configFor(sql, deadlineMs), session);
	await session.flush();
	const raw = await routed.response.text();
	const cookies = session.cookieLines();
	jar.apply(cookies);
	const meta = raw.match(/name="csrf" content="([^"]*)"/);
	if (meta !== null && meta[1] !== undefined && meta[1] !== "") {
		jar.token = meta[1];
	}
	return {
		answer: {
			status: routed.response.status,
			headers: pinnedHeaders(routed.response, cookies),
			body: normaliseBody(raw, jar.token, routed.response.status),
		},
		raw,
	};
};

const byName = (name: string): FixtureStep => {
	const step = fixture.steps.find((entry) => entry.name === name);
	if (step === undefined) {
		throw new Error(`no fixture step ${name}`);
	}
	return step;
};

/* ------------------------------------------------------------------------------------------------
 * The replay.
 * ---------------------------------------------------------------------------------------------- */

describe("the /account page against v3's answers", () => {
	let sql: Sql;
	const jars: Record<"a" | "b" | "c" | "d" | "e", Jar> = {
		a: new Jar(),
		b: new Jar(),
		c: new Jar(),
		d: new Jar(),
		e: new Jar(),
	};
	let clock = NOW;
	const times = new Map<string, number>();

	const jarFor = (step: FixtureStep): Jar | null => (step.jar === "none" ? null : jars[step.jar]);

	const applySetup = async (step: FixtureStep): Promise<void> => {
		for (const op of step.setup) {
			if (op.op === "user") {
				await addUser(sql, op.username ?? "", op.role ?? "viewer", op.status ?? "active");
			} else {
				await setSetting(sql, op.name ?? "", op.value ?? "");
			}
		}
	};

	/** One step, compared with the fixture's own answer: status, pinned headers, body bytes. */
	const expectStep = async (name: string): Promise<Answer> => {
		const step = byName(name);
		await applySetup(step);
		const jar = jarFor(step);
		clock += STEP_SECONDS;
		times.set(name, clock);
		const { answer } = await send(sql, jar ?? new Jar(), step, clock);
		expect(answer.status, name).toBe(step.response.status);
		if (step.response.body !== "") {
			expect(answer.headers["content-type"], name).toBe(step.response.headers["content-type"]);
		}
		if (step.response.headers.location !== undefined) {
			expect(answer.headers.location, name).toBe(step.response.headers.location);
		}
		// Nothing this page answers may be stored, and both apps say so; they spell it differently
		// because v3's value depends on where the answer came from (PHP's session cache limiter adds
		// `no-cache`/`must-revalidate`, or `max-age=0, no-cache`, around the page's own `no-store`).
		// v4 sends `no-store` from every handler answer and the fuller `no-store, max-age=0, no-cache`
		// from the filters' refusals; the fixture records what v3 sent, and both sides must start with
		// `no-store` so neither can become cacheable without this failing.
		expect(String(step.response.headers["cache-control"]), `${name} (v3)`).toMatch(/^no-store/);
		expect(String(answer.headers["cache-control"]), name).toMatch(/^no-store/);
		if (step.response.headers["set-cookie"] !== undefined) {
			expect(answer.headers["set-cookie"], name).toEqual(step.response.headers["set-cookie"]);
		}
		const fixtureBody =
			step.response.status === 419
				? step.response.body.replace(/<body class="[^"]*"/, '<body class=""')
				: step.response.body;
		expect(answer.body, name).toBe(fixtureBody);
		return answer;
	};

	beforeAll(async () => {
		sql = fresh();
		await seed(sql);
		for (const account of fixture.accounts) {
			await addUser(sql, account.username, account.role, account.status);
		}
	});

	it("a guest is answered 303 /login by the permission filter, not by the controller's branch", async () => {
		// v3's controller has a guest branch (`AccountController.php:44-48`) that would send a guest to
		// `/settings/security` or `/`. It cannot answer these routes: `self` refuses a guest first, so
		// v3's actual answer is the permission filter's redirect — for nobody, and for the guest-browsing
		// visitor — and the fixture holds both.
		for (const name of ["guest_noSession", "guest_consent", "guest_guestBrowsing"]) {
			await expectStep(name);
			expect(byName(name).response.headers.location).toBe("/login");
		}
	});

	it("the two sessions sign in, and the viewer's page is v3's document byte for byte", async () => {
		await expectStep("login_a");
		await expectStep("login_a_post");
		await expectStep("login_b");
		await expectStep("login_b_post");
		const answer = await expectStep("viewer_page");
		expect(answer.body).toContain(
			'<body class="page-account role-viewer no-create no-edit no-delete no-move no-bulk no-lend no-import no-stocktake no-settings no-users no-restore">',
		);
		expect(answer.body).toContain("You may: view, export, labels, backup.");
		expect(answer.body).toContain('<a class="menu-item" href="/account" role="menuitem" id="menu-account">');
		expect(answer.body).not.toContain('id="menu-settings"');
	});

	it("prints the two toasts after the page", async () => {
		const pw = await expectStep("viewer_donePw");
		expect(pw.body).toContain('</main>\n<div class="toast" role="status">Password changed</div>');
		const out = await expectStep("viewer_doneOut");
		expect(out.body).toContain("Signed out on every other device");
	});

	it("re-renders the page with #acct-err for each of v3's refusals, at v3's status", async () => {
		const wrong = await expectStep("viewer_wrongCurrent");
		expect(wrong.status).toBe(403);
		expect(wrong.body).toContain('id="acct-err">The current password is not correct.</div>');
		// The current password is checked before the new one, so an empty form is the same 403.
		const empty = await expectStep("viewer_emptyAll");
		expect(empty.status).toBe(403);
		const short = await expectStep("viewer_shortNew");
		expect(short.status).toBe(422);
		expect(short.body).toContain("Use at least 8 characters for the password.");
		const long = await expectStep("viewer_longNew");
		expect(long.body).toContain("That password is too long.");
		const nul = await expectStep("viewer_nullNew");
		expect(nul.body).toContain("Passwords cannot contain a null character.");
		const mismatch = await expectStep("viewer_mismatch");
		expect(mismatch.body).toContain("The two passwords do not match.");
	});

	it("refuses both POSTs without the token at v3's 419, never the API's 403", async () => {
		for (const name of ["viewer_noCsrf", "signout_noCsrf"]) {
			const answer = await expectStep(name);
			expect(answer.status).toBe(419);
			expect(answer.body).toContain("Your session expired. Reload the page and try again.");
		}
	});

	it("sign out everywhere keeps this device and ends the other one", async () => {
		await expectStep("signout");
		// The session that asked stays signed in: the same page, byte for byte, from the same jar.
		const here = await expectStep("viewer_afterSignout");
		expect(here.status).toBe(200);
		// The other session held the old epoch, so it stopped authenticating on its next request.
		expect(jars.b.session).not.toBeNull();
		await expectStep("secondSession_afterSignout");
		expect(byName("secondSession_afterSignout").response.headers.location).toBe("/login");
	});

	it("a new password rotates the epoch, keeps the sign-in time and re-signs this session", async () => {
		const before = await sql.all(statement("SELECT session_epoch FROM users WHERE username_key = 'viewer'"));
		await expectStep("viewer_changePw");
		const after = await sql.all(statement("SELECT session_epoch FROM users WHERE username_key = 'viewer'"));
		expect(after[0]?.session_epoch).not.toBe(before[0]?.session_epoch);
		// The row that now carries the session keeps the sign-in time of the one it replaced: without
		// `keepSignIn` this would be the change's own clock, and `session_days` would start again.
		const signedInAt = times.get("login_a_post");
		const key = await sessionKey(jars.a.session ?? "");
		const row = await sql.all(statement("SELECT data FROM sessions WHERE id = ?", [key]));
		expect(String(row[0]?.data ?? "")).toContain(`signed_in_at|i:${signedInAt};`);
		const here = await expectStep("viewer_afterChange");
		expect(here.status).toBe(200);
		expect(here.body).toContain("Password changed");
		await expectStep("secondSession_afterChange");
	});

	it("the old password stops working and the new one signs in", async () => {
		await expectStep("login_oldPassword");
		const refused = await expectStep("login_oldPassword_post");
		expect(refused.status).toBe(401);
		expect(refused.body).toContain("Wrong user name or password.");
		await expectStep("login_newPassword");
		const page = await expectStep("c_page");
		expect(page.body).toContain('<b id="acct-name">viewer</b>');
	});

	it("an admin's page: no role classes and every permission", async () => {
		await expectStep("login_admin");
		await expectStep("login_admin_post");
		const admin = await expectStep("admin_page");
		expect(admin.body).toContain('<body class="page-account">');
		expect(admin.body).toContain(
			"You may: view, add items, edit, delete, move, bulk actions, lend, import, export, stocktake, labels, settings, users, backup, restore.",
		);
		expect(admin.body).toContain('id="menu-settings"');
	});

	it("the current-password check is throttled exactly as a sign-in is", async () => {
		const step = byName("lockme_rateLimited");
		await applySetup(step);
		await expectStep("login_lockme");
		await expectStep("login_lockme_post");
		await expectStep("lockme_page");
		// The ten attempts the capture made before its eleventh, replayed on the same jar.
		for (let attempt = 0; attempt < 10; attempt += 1) {
			clock += STEP_SECONDS;
			await send(sql, jars.e, { ...step, setup: [] }, clock);
		}
		clock += STEP_SECONDS;
		const sent = await send(sql, jars.e, { ...step, setup: [] }, clock);
		expect(sent.answer.status).toBe(429);
		// v4's addition on top of v3's status: the window as `Retry-After` (D21).
		expect(sent.answer.headers["retry-after"]).toBe("900");
		expect(sent.answer.body).toContain(
			'id="acct-err">Too many attempts for this account from this address – please wait 15 minutes and try again.</div>',
		);
	});

	it("the fixture holds the sources and answers it was generated from", () => {
		expect(Object.keys(fixture.sources)).toHaveLength(20);
		expect(fixture.steps).toHaveLength(35);
	});
});

/* ------------------------------------------------------------------------------------------------
 * The parts a capture cannot show.
 * ---------------------------------------------------------------------------------------------- */

describe("the refusals and the deadline a capture cannot show", () => {
	let sql: Sql;

	beforeAll(async () => {
		sql = fresh();
		await seed(sql);
		await addUser(sql, "viewer", "viewer", "active");
	});

	const ctxFor = (db: Sql): Ctx =>
		({
			request: new Request("http://localhost/account"),
			session: { sql: db, now: NOW },
			config: { signInDeadlineMs: 0 },
		}) as unknown as Ctx;

	it("answers 404 User not found. for an account that is gone", async () => {
		// `SignOutEverywhere::run()`'s own check (`SignOutEverywhere.php:28`).
		await expect(signOutEverywhere(sql, 9999)).rejects.toMatchObject({
			status: 404,
			message: "User not found.",
		});
	});

	it("answers 404 for a deletion between the password check and the write", async () => {
		// `changeOwnPassword()` re-reads the row after the check (`Accounts.php:226`); the one way to
		// reach that 404 is the row going away in between, so this SQL deletes it at the write
		// `checkCurrent()` ends with — the counter a success takes back.
		const user = await addUser(sql, "race", "viewer", "active");
		let batches = 0;
		const deleting: Sql = {
			all: (query) => sql.all(query),
			batch: async (queries) => {
				const result = await sql.batch(queries);
				batches += 1;
				// 1: `login_address`, 2: `login`, 3: the counters a success takes back — the row goes
				// between the check that just passed and the write that follows.
				if (batches === 3) {
					await sql.batch([statement("DELETE FROM users WHERE id = ?", [user])]);
				}
				return result;
			},
		};
		await expect(
			changeOwnPassword(
				ctxFor(deleting),
				{ id: user, username: "race", role: "viewer" },
				PASSWORD,
				"a-new-password",
				"a-new-password",
			),
		).rejects.toMatchObject({ status: 404, message: "User not found." });
	});

	it("answers a wrong current password at SignInPace's deadline, not at the hashing time", async () => {
		const paced = await addUser(sql, "paced", "viewer", "active");
		// `SignInPace` clamps to its 400 ms floor, so this is the shortest deadline a caller can ask
		// for; the point is that the answer waits at all rather than returning with the bcrypt.
		const ctx = {
			...(ctxFor(sql) as unknown as Record<string, unknown>),
			config: { signInDeadlineMs: 1 },
		} as unknown as Ctx;
		const started = performance.now();
		await expect(
			changeOwnPassword(
				ctx,
				{ id: paced, username: "paced", role: "viewer" },
				"wrong",
				"a-new-password",
				"a-new-password",
			),
		).rejects.toMatchObject({ status: 403, message: "The current password is not correct." });
		expect(performance.now() - started).toBeGreaterThanOrEqual(350);
	});
});
