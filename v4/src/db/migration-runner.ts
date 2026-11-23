// The migration runner (plan §5 S0.4): one dialect, one runner, refuse-if-newer, and the unit of work
// supplied by the driver.
//
// Shape of a run, and why:
//   - `schema_migrations` is created first, idempotently. It is also the only thing the runner knows
//     about the database before a migration has run.
//   - One batch per migration: an idempotency key, the migration's statements, and its
//     `schema_migrations` row — all in the same unit.
//   - The key is D10's envelope (`INSERT INTO _write_log ...`, plan §10 rule 10) once `_write_log`
//     exists, i.e. from 0036 on. Before it exists there is nothing to insert into, so the units before
//     it carry `schema_migrations`' own row as the key: a replayed batch dies on that primary-key
//     conflict before any DDL runs, which is the same property. A driver maps that conflict to success
//     (§10 rule 10); until then it is a loud error, and loud is right for a migration.
//   - `plan()` steps read while planning and write in the same single batch; nothing reads back a
//     statement's effect inside a unit (§10.2 — a dependent id is expressed in SQL).
//   - Refuse-if-newer: a database with a version the code does not know is refused, never downgraded.
//     The failure is loud because the alternative is a newer schema read by older code.
//   - A fresh database is stamped `v4_seed=pending` (§5 S0.4, the S0.3 gate): the configuration rows
//     the port deliberately did not seed are then a known debt, not a silent skip. `/readyz` refuses
//     until the seed phase has run; `/healthz` still answers (§11 rule 7).

import { MIGRATIONS, type Migration, type MigrationContext, type Statement } from "./migrations";
import { type Sql, utcNow } from "./sql";

/** The seed stamp's key in `app_settings`; the seed phase (S0.3) sets it to `done`. */
export const SEED_STAMP = "v4_seed";

export const SEED_PENDING = "pending";
export const SEED_DONE = "done";

/** The migration that creates `_write_log`; the write units before it key on `schema_migrations`. */
export const WRITE_LOG_VERSION = "0036";

export class SchemaNewerThanCodeError extends Error {
	constructor(unknownVersions: readonly string[], knownMax: string) {
		super(
			`This database is at a schema the code does not know (${unknownVersions.join(", ")}); the newest migration here is ${knownMax}. Upgrade the application instead of downgrading the database.`,
		);
		this.name = "SchemaNewerThanCodeError";
	}
}

export type SeedState = "pending" | "done" | "unknown";

export interface MigrationRun {
	/** Versions this run applied, in order. */
	readonly applied: readonly string[];
	/** True when `schema_migrations` was empty before the run. */
	readonly fresh: boolean;
	/** The seed stamp after the run. */
	readonly seed: SeedState;
}

export interface RunOptions {
	/** Injectable clock: the app's stored UTC form (`gmdate('Y-m-d H:i:s')`). */
	readonly now?: string;
	/** The migration list; tests pass a shorter one. */
	readonly migrations?: readonly Migration[];
}

/** `schema_migrations`, exactly as the PHP runner creates it. */
const SCHEMA_MIGRATIONS_DDL = `CREATE TABLE IF NOT EXISTS schema_migrations (
	version    TEXT NOT NULL PRIMARY KEY,
	name       TEXT NOT NULL,
	applied_at TEXT NOT NULL
)`;

const tableExists = async (sql: Sql, name: string): Promise<boolean> => {
	const rows = await sql.all({
		sql: "SELECT 1 AS present FROM sqlite_master WHERE type = 'table' AND name = ?",
		params: [name],
	});

	return rows.length > 0;
};

const appliedVersions = async (sql: Sql): Promise<string[]> => {
	if (!(await tableExists(sql, "schema_migrations"))) {
		return [];
	}
	const rows = await sql.all({ sql: "SELECT version FROM schema_migrations ORDER BY version" });

	return rows.map((r) => String(r.version));
};

