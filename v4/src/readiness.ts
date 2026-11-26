// Readiness (plan §5 S0.3): one place decides whether this installation may serve.
//
// The order is migration → seed → stamp, and it runs inside the `Installation` Durable Object's
// `blockConcurrencyWhile` at boot (D12), so "seed pending" is a state that exists only inside one
// boot. Two consequences worth writing down:
//
//   - `/healthz` never consults this. It says "the process is up", so a database that is still
//     electing a leader — or an install whose seed was refused — must not restart-loop the compose
//     stack (§11 rule 7). The self-host healthcheck probes `/healthz`, never `/readyz`.
//   - `/readyz` is where the reason goes: 200 with the schema version, or 503 problem+json naming
//     what is wrong. It is answered before the router (S0.5, `src/index.ts`), so it stays a bare
//     probe that an unauthenticated caller can read; the compose stack still defaults to `/healthz`,
//     because a 503 there means "still booting", not "restart me".

import { runMigrations, SchemaNewerThanCodeError, seedState } from "./db/migration-runner";
import { KNOWN_VERSIONS } from "./db/migrations";
import { runSeeds, SeedRefusedError } from "./db/seed";
import type { Sql } from "./db/sql";
import type { AppConfig } from "./platform/config";

export type ReadyProblem = "schema.newer" | "seed.refused" | "seed.pending" | "backend" | "database";

export type ReadyResult =
	| { readonly ok: true; readonly schema: string; readonly seed: "done" }
	| { readonly ok: false; readonly code: ReadyProblem; readonly detail: string };

/** The newest migration this code knows: what a ready install reports. */
export const schemaVersion = (): string => KNOWN_VERSIONS[KNOWN_VERSIONS.length - 1] ?? "";

/** Migrate, seed, and say whether the installation may serve. Never throws: the answer is the result. */
export const prepareInstallation = async (sql: Sql, config: AppConfig, now: string): Promise<ReadyResult> => {
	try {
		const run = await runMigrations(sql, { now });
		await runSeeds(sql, { config, now, fresh: run.fresh });
		const seed = await seedState(sql);
		if (seed !== "done") {
			return {
				ok: false,
				code: "seed.pending",
				detail: `The seed phase has not completed (state: ${seed}).`,
			};
		}
		return { ok: true, schema: schemaVersion(), seed: "done" };
	} catch (error) {
		if (error instanceof SchemaNewerThanCodeError) {
			return { ok: false, code: "schema.newer", detail: error.message };
		}
		if (error instanceof SeedRefusedError) {
			return { ok: false, code: "seed.refused", detail: error.message };
		}
		return { ok: false, code: "database", detail: error instanceof Error ? error.message : String(error) };
	}
};

/**
 * problem+json (plan §4 rule 3, §5 S0.5), with the same shape the router will use. Never cached:
 * v3's `PlainHandler` sent `no-store` on every answer it rendered, and a boot failure that a cache
 * keeps is worse than a wrong content type (D136).
 */
export const problem = (status: number, title: string, detail: string): Response =>
	new Response(JSON.stringify({ type: "about:blank", title, status, detail }), {
		status,
		headers: {
			"content-type": "application/problem+json; charset=utf-8",
			"cache-control": "no-store",
		},
	});

/** `/readyz`: 200 when the installation may serve, 503 with the reason when it may not. */
export const readinessResponse = (result: ReadyResult, driver: string): Response =>
	result.ok
		? new Response(JSON.stringify({ ok: true, schema: result.schema, seed: result.seed, driver }), {
				status: 200,
				headers: { "content-type": "application/json; charset=utf-8" },
			})
		: problem(503, result.code, result.detail);
