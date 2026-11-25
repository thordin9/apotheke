// `/api/v1/session` and `/api/v1/me` (plan §5 S1.4): v3's answers, replayed against v4.
//
// The oracle is `test/fixtures/php-api.json`: 28 real HTTP answers captured from the PHP app by
// `scripts/gen-api-fixture.sh`, each with the request that produced it and the state it was asked
// from. This file does not restate any of them — it boots the same starting state, sends the same
// request through the real session load, filter chain, handler and flush, and compares status, the
// pinned headers and the body *bytes*. So the six keys of `state()`, the four of `account()`, every
// refusal's status and sentence, and the API header set are all asserted without being typed here.
//
// Three things are normalised, mirroring the writer for the same reason: the CSRF token, the session
// cookie's value, and the cookie's expiry date. Everything else is compared as sent.
//
// The steps run in the fixture's order because they are one session's life: a guest reads, a visitor
// makes every mistake, a viewer signs in and out, the admin signs in, the switches move, and the
// eleventh wrong password is refused. So the cases share one database and one cookie jar, built once
// in `beforeAll`; each `it` sends its own step and names the contract it broke.

import { env } from "cloudflare:workers";
import { beforeAll, describe, expect, it } from "vitest";
import { permsJson, ROLE_DEFAULTS } from "../../src/auth/permissions";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import type { Sql } from "../../src/db/sql";
import { type RouterConfig, routeRequest } from "../../src/http/router";
import { ROUTES } from "../../src/http/routes.generated";
import type { AppConfig } from "../../src/platform/config";
import { hashPassword } from "../../src/platform/passwords";
import { CONSENT_COOKIE } from "../../src/session/consent";
import { openSession } from "../../src/session/request";
import { DEFAULT_MS, deadlineMs, MAX_MS, MIN_MS, padSignIn } from "../../src/session/sign-in-pace";
import { readPageData } from "../../src/settings";
import fixtureJson from "../fixtures/php-api.json";
import { SLOW_TEST_MS } from "../helpers/deadline";

const NOW = 1_764_000_000;
const LIFETIME = 30 * 86_400;
const IDLE = 168 * 3_600;

/* ------------------------------------------------------------------------------------------------
 * JSON Schema, the subset docs/api/openapi.yaml uses for these four answers.
 * ---------------------------------------------------------------------------------------------- */

interface JsonSchema {
	readonly type?: string;
	readonly nullable?: boolean;
	readonly required?: readonly string[];
	readonly additionalProperties?: boolean | JsonSchema;
	readonly properties?: Readonly<Record<string, JsonSchema>>;
	readonly items?: JsonSchema;
	readonly enum?: readonly unknown[];
	readonly pattern?: string;
	readonly minLength?: number;
}

interface FixtureStep {
	readonly name: string;
	readonly uses: "none" | "consent" | "session";
	readonly setup: readonly (
		| { readonly op: "user"; readonly username: string; readonly role: string; readonly status: string }
		| { readonly op: "set"; readonly name: string; readonly value: string }
	)[];
	readonly request: {
		readonly method: string;
		readonly path: string;
		readonly body: string | null;
		readonly contentType: string | null;
		readonly csrf: boolean;
		readonly origin: "own" | "evil" | null;
	};
	readonly response: {
		readonly status: number;
		readonly headers: Readonly<Record<string, string | readonly string[]>>;
		readonly body: string;
	};
}

