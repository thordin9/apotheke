// Readiness (plan §5 S0.3): the gate that makes "seed pending" impossible to serve through, and the
// one probe that must keep answering whatever the database is doing (§11 rule 7).

import { SELF } from "cloudflare:test";
import { env } from "cloudflare:workers";
import { describe, expect, it, vi } from "vitest";
import { durableObjectSql, type InstallationStub } from "../src/db/do-sql";
import { runMigrations, seedState } from "../src/db/migration-runner";
import { type Sql, statement } from "../src/db/sql";
import { PAGE_HEADERS } from "../src/http/problem";
import {
	handlerFailure,
	logServerError,
	plainErrorPage,
	SERVER_ERROR,
	serverError,
} from "../src/http/server-error";
import { loadConfig } from "../src/platform/config";
import { notReadyLogLine, prepareInstallation, readinessResponse } from "../src/readiness";
import { SLOW_TEST_MS } from "./helpers/deadline";
import { TEST_PAGE } from "./helpers/page";
import { resetSchema } from "./helpers/schema";

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

const config = loadConfig({ APP_PASSWORD: "correct horse battery staple 2026", TZ: "UTC" });

/** A driver that throws: what `database` catches, and what `/readyz` must not quote (D159). */
const brokenSql = (message: string): Sql => {
	const fail = async (): Promise<never> => {
		throw new Error(message);
	};

	return { all: fail, batch: fail };
};

describe("/readyz and /healthz", () => {
	it("/readyz reports the schema and a completed seed once boot has run", async () => {
		const res = await SELF.fetch("http://apotheke-v4.test/readyz");
		expect(res.status).toBe(200);
		// Through the Worker, not the helper: a proxy must not keep a ready answer (D156).
		expect(res.headers.get("cache-control")).toBe("no-store");
		expect(await res.json()).toEqual({ ok: true, schema: "0036", seed: "done", driver: "sqlite" });
	});

	it("/healthz keeps answering without touching the database", async () => {
		const res = await SELF.fetch("http://apotheke-v4.test/healthz");
		expect(res.status).toBe(200);
		expect(await res.json()).toEqual({ ok: true, driver: "sqlite" });
	});

	it("still 404s other paths, with the app's error page, once the installation is ready", async () => {
		// `localhost` on purpose: `hostcheck` (S0.5) answers 421 for a Host that is neither a local
		// name, an IP literal, nor PUBLIC_URL's host — and the harness host is not PUBLIC_URL's.
		const res = await SELF.fetch("http://localhost/nope");
		expect(res.status).toBe(404);
		expect(res.headers.get("content-type")).toBe("text/html; charset=UTF-8");
	});
});

