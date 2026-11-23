// G0's schema comparison (plan §5): v4's runner against the PHP runner's output, on both backends
// that exist today (D1 emulated and local DO SQLite). rqlite joins the same comparison in S0.8, when
// its driver exists to supply the unit of work — the comparison itself is backend-independent.
//
// The fixture is generated, not hand-written: `php v4/test/fixtures/php-schema-dump.php` migrates a
// throwaway SQLite database with the app's own PdoMigrationRunner and writes every sqlite_master
// object in creation order. Its header records the PHP and SQLite versions it came from; re-run the
// script when the PHP migrations change, which the v4 branch does not do.
//
// 0019's FTS5 statements are `optional` so a backend without the module still migrates (PHP caught
// the same exception and fell back to substring search). That must never turn G0 into a lie: if a
// backend drops them, the comparison has to fail and name every object it lost. Both halves are
// tested here — the drop, and the naming.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { D1Sql } from "../src/db/d1";
import { durableObjectSql, type InstallationStub } from "../src/db/do-sql";
import { runMigrations } from "../src/db/migration-runner";
import { type Sql, statement } from "../src/db/sql";
import fixture from "./fixtures/php-v4.0.0-schema.json";
import { compareSchema, dumpSchema, resetSchema, type SchemaObject } from "./helpers/schema";

/**
 * D10 raises the schema by exactly these two objects (plan §10 rule 10). A literal set on purpose:
 * an addition that is not here fails the comparison in both directions.
 */
const WRITE_LOG_ALLOWLIST = ["_write_log", "idx_write_log_created"];

interface FixtureObject extends SchemaObject {
	/** False for FTS5 shadow tables: SQLite creates them, so replaying their DDL would collide. */
	readonly replay: boolean;
	/** The statement as SQLite stored it, comments intact — `sql` is normalised for comparison. */
	readonly replay_sql: string | null;
}

const phpSchema = fixture.objects as unknown as readonly FixtureObject[];

/** Every object 0019 creates when the backend has FTS5. */
const SEARCH_OBJECTS = phpSchema.filter((o) => o.name.startsWith("search_fts")).map((o) => o.name);

const d1 = (): Sql => new D1Sql(env.DB);

/** A named installation's DO, which owns `ctx.storage.sql` under DB_DRIVER=sqlite (D12). */
const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

/** Rebuild "a v3.72 database": the PHP schema, then its 35 `schema_migrations` rows. */
const replayPhpDatabase = async (sql: Sql): Promise<void> => {
	for (const object of phpSchema) {
		if (object.replay && object.replay_sql !== null) {
			await sql.batch([statement(object.replay_sql)]);
		}
	}
	for (const row of fixture.schema_migrations) {
		await sql.batch([
			statement("INSERT INTO schema_migrations (version, name, applied_at) VALUES (?,?,?)", [
				row.version,
				row.name,
				"2026-11-23 10:00:00",
			]),
		]);
	}
};

describe("schema parity with the PHP runner (G0)", () => {
	beforeEach(async () => {
		await resetSchema(d1());
		await resetSchema(sqlite("parity"));
	});

	it("builds the PHP schema on D1, plus only the D10 allowlist", async () => {
		const sql = d1();
		const run = await runMigrations(sql);
		expect(run.applied).toHaveLength(36);

		expect(compareSchema(phpSchema, await dumpSchema(sql), WRITE_LOG_ALLOWLIST)).toEqual([]);
	});

	it("builds the PHP schema on local DO SQLite, plus only the D10 allowlist", async () => {
		const sql = sqlite("parity");
		const run = await runMigrations(sql);
		expect(run.applied).toHaveLength(36);

		expect(compareSchema(phpSchema, await dumpSchema(sql), WRITE_LOG_ALLOWLIST)).toEqual([]);
	});

	it("runs on a v3.72 database by applying 0036 and nothing else, on both backends", async () => {
		for (const [name, sql] of [
			["D1", d1()],
			["local DO SQLite", sqlite("v372")],
		] as const) {
			await resetSchema(sql);
			await replayPhpDatabase(sql);

			const run = await runMigrations(sql);
			expect(run.applied, name).toEqual(["0036"]);
			expect(run.fresh, name).toBe(false);

			expect(compareSchema(phpSchema, await dumpSchema(sql), WRITE_LOG_ALLOWLIST), name).toEqual([]);
		}
	});

	it("refuses a database newer than the code instead of downgrading it", async () => {
		const sql = sqlite("newer");
		// Constructing the DO runs its own boot; the state under test is what comes after a wipe.
		await resetSchema(sql);
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

		await expect(runMigrations(sql)).rejects.toThrow(/schema the code does not know \(9999\)/);
	});
});

describe("0019's optional FTS5 statements", () => {
	it("fails loudly and names every object when a backend drops them", () => {
		// A backend without FTS5 loses the virtual table, its five shadow tables and the vocabulary
		// view. The comparison must report each one by name; a silent skip would pass G0 and ship a
		// search that does not exist.
		expect(SEARCH_OBJECTS.length).toBeGreaterThanOrEqual(6);
		// No allowlist here: this is about the 0019 objects, not about D10's two.
		const dropped = phpSchema.filter((o) => !o.name.startsWith("search_fts"));
		const problems = compareSchema(phpSchema, dropped, []);

		// Every lost object is named, and nothing else is complained about: one problem per drop.
		for (const name of SEARCH_OBJECTS) {
			expect(problems.some((p) => p.includes(name))).toBe(true);
		}
		expect(problems).toHaveLength(phpSchema.length - dropped.length);
	});

	it("keeps the comparison honest: a changed column is reported, not normalised away", () => {
		const changed = phpSchema.map((o) =>
			o.name === "items" ? { ...o, sql: (o.sql ?? "").replace("name TEXT", "name TEXT COLLATE NOCASE") } : o,
		);
		expect(compareSchema(phpSchema, changed, WRITE_LOG_ALLOWLIST)).not.toEqual([]);
	});
});