const fixture = fixtureJson as unknown as {
	readonly appPassword: string;
	readonly settings: Readonly<Record<string, string>>;
	readonly pacing: {
		readonly DEFAULT_MS: number;
		readonly MIN_MS: number;
		readonly MAX_MS: number;
		readonly clamped: Readonly<Record<string, number>>;
	};
	readonly contract: {
		readonly schemas: Readonly<Record<string, JsonSchema>>;
		readonly parameters: Readonly<Record<string, JsonSchema>>;
	};
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
 * JSON Schema, the subset `docs/api/openapi.yaml` uses for these four answers.
 * ---------------------------------------------------------------------------------------------- */

/** A field-by-field reading of a schema; returns one line per problem, so a failure says which. */
const validate = (value: unknown, schema: JsonSchema, path: string, problems: string[]): void => {
	if (value === null) {
		if (schema.nullable === true || schema.type === undefined) {
			return;
		}
		problems.push(`${path}: null, but the schema wants ${schema.type}`);
		return;
	}
	if (schema.enum !== undefined && !schema.enum.includes(value)) {
		problems.push(`${path}: ${JSON.stringify(value)} is not one of ${JSON.stringify(schema.enum)}`);
	}
	switch (schema.type) {
		case "object": {
			if (typeof value !== "object" || Array.isArray(value)) {
				problems.push(`${path}: not an object`);
				return;
			}
			const record = value as Record<string, unknown>;
			for (const key of schema.required ?? []) {
				if (!(key in record)) {
					problems.push(`${path}: missing required "${key}"`);
				}
			}
			for (const [key, child] of Object.entries(record)) {
				const declared = schema.properties?.[key];
				if (declared === undefined) {
					if (schema.additionalProperties === false) {
						problems.push(`${path}: unexpected "${key}"`);
					}
					continue;
				}
				validate(child, declared, `${path}.${key}`, problems);
			}
			return;
		}
		case "array": {
			if (!Array.isArray(value)) {
				problems.push(`${path}: not an array`);
				return;
			}
			if (schema.items !== undefined) {
				const items = schema.items;
				for (const [index, item] of value.entries()) {
					validate(item, items, `${path}[${index}]`, problems);
				}
			}
			return;
		}
		case "string": {
			if (typeof value !== "string") {
				problems.push(`${path}: not a string`);
				return;
			}
			if (schema.pattern !== undefined && !new RegExp(schema.pattern, "u").test(value)) {
				problems.push(`${path}: ${JSON.stringify(value)} does not match ${schema.pattern}`);
			}
			if (schema.minLength !== undefined && value.length < schema.minLength) {
				problems.push(`${path}: shorter than ${schema.minLength}`);
			}
			return;
		}
		case "integer": {
			if (typeof value !== "number" || !Number.isInteger(value)) {
				problems.push(`${path}: not an integer`);
			}
			return;
		}
		case "number": {
			if (typeof value !== "number") {
				problems.push(`${path}: not a number`);
			}
			return;
		}
		case "boolean": {
			if (typeof value !== "boolean") {
				problems.push(`${path}: not a boolean`);
			}
			return;
		}
		default:
			return; // no type: a `$ref`, which this validator resolves for `permissions` only
	}
};

/**
 * The document's own schema, with its one `$ref` resolved: `Session.permissions` and
 * `Me.permissions` point at `components.schemas.Permissions`.
 */
const conformsTo = (value: unknown, name: "Session" | "Me" | "Problem"): string[] => {
	const schema = { ...(fixture.contract.schemas[name] as JsonSchema) };
	if (name !== "Problem") {
		schema.properties = {
			...(schema.properties ?? {}),
			permissions: fixture.contract.schemas.Permissions as JsonSchema,
		};
	}
	const problems: string[] = [];
	validate(value, schema, name, problems);
	return problems;
};

/* ------------------------------------------------------------------------------------------------
 * The replay: one database, one cookie jar, one session's life.
 * ---------------------------------------------------------------------------------------------- */

let counter = 0;
const fresh = (): Sql => {
	counter += 1;
	return durableObjectSql(
		env.INSTALLATION.get(env.INSTALLATION.idFromName(`s1.4-api-${counter}`)) as unknown as InstallationStub,
	);
};

/** The schema's own tables, emptied, with the fixture's settings and the built-in roles. */
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

/** An account with v3's own column shape; the fixture's `user` op. */
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

/** `SettingsService`'s one-row write, which is what the fixture's `set` op did over SQL. */
const setSetting = async (sql: Sql, name: string, value: string): Promise<void> => {
	await sql.batch([
		{
			sql: "UPDATE settings SET value = ?, updated_at = '2026-01-01 00:00:00' WHERE name = ?",
			params: [value, name],
		},
	]);
};

/** The router's configuration, read the way `index.ts` reads it: one settings query, every time. */
const configFor = async (sql: Sql): Promise<RouterConfig> => {
	const data = await readPageData(sql, APP_CONFIG, "/api/v1/session");
	return {
		publicUrl: "",
		https: false,
		hosted: false,
		maxUploadMb: APP_CONFIG.uploads.maxMb,
		page: data.page,
		login: { registrationOpen: data.registrationOpen, guestBrowsing: data.guestBrowsing },
		signInEnabled: data.signInEnabled,
		// v3 pads a failed sign-in to 1000 ms; this replay sets 0 so it is not seconds of waiting. The
		// deadline itself is asserted against the fixture at the end of the file.
		signInDeadlineMs: 0,
	};
};

interface Answer {
	readonly status: number;
	readonly headers: Readonly<Record<string, string | readonly string[]>>;
	readonly body: string;
}

/** The headers the fixture pins, spelled as it spells them. */
const PINNED = [
	"content-type",
	"cache-control",
	"retry-after",
	"content-security-policy",
	"x-content-type-options",
	"referrer-policy",
	"cross-origin-resource-policy",
	"cross-origin-opener-policy",
	"strict-transport-security",
] as const;

const SESSION_VALUE = /((?:__Host-)?apothekesid)=[^;]+/;
const EXPIRES = /expires=[^;]+/i;

const normaliseCookie = (line: string): string =>
	line.replace(SESSION_VALUE, "$1=<id>").replace(EXPIRES, "expires=<date>");

/** The same three substitutions the fixture writer made, and nothing more. */
const normaliseBody = (body: string): string =>
	body.replace(/"csrf_token":"([0-9a-f]{64})"/, '"csrf_token":"<csrf>"');

const pinned = (response: Response, body: string): Answer => {
	const headers: Record<string, string | readonly string[]> = {};
	for (const name of PINNED) {
		const value = response.headers.get(name);
		if (value !== null) {
			headers[name] = value;
		}
	}
	const cookies = response.headers.getSetCookie().map(normaliseCookie);
	const sorted = Object.fromEntries(Object.entries(headers).sort(([a], [b]) => a.localeCompare(b)));
	return {
		status: response.status,
		headers: cookies.length === 0 ? sorted : { ...sorted, "set-cookie": cookies },
		body: normaliseBody(body),
	};
};

const safeJson = (text: string): Record<string, unknown> | null => {
	try {
		const value = JSON.parse(text);
		return value !== null && typeof value === "object" ? (value as Record<string, unknown>) : null;
	} catch {
		return null;
	}
};

/** A client's cookie jar: consent plus at most one session, updated from every answer. */
class Jar {
	session: string | null = null;

	/** The `Cookie` header for a step's `uses`. */
	header(uses: FixtureStep["uses"]): string | null {
		if (uses === "none") {
			return null;
		}
		const parts = [`${CONSENT_COOKIE}=v1`];
		if (uses === "session" && this.session !== null) {
			parts.push(`apothekesid=${this.session}`);
		}
		return parts.join("; ");
	}

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
			this.session = /max-age=0/i.test(line) ? null : (pair ?? "").slice(at + 1).trim();
		}
	}
}