/** `pending` until the S0.3 seed phase has run; `unknown` on a database too old to say. */
export const seedState = async (sql: Sql): Promise<SeedState> => {
	if (!(await tableExists(sql, "app_settings"))) {
		return "unknown";
	}
	const rows = await sql.all({ sql: "SELECT value FROM app_settings WHERE name = ?", params: [SEED_STAMP] });
	const value = rows.length === 0 ? null : String(rows[0]?.value);

	return value === SEED_PENDING ? "pending" : value === SEED_DONE ? "done" : "unknown";
};

/** D10's envelope: the id a unit of work carries so a replay is a primary-key conflict, not a redo. */
const envelope = (createdAt: string): Statement => ({
	sql: "INSERT INTO _write_log (id, created_at) VALUES (?, ?)",
	params: [crypto.randomUUID(), createdAt],
});

const LACKS_FTS5 = /\bno such module:?\s*fts5\b/i;

/**
 * Flush one unit. A backend without FTS5 refuses 0019's virtual table; PHP caught that exception and
 * carried on with substring search, and so does this — once, with the optional statements dropped,
 * which is safe because the batch is atomic and the first attempt left nothing behind.
 */
const flush = async (sql: Sql, statements: readonly Statement[]): Promise<void> => {
	const required = statements.filter((s) => s.optional !== true);
	try {
		await sql.batch(statements);
	} catch (error) {
		if (required.length === statements.length || !LACKS_FTS5.test(String(error))) {
			throw error;
		}
		await sql.batch(required);
	}
};

const statementsFor = async (
	migration: Migration,
	context: MigrationContext,
): Promise<readonly Statement[]> => {
	if (migration.sql) {
		return migration.sql.map((sql) => ({ sql }));
	}
	if (migration.plan) {
		return migration.plan(context);
	}
	throw new Error(`Migration ${migration.version} has neither sql nor plan.`);
};

export const runMigrations = async (sql: Sql, options: RunOptions = {}): Promise<MigrationRun> => {
	const migrations = options.migrations ?? MIGRATIONS;
	const appliedAt = options.now ?? utcNow();
	const known = new Set(migrations.map((m) => m.version));

	await sql.batch([{ sql: SCHEMA_MIGRATIONS_DDL }]);

	const applied = await appliedVersions(sql);
	const unknown = applied.filter((version) => !known.has(version));
	if (unknown.length > 0) {
		throw new SchemaNewerThanCodeError(unknown, migrations[migrations.length - 1]?.version ?? "none");
	}

	const fresh = applied.length === 0;
	const done = new Set(applied);
	let writeLog = applied.includes(WRITE_LOG_VERSION);
	const context: MigrationContext = {
		now: appliedAt,
		all: (statement, params) =>
			sql.all(params === undefined ? { sql: statement } : { sql: statement, params }),
	};
	const appliedNow: string[] = [];

	for (const migration of migrations) {
		if (done.has(migration.version)) {
			continue;
		}
		const statements = await statementsFor(migration, context);
		const batch: Statement[] = [];
		if (writeLog) {
			batch.push(envelope(appliedAt));
		}
		batch.push({
			sql: "INSERT INTO schema_migrations (version, name, applied_at) VALUES (?,?,?)",
			params: [migration.version, migration.name, appliedAt],
		});
		batch.push(...statements);
		// `app_settings` exists from 0002 on, so the seed stamp can start there: any stopping point
		// from then on still leaves the install marked as owing its seeds.
		if (fresh && migration.version >= "0002") {
			batch.push({
				sql: "INSERT INTO app_settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO NOTHING",
				params: [SEED_STAMP, SEED_PENDING],
			});
		}
		await flush(sql, batch);
		appliedNow.push(migration.version);
		if (migration.version === WRITE_LOG_VERSION) {
			writeLog = true;
		}
	}

	return { applied: appliedNow, fresh, seed: await seedState(sql) };
};