describe("the readiness answer", () => {
	// A Durable Object runs its own boot the first time a test touches it, so each case gets a
	// name of its own and wipes it afterwards: the state under test is the *next* boot's question.
	const fresh = async (name: string): Promise<Sql> => {
		const sql = sqlite(name);
		await resetSchema(sql);
		return sql;
	};

	it(
		"is 200 with a reason to be, and 503 problem+json when the seed refuses",
		async () => {
			const ready = await prepareInstallation(await fresh("readiness"), config, "2026-11-23 12:00:00");
			expect(ready).toEqual({ ok: true, schema: "0036", seed: "done" });
			expect(readinessResponse(ready, "sqlite").status).toBe(200);

			const refused = await prepareInstallation(
				await fresh("readiness-refused"),
				loadConfig({ APP_PASSWORD: "short" }),
				"2026-11-23 12:00:00",
			);
			expect(refused).toMatchObject({ ok: false, code: "seed.refused" });

			const res = readinessResponse(refused, "sqlite");
			expect(res.status).toBe(503);
			expect(res.headers.get("content-type")).toContain("application/problem+json");
			expect(await res.json()).toMatchObject({ status: 503, title: "seed.refused" });
			// Two fresh installations, each the 36-migration chain plus the seed: 5069 ms on CI's
			// runner (run 45578984620 attempt 1, against the default 5 s), 1709 ms here.
		},
		SLOW_TEST_MS,
	);

	it("refuses a database newer than the code, never downgrades it", async () => {
		const sql = await fresh("readiness-newer");
		await sql.batch([
			statement(
				"CREATE TABLE schema_migrations (version TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)",
			),
		]);
		await sql.batch([
			statement(
				"INSERT INTO schema_migrations (version, name, applied_at) VALUES ('9999','from_the_future','2026-11-23 10:00:00')",
			),
		]);

		const result = await prepareInstallation(sql, config, "2026-11-23 12:00:00");
		expect(result).toMatchObject({ ok: false, code: "schema.newer" });
	});

	it("only says done after the stamp is written", async () => {
		const sql = await fresh("readiness-pending");
		// Migrations only: that is a real "pending" install, and the state the gate reads.
		await runMigrations(sql, { now: "2026-11-23 12:00:00" });
		expect(await seedState(sql)).toBe("pending");

		expect(await prepareInstallation(sql, loadConfig({ APP_PASSWORD: "" }), "2026-11-23 12:00:00")).toEqual({
			ok: true,
			schema: "0036",
			seed: "done",
		});
		expect(await seedState(sql)).toBe("done");
	});

	it("answers a caught driver failure with one fixed sentence, never its text (D159)", async () => {
		// `no such table: settings` is a driver's own sentence; on a real outage the same field can
		// carry a DSN, a host or a SQL fragment, and `/readyz` answers before the router to an
		// unauthenticated caller (`src/index.ts:81-83`).
		const ready = await prepareInstallation(
			brokenSql("no such table: settings"),
			config,
			"2026-11-23 12:00:00",
		);
		expect(ready).toEqual({ ok: false, code: "database", detail: SERVER_ERROR });

		const res = readinessResponse(ready, "sqlite");
		expect(res.status).toBe(503);
		expect(res.headers.get("content-type")).toBe("application/problem+json; charset=utf-8");
		// A failure answer is never cached: v3's `PlainHandler` sent `no-store` on every answer it
		// rendered, and v4's boot, readiness and session-flush failures all come through here (D136).
		expect(res.headers.get("cache-control")).toBe("no-store");
		// The whole body, because "does not contain the message" passes on an empty body too.
		expect(await res.text()).toBe(
			'{"type":"about:blank","title":"database","status":503,"detail":"Something went wrong on the server."}',
		);
	});

	it("keeps the three details v4 writes itself, because those are sentences, not a driver's (D159)", async () => {
		const res = readinessResponse(
			{ ok: false, code: "seed.pending", detail: "The seed phase has not completed (state: pending)." },
			"sqlite",
		);
		expect(await res.text()).toBe(
			'{"type":"about:blank","title":"seed.pending","status":503,"detail":"The seed phase has not completed (state: pending)."}',
		);
	});

	it("sends the driver's text to the log, where the body cannot carry it (D161)", async () => {
		const lines: string[] = [];
		const spy = vi.spyOn(console, "error").mockImplementation((line: unknown) => {
			lines.push(String(line));
		});
		try {
			await prepareInstallation(brokenSql("no such table: settings"), config, "2026-11-23 12:00:00");
		} finally {
			spy.mockRestore();
		}
		expect(lines).toEqual(["[apotheke] 5xx readiness: Error: no such table: settings"]);
	});

	it("builds the not-ready line from the result, so a branch no test can reach is still asserted (D162)", () => {
		// The sentence an operator greps for, spelled out rather than read back off the code.
		expect(
			notReadyLogLine({
				ok: false,
				code: "seed.pending",
				detail: "The seed phase has not completed (state: pending).",
			}),
		).toBe("[apotheke] 5xx readiness: seed.pending: The seed phase has not completed (state: pending).");
		// The catch-all: v4's own sentence, and the driver's text is not in it — that went to the catch's
		// own log line (D161).
		expect(notReadyLogLine({ ok: false, code: "database", detail: SERVER_ERROR })).toBe(
			"[apotheke] 5xx readiness: database: Something went wrong on the server.",
		);
	});
});

