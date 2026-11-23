// The `Installation` Durable Object (D12, plan §10.1). It is kept, one per installation, and
// demoted: `alarms` for per-installation jobs (Cron Triggers are per-Worker, not per-installation),
// `blockConcurrencyWhile` as the migration lock and refuse-if-newer gate, lease/cursor state only.
// It holds no application data and sits off the request path.
//
// Exception, and the only one: under `DB_DRIVER=sqlite` (single-process self-host) the database is
// `ctx.storage.sql`, so in exactly that mode the DO owns the database too. That is why the RPC below
// exists: the `Sql` driver for that mode is a thin client of this object. Hosted D1 and rqlite never
// call it — and no handler knows which mode it is in, because that decision lives in the driver
// factory, not in a handler.

import { DurableObject } from "cloudflare:workers";
import type { Row, Statement } from "./db/sql";
import type { Env } from "./env";

export class Installation extends DurableObject<Env> {
	/** A read, forwarded to the DO's SQLite. */
	query(sql: string, params: readonly unknown[]): Row[] {
		return this.ctx.storage.sql.exec(sql, ...params).toArray() as Row[];
	}

	/**
	 * One atomic unit of work. `transactionSync` is the local backend's batch: it commits when the
	 * callback returns and rolls back when it throws, which is the same contract as D1's `batch()`
	 * and rqlite's `?transaction` request.
	 */
	batch(statements: readonly Statement[]): void {
		this.ctx.storage.transactionSync(() => {
			for (const s of statements) {
				this.ctx.storage.sql.exec(s.sql, ...(s.params ?? []));
			}
		});
	}
}
