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
//
// Boot (S0.3): the constructor starts `prepare()` inside `blockConcurrencyWhile`, so no RPC — and
// therefore no request — is answered until migrations have run and the seed phase has completed or
// refused. `ready()` hands the result to the Worker's `/readyz` and to the guard in `src/index.ts`.

import { DurableObject } from "cloudflare:workers";
import { D1Sql } from "./db/d1";
import { rqliteSql } from "./db/rqlite";
import type { Row, Sql, Statement } from "./db/sql";
import type { Env } from "./env";
import { clockFrom } from "./platform/clock";
import { loadConfig, loadDatabaseConfig } from "./platform/config";
import { prepareInstallation, type ReadyResult } from "./readiness";

/** The `Sql` a DO gets over its own storage: `transactionSync` is the unit of work (D12, §10). */
const storageSql = (ctx: DurableObjectState): Sql => ({
	async all(statement: Statement): Promise<Row[]> {
		return ctx.storage.sql.exec(statement.sql, ...(statement.params ?? [])).toArray() as Row[];
	},
	async batch(statements: readonly Statement[]) {
		if (statements.length === 0) {
			return [];
		}
		ctx.storage.transactionSync(() => {
			for (const s of statements) {
				ctx.storage.sql.exec(s.sql, ...(s.params ?? []));
			}
		});
		return statements.map(() => ({ changes: 0, lastRowId: null }));
	},
});

/** How long after a failed boot before `/readyz` may try the database again. */
const RETRY_COOLDOWN_MS = 1_000;

export class Installation extends DurableObject<Env> {
	/** Resolved once per object lifetime, inside `blockConcurrencyWhile`. */
	private readonly readiness: Promise<ReadyResult>;
	/** When `prepare()` last ran, so a retry cannot become one per request. */
	private lastPrepareAt = 0;
	/** One retry at a time: two requests must not migrate concurrently. */
	private retrying: Promise<ReadyResult> | null = null;

	constructor(ctx: DurableObjectState, env: Env) {
		super(ctx, env);
		this.readiness = ctx.blockConcurrencyWhile(() => this.prepare());
	}

	private async prepare(): Promise<ReadyResult> {
		this.lastPrepareAt = Date.now();
		const config = loadConfig(this.env);
		const now = clockFrom(this.env.APOTHEKE_CLOCK).stamp();

		try {
			const database = loadDatabaseConfig(this.env);
			if (database.mode === "sqlite") {
				return await prepareInstallation(storageSql(this.ctx), config, now);
			}
			if (database.mode === "d1") {
				return await prepareInstallation(new D1Sql(this.env.DB), config, now);
			}

			return await prepareInstallation(rqliteSql(database), config, now);
		} catch (error) {
			// A bad DB_DRIVER, a malformed RQLITE_URL or an unreachable node: refused, never
			// substituted. `/healthz` keeps answering; `/readyz` says which one it was.
			return {
				ok: false,
				code: "backend",
				detail: error instanceof Error ? error.message : String(error),
			};
		}
	}

	/**
	 * Whether this installation may serve, and why not when it may not.
	 *
	 * A boot that reported the *database* unreachable is retried here, once, with a cooldown: §11 rule 2
	 * requires the app to come up if it wins the race with rqlite, and `depends_on` does not survive a
	 * restart. Only `database` retries — a schema newer than the code, or a refused seed, is a decision,
	 * not a hiccup, and retrying it would only hide it.
	 */
	async ready(): Promise<ReadyResult> {
		const boot = await this.readiness;
		if (boot.ok || boot.code !== "database") {
			return boot;
		}
		if (Date.now() - this.lastPrepareAt < RETRY_COOLDOWN_MS) {
			return boot;
		}
		this.retrying ??= this.prepare().finally(() => {
			this.retrying = null;
		});

		return await this.retrying;
	}

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