// A 5xx (plan §5 S1.7, D151): v3 states the rule twice — `BootError`, "one public sentence, the detail
// only in the error log" (modules/Core/Boot/BootError.php:7), and `PlainHandler`, "a 5xx detail is
// generic, never the exception's text", its `problem()` answering `server_error` with "Something went
// wrong on the server." (modules/Core/Exceptions/PlainHandler.php:23, :133, :163). The path decides the
// body, the four page headers are set by hand because no after-filter runs (SEC-N8), and which page a
// site may draw is decided by what it has in hand (D157): only `index.ts`'s handler catch has the
// settings row, because it is read at `index.ts:99` — every earlier site answers the plain page.
describe("a 5xx answer (D151)", () => {
	const PLAIN = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head><body><p>${SERVER_ERROR}</p></body></html>`;

	it("answers a page path before the settings read with the plain page and the four headers", async () => {
		const res = serverError("/login", false);
		expect(res.status).toBe(503);
		expect(res.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(res.headers.get("cache-control")).toBe("no-store");
		// By hand, because `securityheaders` never runs for this answer: the same four the filter sets.
		for (const [name, value] of Object.entries(PAGE_HEADERS)) {
			expect(res.headers.get(name), name).toBe(value);
		}
		// Absence is asserted by name, presence by membership (D167): a loop over the constant proves
		// only its members, so a fifth header added there would be asserted rather than caught. v3
		// ends its HSTS method the other way round — `assertFalse($page->hasHeader(...), "pages are unchanged")`.
		expect(res.headers.get("strict-transport-security")).toBeNull();
		expect(await res.text()).toBe(PLAIN);
	});

	it("escapes the sentence it is given, even though the constant carries none", () => {
		expect(plainErrorPage("<x> & y")).toBe(
			'<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head><body><p>&lt;x&gt; &amp; y</p></body></html>',
		);
	});

	it("draws the app's own page where the settings row is in hand, and nowhere else", async () => {
		const res = serverError("/login", false, TEST_PAGE);
		expect(res.status).toBe(503);
		expect(res.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		const body = await res.text();
		// The shell (views.ts:104), not the fallback: its own title, and the sentence inside its body.
		expect(body).toContain("<title>Error \u00b7 Apotheke</title>");
		expect(body).toContain(`<div class="empty">`);
		expect(body).toContain(`<p>${SERVER_ERROR}</p>`);
		expect(body).toContain('<a class="btn btn-primary" href="/">Back to catalog</a>');
		expect(body).not.toBe(PLAIN);
	});

	it("answers /api with RFC 9457 server_error, and HSTS only over https", async () => {
		const res = serverError("/api/v1/medicines", false, TEST_PAGE);
		expect(res.status).toBe(503);
		expect(res.headers.get("content-type")).toBe("application/problem+json");
		expect(res.headers.get("cache-control")).toBe("no-store");
		expect(res.headers.get("strict-transport-security")).toBeNull();
		// `/api` wins over the page settings: passing them cannot make an API answer an HTML page.
		expect(await res.text()).toBe(
			'{"type":"about:blank","title":"Service Unavailable","status":503,"detail":"Something went wrong on the server.","code":"server_error"}',
		);
		expect(serverError("/api/v1/medicines", true).headers.get("strict-transport-security")).toBe(
			"max-age=31536000",
		);
	});

	it("logs the class always, and the text only where a site passes one (D161)", () => {
		const lines: string[] = [];
		const spy = vi.spyOn(console, "error").mockImplementation((line: unknown) => {
			lines.push(String(line));
		});
		try {
			logServerError("boot", new TypeError("connect ECONNREFUSED postgres://apotheke:hunter2@db:5432"));
			logServerError("handler", "not an Error");
			// The site that caught a driver passes its text, and only there: the body belongs to the
			// caller, the log to the operator (D161).
			logServerError("readiness", new Error("no such table: settings"), "no such table: settings");
		} finally {
			spy.mockRestore();
		}
		expect(lines).toEqual([
			"[apotheke] 5xx boot: TypeError",
			"[apotheke] 5xx handler: string",
			"[apotheke] 5xx readiness: Error: no such table: settings",
		]);
	});

	it("logs the handler's 500 as class and message, with the row detail stripped (D166)", async () => {
		// `PlainHandler::beforeAnswer`'s second line (`modules/Core/Exceptions/PlainHandler.php:96-97`)
		// is the class AND the message, through `strip()`; the first line (a cleanup failure, class
		// alone, because its message can hold a path) waits for the upload cleanup it belongs to (P2).
		const lines: string[] = [];
		const spy = vi.spyOn(console, "error").mockImplementation((line: unknown) => {
			lines.push(String(line));
		});
		let res: Response;
		try {
			res = handlerFailure(
				"/medicines",
				false,
				TEST_PAGE,
				new Error(
					'pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint "k"\n' +
						"DETAIL:  Key (name)=(placeholder-secret) already exists.\n" +
						"in SYSTEMPATH/Database/Postgre/Connection.php on line 210.\n#0 trace",
				),
			);
		} finally {
			spy.mockRestore();
		}
		expect(lines).toEqual([
			'[apotheke] 5xx handler: Error: pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint "k"\nDETAIL: (removed)\nin SYSTEMPATH/Database/Postgre/Connection.php on line 210.\n#0 trace',
		]);
		expect(lines[0]).not.toContain("placeholder-secret");
		// The one site with the settings row draws the app's own page, never the fallback (D157).
		expect(res.status).toBe(503);
		expect(res.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(await res.text()).toContain(`<p>${SERVER_ERROR}</p>`);
	});

	it("answers /readyz's 200 no-store too, so a cache cannot outlive the outage (D156)", async () => {
		const res = readinessResponse({ ok: true, schema: "0036", seed: "done" }, "sqlite");
		expect(res.status).toBe(200);
		expect(res.headers.get("content-type")).toBe("application/json; charset=utf-8");
		expect(res.headers.get("cache-control")).toBe("no-store");
		expect(await res.text()).toBe('{"ok":true,"schema":"0036","seed":"done","driver":"sqlite"}');
	});
});
