// The hosted driver (plan §10, S0.7 landed early because S0.4's parity test needs a backend).
// `env.DB` is one D1 database per installation (D8); `batch()` is the unit of work and is atomic.
//
// Nothing D1-specific leaks past this file: callers see `Sql`. The pieces that do differ from plain
// SQLite are handled here — `meta.changes` / `meta.last_row_id` for results, and the fact that a
// statement has at most 100 bound parameters (§10 rule 3, ADR-002's cap).

import type { Row, Sql, Statement, StatementResult } from "./sql";

/** §10 rule 3: one statement binds at most this many parameters. The binding, not SQLite's 999. */
export const MAX_BOUND_PARAMS = 100;

/**
 * D117: more parameters than one D1 statement takes, refused here — before `prepare`, so nothing is
 * sent and the caller reads v4's words rather than the engine's. `v4/test/sqlite.spec.ts` measures
 * the same 101st bind on the DO driver, where the engine is the one that refuses; this class exists
 * so the hosted driver never gets that far.
 */
export class TooManyBoundParams extends Error {
	constructor(readonly count: number) {
		super(
			`a statement binds ${count} parameters, and one D1 statement takes at most ${MAX_BOUND_PARAMS} (§10 rule 3)`,
		);
		this.name = "TooManyBoundParams";
	}
}

/** The whole unit is checked before any of it is prepared, so one bad statement cannot half-send a batch. */
const assertBound = (statements: readonly Statement[]): void => {
	for (const entry of statements) {
		const count = entry.params?.length ?? 0;
		if (count > MAX_BOUND_PARAMS) {
			throw new TooManyBoundParams(count);
		}
	}
};

export class D1Sql implements Sql {
	constructor(private readonly db: D1Database) {}

	async all(statement: Statement): Promise<Row[]> {
		assertBound([statement]);
		const prepared = this.db.prepare(statement.sql);
		const bound = statement.params === undefined ? prepared : prepared.bind(...statement.params);
		const { results } = await bound.all<Row>();

		return results;
	}

	async batch(statements: readonly Statement[]): Promise<readonly StatementResult[]> {
		if (statements.length === 0) {
			return [];
		}
		assertBound(statements);
		const prepared = statements.map((s) => {
			const p = this.db.prepare(s.sql);

			return s.params === undefined ? p : p.bind(...s.params);
		});
		const results = await this.db.batch<Row>(prepared);

		return results.map((r) => ({
			changes: r.meta.changes ?? null,
			lastRowId: r.meta.last_row_id === undefined || r.meta.last_row_id === 0 ? null : r.meta.last_row_id,
		}));
	}
}