/** The token a client keeps from the last state answer. */
let token = "";

const headersFor = (jar: Jar, step: FixtureStep): Record<string, string> => {
	const headers: Record<string, string> = {};
	const cookie = jar.header(step.uses);
	if (cookie !== null) {
		headers.cookie = cookie;
	}
	if (step.request.contentType !== null) {
		headers["content-type"] = step.request.contentType;
	}
	if (step.request.origin === "own") {
		headers.origin = "http://localhost";
	} else if (step.request.origin === "evil") {
		headers.origin = "https://evil.example";
	}
	if (step.request.csrf) {
		headers["x-csrf-token"] = token;
	}
	return headers;
};

/**
 * One request through the real session load, chain, handler and flush — then the cookie lines, the
 * way `index.ts` attaches them. A client reads the new token from a state answer and sends it next.
 */
interface Sent {
	/** The answer as the fixture records it: token and cookie value normalised. */
	readonly answer: Answer;
	/** The bytes as sent, for the OpenAPI check: the contract's pattern describes a real token. */
	readonly raw: string;
}

const send = async (sql: Sql, jar: Jar, step: FixtureStep): Promise<Sent> => {
	const request = new Request(`http://localhost${step.request.path}`, {
		method: step.request.method,
		headers: headersFor(jar, step),
		...(step.request.body === null ? {} : { body: step.request.body }),
	});
	const session = await openSession({
		sql,
		request,
		now: NOW,
		secure: false,
		limits: { lifetimeSeconds: LIFETIME, idleSeconds: IDLE },
	});
	const routed = await routeRequest(request, await configFor(sql), session);
	await session.flush();
	const body = await routed.response.text();
	const headers = new Headers(routed.response.headers);
	for (const line of session.cookieLines()) {
		headers.append("set-cookie", line);
	}
	jar.apply(headers.getSetCookie());
	const parsed = safeJson(body);
	if (typeof parsed?.csrf_token === "string" && parsed.csrf_token !== "") {
		token = parsed.csrf_token;
	}
	return {
		answer: pinned(new Response(body, { status: routed.response.status, headers }), body),
		raw: body,
	};
};

