// The hosted driver's own cap (plan §10 rule 3, D117): a D1 statement takes at most 100 bound
// parameters. `v4/test/sqlite.spec.ts` measures that same 101st bind on the DO driver, where the
// engine refuses it — fact 3's negative control. What this file adds is the other half: on the
// hosted driver v4 refuses it itself, before `prepare`, so the caller reads v4's error and nothing
// is sent. The refusal must never be the engine's message, which is why the test forbids it by name.

import { env } from "cloudflare:workers";
import { describe, expect, it } from "vitest";
import { D1Sql, MAX_BOUND_PARAMS, TooManyBoundParams } from "../src/db/d1";
import { statement } from "../src/db/sql";

/** One row of `n` distinct columns: SQLite names every expression column `?`, so the aliases differ. */
const placeholders = (n: number): string =>
	new Array(n)
		.fill(0)
		.map((_, i) => `? AS c${i}`)
		.join(", ");

describe("the hosted driver's parameter cap", () => {
	it("binds 100 and refuses the 101st in v4's own words, not the engine's", async () => {
		const sql = new D1Sql(env.DB);

		const [row] = await sql.all({
			sql: `SELECT ${placeholders(MAX_BOUND_PARAMS)}`,
			params: new Array(MAX_BOUND_PARAMS).fill(1),
		});
		expect(Object.keys(row ?? {})).toHaveLength(MAX_BOUND_PARAMS);

		const refused = await sql
			.all({
				sql: `SELECT ${placeholders(MAX_BOUND_PARAMS + 1)}`,
				params: new Array(MAX_BOUND_PARAMS + 1).fill(1),
			})
			.catch((error: unknown) => error);

		expect(refused).toBeInstanceOf(TooManyBoundParams);
		expect((refused as Error).name).toBe("TooManyBoundParams");
		expect((refused as Error).message).toBe(
			"a statement binds 101 parameters, and one D1 statement takes at most 100 (§10 rule 3)",
		);
		expect((refused as Error).message).not.toMatch(/too many SQL variables/);
	});

	it("refuses before it prepares anything, so a bad statement cannot half-send a batch", async () => {
		let prepared = 0;
		const spy = {
			prepare: () => {
				prepared += 1;

				throw new Error("prepare reached");
			},
		} as unknown as D1Database;
		const sql = new D1Sql(spy);

		// The good statement comes first on purpose: a guard that only checked the statement it is
		// about to prepare would let this one through to `prepare` before it noticed the second.
		await expect(
			sql.batch([statement("SELECT 1"), statement("SELECT ?", new Array(MAX_BOUND_PARAMS + 1).fill(1))]),
		).rejects.toThrow(TooManyBoundParams);
		expect(prepared).toBe(0);
	});
});
