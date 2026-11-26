// Readiness (plan §5 S0.3): the gate that makes "seed pending" impossible to serve through, and the
// one probe that must keep answering whatever the database is doing (§11 rule 7).

import { SELF } from "cloudflare:test";
import { env } from "cloudflare:workers";
import { describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../src/db/do-sql";
import { runMigrations, seedState } from "../src/db/migration-runner";
import { type Sql, statement } from "../src/db/sql";
import { loadConfig } from "../src/platform/config";
import { prepareInstallation, problem, readinessResponse } from "../src/readiness";
import { SLOW_TEST_MS } from "./helpers/deadline";
import { resetSchema } from "./helpers/schema";

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

const config = loadConfig({ APP_PASSWORD: "correct horse battery staple 2026", TZ: "UTC" });

describe("/readyz and /healthz", () => {
	it("/readyz reports the schema and a completed seed once boot has run", async () => {
		const res = await SELF.fetch("http://apotheke-v4.test/readyz");
		expect(res.status).toBe(200);
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

	it("turns any other failure into a 503 with the message, not a crash", () => {
		const res = problem(503, "database", "no such table: settings");
		expect(res.status).toBe(503);
		expect(res.headers.get("content-type")).toContain("application/problem+json");
		// A failure answer is never cached: v3's `PlainHandler` sent `no-store` on every answer it
		// rendered, and v4's boot, readiness and session-flush failures all come through here (D136).
		expect(res.headers.get("cache-control")).toBe("no-store");
	});
});
