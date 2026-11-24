// The one database interface the app is written against (plan §10.1): async everywhere, because D1
// and rqlite are network hops and local DO SQLite answers immediately. No handler knows which
// backend it is on — that is this interface's whole job.
//
// There is no `begin()`/`commit()`: the unit of work is a batch, written for the weakest backend
// (rqlite has no transaction across requests). Statements are collected and flushed as one request
// — "plan-then-flush" (§10.2). A driver implements `all()` for reads and `batch()` for a write unit.

export type Row = Record<string, unknown>;

export interface Statement {
	readonly sql: string;
	readonly params?: readonly unknown[];
}

/** One statement's effect inside a batch, in the order the statement was sent. */
export interface StatementResult {
	/**
	 * Rows matched and changed (`meta.changes` on D1, `rows_affected` on rqlite), or `null` when the
	 * backend does not report it — the Durable Object's `transactionSync` either commits the whole
	 * unit or throws, so it has no per-statement count (D26). Never decide an outcome from this
	 * field: read the state back and assert on that.
	 */
	readonly changes: number | null;
	/** `meta.last_row_id` on D1, `last_insert_id` on rqlite: the id this statement inserted, if any. */
	readonly lastRowId: number | null;
}

export interface Sql {
	/** A read. Never a write: reads retry freely (§10 rule 10), a write unit does not. */
	all(statement: Statement): Promise<Row[]>;
	/**
	 * One atomic unit of work. Either every statement takes effect or none does — a backend that
	 * cannot promise that is not a `Sql`.
	 */
	batch(statements: readonly Statement[]): Promise<readonly StatementResult[]>;
}

/** Statement helper: `sql\`...\`` is not used, so params stay visibly separate from the SQL text. */
export const statement = (sql: string, params?: readonly unknown[]): Statement =>
	params === undefined ? { sql } : { sql, params };

/** A UTC timestamp in the app's stored form (`gmdate('Y-m-d H:i:s')`), from an injectable clock. */
export const utcNow = (clock: Date = new Date()): string =>
	clock.toISOString().slice(0, 19).replace("T", " ");
