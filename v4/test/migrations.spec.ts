// The runner's own contract (plan §5 S0.4): the chain, idempotency, the seed stamp the S0.3 gate
// reads, and the one v4-only migration. Backend-independent assertions run on local DO SQLite, which
// is the cheapest backend to build a database on; the D1 and DO parity comparison lives in
// schema-parity.spec.ts.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../src/db/do-sql";
import { runMigrations, SEED_PENDING, seedState } from "../src/db/migration-runner";
import { DEFERRED_SEEDS, MIGRATIONS } from "../src/db/migrations";
import { type Sql, statement } from "../src/db/sql";
import { resetSchema } from "./helpers/schema";

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

describe("the migration chain", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = sqlite("runner");
		await resetSchema(sql);
	});

	it("knows the 35 PHP migrations and 0036, in order", () => {
		expect(MIGRATIONS.map((m) => m.version)).toEqual([
			"0001",
			"0002",
			"0003",
			"0004",
			"0005",
			"0006",
			"0007",
			"0008",
			"0009",
			"0010",
			"0011",
			"0012",
			"0013",
			"0014",
			"0015",
			"0016",
			"0017",
			"0018",
			"0019",
			"0020",
			"0021",
			"0022",
			"0023",
			"0024",
			"0025",
			"0026",
			"0027",
			"0028",
			"0029",
			"0030",
			"0031",
			"0032",
			"0033",
			"0034",
			"0035",
			"0036",
		]);
	});

	it("applies the whole chain to an empty database and records every version", async () => {
		const run = await runMigrations(sql, { now: "2026-11-23 12:00:00" });
		expect(run.fresh).toBe(true);
		expect(run.applied).toHaveLength(36);

		const rows = await sql.all({ sql: "SELECT version FROM schema_migrations ORDER BY version" });
		expect(rows.map((r) => r.version)).toEqual(MIGRATIONS.map((m) => m.version));
		const counts = await sql.all({ sql: "SELECT COUNT(*) AS n FROM schema_migrations" });
		expect(Number(counts[0]?.n)).toBe(36);
	});

	it("is a no-op on a second run", async () => {
		await runMigrations(sql);
		const second = await runMigrations(sql);

		expect(second.applied).toEqual([]);
		expect(second.fresh).toBe(false);
	});

	it("marks a fresh install as owing its seeds, and does not clear the stamp on a re-run", async () => {
		const run = await runMigrations(sql);
		expect(run.seed).toBe(SEED_PENDING);
		expect(await seedState(sql)).toBe(SEED_PENDING);

		// The seed phase (S0.3) owns the flip; a later migration run must not undo it.
		await sql.batch([statement("UPDATE app_settings SET value = 'done' WHERE name = 'v4_seed'")]);
		await runMigrations(sql);
		expect(await seedState(sql)).toBe("done");
	});

	it("is atomic per migration: a failing step leaves no row and no schema", async () => {
		const failing = MIGRATIONS.slice(0, 2).concat([
			{
				version: "0003",
				name: "deliberately_broken",
				sql: ["CREATE TABLE broken (id INTEGER PRIMARY KEY)", "INSERT INTO broken (nope) VALUES (1)"],
			},
		]);

		await expect(runMigrations(sql, { migrations: failing })).rejects.toThrow();

		const applied = await sql.all({ sql: "SELECT version FROM schema_migrations ORDER BY version" });
		expect(applied.map((r) => r.version)).toEqual(["0001", "0002"]);
		const broken = await sql.all({ sql: "SELECT 1 AS present FROM sqlite_master WHERE name = 'broken'" });
		expect(broken).toEqual([]);
	});

	it("opens every write unit after 0036 with D10's envelope, and none before it", async () => {
		// A fresh install stops at 0036, which is the migration that creates the table: nothing before
		// it can insert into it, so those units key on schema_migrations' own row instead.
		await runMigrations(sql);
		const before = await sql.all({ sql: "SELECT COUNT(*) AS n FROM _write_log" });
		expect(Number(before[0]?.n)).toBe(0);

		// Any later migration is one of "every write unit": it opens with the envelope.
		const withProbe = MIGRATIONS.concat([
			{
				version: "0037",
				name: "envelope_probe",
				sql: ["CREATE TABLE probe_envelope (id INTEGER PRIMARY KEY)"],
			},
		]);
		const run = await runMigrations(sql, { migrations: withProbe });
		expect(run.applied).toEqual(["0037"]);

		const after = await sql.all({ sql: "SELECT id, created_at FROM _write_log" });
		expect(after).toHaveLength(1);
		expect(String(after[0]?.id)).toMatch(/^[0-9a-f-]{36}$/);
	});

	it("creates D10's write log, so a write unit has its idempotency key", async () => {
		await runMigrations(sql);
		const table = await sql.all({
			sql: "SELECT 1 AS present FROM sqlite_master WHERE type = 'table' AND name = '_write_log'",
		});
		const index = await sql.all({
			sql: "SELECT 1 AS present FROM sqlite_master WHERE type = 'index' AND name = 'idx_write_log_created'",
		});
		expect(table).toHaveLength(1);
		expect(index).toHaveLength(1);

		// The envelope of rule 10: the key is a text id, the conflict is the same one a replay hits.
		await sql.batch([
			statement("INSERT INTO _write_log (id, created_at) VALUES (?, ?)", ["k1", "2026-11-23 12:00:00"]),
		]);
		await expect(
			sql.batch([
				statement("INSERT INTO _write_log (id, created_at) VALUES (?, ?)", ["k1", "2026-11-23 12:00:01"]),
			]),
		).rejects.toThrow(/UNIQUE constraint failed: _write_log\.id/);
	});
});

describe("the seed debt the port leaves to S0.3", () => {
	it("names every step whose only job is writing configuration rows", () => {
		expect(DEFERRED_SEEDS.map((s) => s.version)).toEqual([
			"0013",
			"0022",
			"0023",
			"0024",
			"0025",
			"0026",
			"0027",
		]);
	});
});