const byName = (name: string): FixtureStep => {
	const step = fixture.steps.find((entry) => entry.name === name);
	if (step === undefined) {
		throw new Error(`no fixture step ${name}`);
	}
	return step;
};

describe("the four /api/v1 session routes against v3's answers", () => {
	let sql: Sql;
	let jar: Jar;

	/** What the capture changed before that answer: an account, or a settings row. */
	const applySetup = async (step: FixtureStep): Promise<void> => {
		for (const op of step.setup) {
			if (op.op === "user") {
				await addUser(sql, op.username, op.role, op.status);
			} else {
				await setSetting(sql, op.name, op.value);
			}
		}
	};

	/** The step, replayed; asserted against the fixture's own answer, status/headers/bytes. */
	const expectStep = async (name: string): Promise<Answer> => {
		const step = byName(name);
		await applySetup(step);
		const { answer, raw } = await send(sql, jar, step);
		expect(answer, name).toEqual(step.response);
		const parsed = safeJson(raw);
		if (step.response.status === 200) {
			const schema = step.request.path === "/api/v1/me" ? "Me" : "Session";
			expect(conformsTo(parsed, schema), `${name} (${schema})`).toEqual([]);
		} else if (parsed !== null) {
			expect(conformsTo(parsed, "Problem"), `${name} (Problem)`).toEqual([]);
		}
		return answer;
	};

	beforeAll(async () => {
		sql = fresh();
		await seed(sql);
		// The capture's starting point: the account the seed created from APP_PASSWORD, with id 1.
		expect(await addUser(sql, "admin", "admin", "active")).toBe(1);
		jar = new Jar();
		token = "";
	});

	/**
	 * The steps below run in the fixture's order, against the one state it built. Each `it` is one
	 * answer, so a failure names the contract; they share the jar because the fixture does.
	 */

	it("session_noConsent: starts nothing without consent, and answers an empty token", async () => {
		const answer = await expectStep("session_noConsent");
		expect(jar.session).toBeNull();
		expect(safeJson(answer.body)?.csrf_token).toBe("");
	});

	it("session_guest: starts a session for the token", async () => {
		await expectStep("session_guest");
		expect(jar.session).not.toBeNull();
		expect(token).toMatch(/^[0-9a-f]{64}$/);
	});

	it("me_guest: refuses nobody with v3's 401", async () => {
		const answer = await expectStep("me_guest");
		expect(JSON.parse(answer.body).detail).toBe("Sign in first.");
	});

	it("session_invalidJson: 400 bad_request for a truncated body", async () => {
		const answer = await expectStep("session_invalidJson");
		expect(JSON.parse(answer.body)).toEqual({
			type: "about:blank",
			title: "Bad Request",
			status: 400,
			detail: "The body is not valid JSON.",
			code: "bad_request",
		});
	});

	it("session_emptyObject: 422 with both fields Required", async () => {
		const answer = await expectStep("session_emptyObject");
		expect(JSON.parse(answer.body)).toEqual({
			type: "about:blank",
			title: "Unprocessable Content",
			status: 422,
			detail: "Enter a user name and password.",
			code: "validation.failed",
			errors: { username: "Required.", password: "Required." },
		});
	});

	it("session_notText: 422 Must be text. for a non-string, Required. for null", async () => {
		const answer = await expectStep("session_notText");
		expect(JSON.parse(answer.body).errors).toEqual({ username: "Must be text.", password: "Required." });
	});

	it("session_missingPassword: 422 names only the field that is missing", async () => {
		const answer = await expectStep("session_missingPassword");
		expect(JSON.parse(answer.body).errors).toEqual({ password: "Required." });
	});

	it("session_emptyBody: an empty body is checked like {}", async () => {
		const answer = await expectStep("session_emptyBody");
		expect(JSON.parse(answer.body).errors).toEqual({ username: "Required.", password: "Required." });
	});

	it("session_noToken: the API's CSRF rule refuses a write without the token", async () => {
		const answer = await expectStep("session_noToken");
		expect(JSON.parse(answer.body).code).toBe("csrf.invalid");
	});

	it("session_wrongOrigin: a foreign Origin is refused before the handler", async () => {
		const answer = await expectStep("session_wrongOrigin");
		expect(JSON.parse(answer.body).detail).toBe("The request was refused: reload and try again.");
	});

	it("session_textPlain: 415 names the body types the API takes", async () => {
		const answer = await expectStep("session_textPlain");
		expect(JSON.parse(answer.body).detail).toBe(
			"Send application/json, or multipart/form-data for a file upload.",
		);
	});

	it("session_unknownUser: 401 with one sentence for an unknown name", async () => {
		const answer = await expectStep("session_unknownUser");
		expect(JSON.parse(answer.body).detail).toBe("Wrong user name or password.");
	});

	it("session_wrongPassword: the same sentence for a wrong password", async () => {
		const answer = await expectStep("session_wrongPassword");
		expect(JSON.parse(answer.body)).toEqual({
			type: "about:blank",
			title: "Unauthorized",
			status: 401,
			detail: "Wrong user name or password.",
			code: "auth.invalid_credentials",
		});
	});

	it("session_pending: 403 auth.pending_approval for an account awaiting an admin", async () => {
		const answer = await expectStep("session_pending");
		expect(JSON.parse(answer.body).detail).toBe("Your account is waiting for an admin’s approval.");
	});

	it("session_guestBrowsing: a guest browsing visitor holds a permission", async () => {
		const answer = await expectStep("session_guestBrowsing");
		expect(JSON.parse(answer.body).permissions).toEqual(["view"]);
	});

	it("me_guestBrowsing: and `me` is still 401 for it", async () => {
		const answer = await expectStep("me_guestBrowsing");
		expect(JSON.parse(answer.body).code).toBe("auth.required");
	});

	it("session_signInViewer: signs in, with the viewer's own permission list", async () => {
		const answer = await expectStep("session_signInViewer");
		const body = JSON.parse(answer.body);
		expect(body.signed_in).toBe(true);
		expect(body.user).toEqual({ id: 3, username: "viewer", role: "viewer" });
		expect(body.permissions).toEqual(["view", "export", "labels", "backup"]);
	});

	it("me_viewer: the account's four keys, and no csrf_token", async () => {
		const answer = await expectStep("me_viewer");
		expect(Object.keys(JSON.parse(answer.body))).toEqual(["id", "username", "role", "permissions"]);
	});

	it("session_deleteViewer: 204 with no body and a Set-Cookie that expires the session", async () => {
		const answer = await expectStep("session_deleteViewer");
		expect(answer.status).toBe(204);
		expect(answer.body).toBe("");
		expect(answer.headers["content-type"]).toBeUndefined();
		expect(jar.session).toBeNull();
	});

	it("session_afterDelete: the next read starts a fresh session, signed out", async () => {
		const answer = await expectStep("session_afterDelete");
		expect(JSON.parse(answer.body).signed_in).toBe(false);
		expect(jar.session).not.toBeNull();
	});

	it("session_signIn: the admin's state, six keys in PHP's order", async () => {
		const answer = await expectStep("session_signIn");
		const body = JSON.parse(answer.body);
		expect(Object.keys(body)).toEqual([
			"signed_in",
			"sign_in_enabled",
			"registration_open",
			"user",
			"permissions",
			"csrf_token",
		]);
		expect(body.user).toEqual({ id: 1, username: "admin", role: "admin" });
		expect(body.permissions).toHaveLength(15);
	});

	it("me_signedIn: the admin's account", async () => {
		const answer = await expectStep("me_signedIn");
		expect(JSON.parse(answer.body).username).toBe("admin");
	});

	it("session_registrationOpen: the switch reaches the answer", async () => {
		const answer = await expectStep("session_registrationOpen");
		expect(JSON.parse(answer.body).registration_open).toBe(true);
	});

	it("session_signInOff: everyone is the anonymous admin, and nobody is signed in", async () => {
		const answer = await expectStep("session_signInOff");
		const body = JSON.parse(answer.body);
		expect(body.signed_in).toBe(false);
		expect(body.sign_in_enabled).toBe(false);
		expect(body.user).toBeNull();
		expect(body.permissions).toHaveLength(15);
	});

	it("me_signInOff: the anonymous admin is refused 403 permission.denied", async () => {
		const answer = await expectStep("me_signInOff");
		expect(JSON.parse(answer.body)).toEqual({
			type: "about:blank",
			title: "Forbidden",
			status: 403,
			detail: "Your role (Admin) does not allow this. Ask an admin.",
			code: "permission.denied",
		});
	});

	it("session_signInOffPost: 409 auth.sign_in_off", async () => {
		const answer = await expectStep("session_signInOffPost");
		expect(JSON.parse(answer.body).detail).toBe("Sign-in is turned off on this server.");
	});

	// D34: eleven sign-in attempts, each a bcrypt verify and a deadline wait — the class the 5 s
	// default has killed four times on CI. Here it timed out at 5078 ms; see helpers/deadline.ts.
	it(
		"session_rateLimited: the eleventh wrong attempt is 429 with RateLimiter's window",
		async () => {
			// The captured answer is the eleventh: sign-in is back on (the step's own setup), then the ten
			// attempts the fixture made before it.
			const step = byName("session_rateLimited");
			await applySetup(step);
			for (let attempt = 0; attempt < 10; attempt += 1) {
				await send(sql, jar, { ...step, setup: [] });
			}
			const answer = await expectStep("session_rateLimited");
			expect(answer.status).toBe(429);
			expect(answer.headers["retry-after"]).toBe("900");
			expect(JSON.parse(answer.body).detail).toBe(
				"Too many attempts for this account from this address – please wait 15 minutes and try again.",
			);
		},
		SLOW_TEST_MS,
	);

	it("session_delete: 204, and the session is gone", async () => {
		const answer = await expectStep("session_delete");
		expect(answer.status).toBe(204);
		expect(jar.session).toBeNull();
	});
});

