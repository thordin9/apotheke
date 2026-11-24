// The `Sql` driver for `DB_DRIVER=sqlite` (single-process self-host, plan §10): a thin async client of
// the installation's Durable Object, which owns `ctx.storage.sql` in that one mode (D12).
//
// The DO answers synchronously; the interface is still async (§10 rule 1) so not one handler cares.

import type { Row, Sql, Statement, StatementResult } from "./sql";

/** The slice of the RPC stub this driver uses. */
export interface InstallationStub {
	query(sql: string, params: readonly unknown[]): Promise<Row[]>;
	batch(statements: readonly Statement[]): Promise<void>;
}

export class DurableObjectSql implements Sql {
	constructor(private readonly installation: InstallationStub) {}

	async all(statement: Statement): Promise<Row[]> {
		return this.installation.query(statement.sql, statement.params ?? []);
	}

	async batch(statements: readonly Statement[]): Promise<readonly StatementResult[]> {
		if (statements.length === 0) {
			return [];
		}
		// The DO's `transactionSync` is the unit of work, so there is nothing to report per statement:
		// the batch either committed or threw. `changes: null` is that fact in the type (D26) — it said
		// `0` before, and code that read it as "no rows changed" refused every write on this backend.
		await this.installation.batch(statements);

		return statements.map(() => ({ changes: null, lastRowId: null }));
	}
}

/** `env.INSTALLATION.get(...)` → `Sql`, so a caller never touches the stub. */
export const durableObjectSql = (stub: InstallationStub): Sql => new DurableObjectSql(stub);
