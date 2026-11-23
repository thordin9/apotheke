// The same driver and the same runner against a real `rqlited` (plan §5 S0.8, gate G0's third backend).
// This file is where the retry policy meets an election and where the parity comparison runs on the
// self-host default rather than on an emulator.
//
// It runs when `RQLITE_URL` is set — `v4.yml`'s rqlite job sets it and fails the run if anything here
// is skipped, so "green" cannot hide an absent server. The driver's own failure branches are in
// `rqlite.spec.ts`, which needs no server at all.

import { env } from "cloudflare:workers";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { runMigrations, SchemaNewerThanCodeError, WRITE_LOG_VERSION } from "../src/db/migration-runner";
import { RqliteSql } from "../src/db/rqlite";
import { runSeeds } from "../src/db/seed";
import { type Sql, statement } from "../src/db/sql";
import { loadConfig } from "../src/platform/config";
import fixture from "./fixtures/php-v4.0.0-schema.json";
import { compareSchema, dumpSchema, resetSchema, type SchemaObject } from "./helpers/schema";

const CLOCK = "2026-11-23 12:00:00";
const WRITE_LOG_ALLOWLIST = ["_write_log", "idx_write_log_created"];

/** `username:password`, split on the first colon — the same rule `loadDatabaseConfig()` applies. */
const parseAuth = (raw: string | undefined): { username: string; password: string } | null => {
	if (raw === undefined || raw === "") {
		return null;
	}
	const separator = raw.indexOf(":");
	return separator <= 0 ? null : { username: raw.slice(0, separator), password: raw.slice(separator + 1) };
};

const url = env.RQLITE_URL;
const auth = parseAuth(env.RQLITE_AUTH);
const phpSchema = fixture.objects as unknown as readonly SchemaObject[];

const sql = (): Sql => new RqliteSql({ url, auth, readConsistency: "weak" });

describe.skipIf(url === "")("rqlite (live)", () => {
	beforeEach(async () => {
		await resetSchema(sql());
	});

	// After every spec too. The node is shared with whatever runs next in the job — in `v4.yml` that is
	// the workerd boot, which runs the migration chain — and the refuse-if-newer case leaves a version
	// 9999 row behind on purpose. A suite that poisons the database it just used is not a suite.
	afterEach(async () => {
		await resetSchema(sql());
	});

	it("round-trips parameters, nulls, blobs and unicode", async () => {
		const db = sql();
		await db.batch([
			statement("CREATE TABLE probe (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, data BLOB, n TEXT)"),
		]);
		const [inserted] = await db.batch([
			statement("INSERT INTO probe (name, data, n) VALUES (?,?,?)", [
				"λ-ünicode",
				new Uint8Array([1, 2, 255, 0]),
				null,
			]),
		]);

		expect(inserted?.changes).toBe(1);
		const rows = await db.all({ sql: "SELECT id, name, data, n FROM probe" });
		expect(rows).toEqual([{ id: 1, name: "λ-ünicode", data: new Uint8Array([1, 2, 255, 0]), n: null }]);
	});

	it("leaves nothing behind when a statement in the unit fails", async () => {
		const db = sql();

		await expect(
			db.batch([
				statement("CREATE TABLE half (id INTEGER PRIMARY KEY)"),
				statement("INSERT INTO half (id) VALUES (1)"),
				statement("INSERT INTO nope (id) VALUES (1)"),
			]),
		).rejects.toThrow(/statement 3/);

		const tables = await db.all({ sql: "SELECT name FROM sqlite_master WHERE name = 'half'" });
		expect(tables).toEqual([]);
	});

	it("maps a replayed unit to success and applies it once", async () => {
		const db = sql();
		await db.batch([
			statement("CREATE TABLE ledger (id TEXT PRIMARY KEY)"),
			// 0036's table, created here because this test starts from an empty database: the envelope
			// is the point of the test, not the migration chain (which has its own case below).
			statement("CREATE TABLE _write_log (id TEXT NOT NULL PRIMARY KEY, created_at TEXT NOT NULL)"),
		]);
		const unit = [
			statement("INSERT INTO _write_log (id, created_at) VALUES (?, ?)", ["unit-1", CLOCK]),
			statement("INSERT INTO ledger (id) VALUES (?)", ["row-1"]),
		];

		await db.batch(unit);
		await expect(db.batch(unit)).resolves.toHaveLength(2);

		expect(await db.all({ sql: "SELECT id FROM ledger" })).toEqual([{ id: "row-1" }]);
	});

	it("runs the whole chain, and its schema matches the PHP oracle under D13", async () => {
		const db = sql();
		const run = await runMigrations(db, { now: CLOCK });

		expect(run.fresh).toBe(true);
		expect(run.applied).toHaveLength(36);
		expect(run.applied.at(-1)).toBe(WRITE_LOG_VERSION);

		const problems = compareSchema(phpSchema, await dumpSchema(db), WRITE_LOG_ALLOWLIST);
		expect(problems).toEqual([]);
	});

	it("seeds, and the seeded rows are the ones PHP writes", async () => {
		const db = sql();
		const run = await runMigrations(db, { now: CLOCK });
		await runSeeds(db, {
			config: loadConfig({
				APP_PASSWORD: fixture.seed_config.app_password,
				TZ: fixture.seed_config.timezone,
			}),
			now: CLOCK,
			fresh: run.fresh,
		});

		const settings = (
			await db.all({ sql: "SELECT name, value, updated_at FROM settings ORDER BY name" })
		).map((r) => ({
			name: String(r.name),
			value: String(r.value ?? ""),
			updated_at: String(r.updated_at),
		}));
		const roles = (
			await db.all({ sql: "SELECT role_key, name, perms, builtin, created_at FROM roles ORDER BY role_key" })
		).map((r) => ({
			role_key: String(r.role_key),
			name: String(r.name),
			perms: String(r.perms),
			builtin: Number(r.builtin),
			created_at: String(r.created_at),
		}));

		expect(settings).toEqual(fixture.seed_rows.settings);
		expect(roles).toEqual(fixture.seed_rows.roles);
	});

	it("refuses a database newer than the code", async () => {
		const db = sql();
		await runMigrations(db, { now: CLOCK });
		await db.batch([
			statement("INSERT INTO schema_migrations (version, name, applied_at) VALUES (?,?,?)", [
				"9999",
				"future",
				CLOCK,
			]),
		]);

		await expect(runMigrations(db, { now: CLOCK })).rejects.toThrow(SchemaNewerThanCodeError);
	});
});
