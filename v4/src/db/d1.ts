// The hosted driver (plan §10, S0.7 landed early because S0.4's parity test needs a backend).
// `env.DB` is one D1 database per installation (D8); `batch()` is the unit of work and is atomic.
//
// Nothing D1-specific leaks past this file: callers see `Sql`. The pieces that do differ from plain
// SQLite are handled here — `meta.changes` / `meta.last_row_id` for results, and the fact that a
// statement has at most 100 bound parameters (§10 rule 3, ADR-002's cap).

import type { Row, Sql, Statement, StatementResult } from "./sql";

export class D1Sql implements Sql {
	constructor(private readonly db: D1Database) {}

	async all(statement: Statement): Promise<Row[]> {
		const prepared = this.db.prepare(statement.sql);
		const bound = statement.params === undefined ? prepared : prepared.bind(...statement.params);
		const { results } = await bound.all<Row>();

		return results;
	}

	async batch(statements: readonly Statement[]): Promise<readonly StatementResult[]> {
		if (statements.length === 0) {
			return [];
		}
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