describe("the sign-in deadline against v3's", () => {
	it("uses SignInPace's constants", () => {
		expect({ DEFAULT_MS, MIN_MS, MAX_MS }).toEqual({
			DEFAULT_MS: fixture.pacing.DEFAULT_MS,
			MIN_MS: fixture.pacing.MIN_MS,
			MAX_MS: fixture.pacing.MAX_MS,
		});
	});

	it("clamps a configured deadline exactly as v3 does", () => {
		for (const [configured, expected] of Object.entries(fixture.pacing.clamped)) {
			expect(deadlineMs(Number(configured)), configured).toBe(expected);
		}
	});

	it("holds a failed answer to the deadline, and does not wait when it has passed", async () => {
		const started = performance.now();
		const waited = await padSignIn(started, 60);
		expect(waited).toBeGreaterThan(0);
		expect(performance.now() - started).toBeGreaterThanOrEqual(50);
		expect(await padSignIn(performance.now() - 5000, 60)).toBe(0);
	});
});

describe("nothing else under /api starts answering 2xx", () => {
	it("leaves every other /api route refused", async () => {
		const own = new Set(["api/v1/session", "api/v1/me"]);
		const sql = fresh();
		await seed(sql);
		const config = await configFor(sql);
		const answered: string[] = [];
		for (const route of ROUTES) {
			if (!route.path.startsWith("api/") || own.has(route.path)) {
				continue;
			}
			if (route.method === "CONNECT" || route.method === "TRACE") {
				continue; // not constructable as a Request, and not a method the app declares
			}
			if (route.method === "OPTIONS") {
				// `ApiFilter::before()` answers /api's CORS preflight with 204 ahead of the chain, for
				// every path under /api. It is a filter answer, not a handler answering for a route.
				continue;
			}
			const path = `/${route.path
				.split("/")
				.map((segment) => (segment.startsWith("(") ? "x" : segment))
				.join("/")}`;
			const response = await routeRequest(
				new Request(`http://localhost${path}`, {
					method: route.method,
					headers: { cookie: `${CONSENT_COOKIE}=v1` },
				}),
				config,
			);
			if (response.response.status >= 200 && response.response.status < 300) {
				answered.push(`${route.method} ${path} → ${response.response.status}`);
			}
		}
		expect(answered).toEqual([]);
	});
});
