// The `DB_DRIVER=sqlite` driver's own contract (plan §10): single-process self-host, where the
// installation's Durable Object owns `ctx.storage.sql` (D12) and `src/db/do-sql.ts` is a thin RPC
// client of it.
//
// Why this file exists. Every behavioural spec in this suite already runs on this driver —
// `wrangler.jsonc` sets `DB_DRIVER=sqlite` — so its contract was covered by inheritance and the
// facts below were asserted nowhere (no spec referenced `changes` except the rqlite ones). They are
// the facts a handler can get wrong, and three are differences from the other two backends rather
// than properties of SQLite:
//
//   1. `changes` and `lastRowId` are `null` for every statement (D26), so a unit that changed a row
//      and one that changed nothing report identically: reading `changes` here is always a bug, and
//      the fix is to read the state back. (This file is what says so; `users.ts` and `roles.ts`
//      already do it.)
//   2. A failing statement rolls the whole unit back — `transactionSync`'s promise, and the same
//      contract as D1's `batch()` and rqlite's `?transaction`. DDL included: 0019's `optional` FTS5
//      statements depend on it.
//   3. The binding cap is D1's 100 variables (§10 rule 3), not SQLite's default 999, because the
//      engine runs inside the runtime's own sandbox. Measured, not assumed: 100 binds, 101 refuses.
//   4. A BLOB arrives as an `ArrayBuffer`. `rqlite-live.spec.ts` asserts a `Uint8Array` for its
//      driver, and the emulated D1 binding hands back a plain byte array, so the same column has
//      three JS shapes on the three backends. No handler reads a BLOB column today (media lives in
//      R2), which is why asserting this rather than normalising it is the smaller change.
//   5. The engine's identity and its `PRAGMA` settings are refused, not reported: `PRAGMA
//      journal_mode` answers `SQLITE_AUTH` and `SELECT sqlite_version()` answers "not authorized to
//      use function", on this driver and on D1 alike. So `src/` may not tune or even name its engine
//      from SQL, and `adapters.md`'s "measured, not inferred" has this spec behind it. The last test
//      below is the only guard on that sentence.
//
// `v4.yml`'s `sqlite` job is the leg that runs these seven specs by name, and its jq assertion pins
// the count: adding a test here without bumping it turns the next run red on a step nobody reads.
//
// Expected throws in this leg's log. Four lines in every job's output are deliberate refusals, not
// damage — the suite prints them because workerd logs an uncaught exception even when a spec catches
// it. All four, so nobody chases one at 2 a.m. and concludes the driver broke:
//
//   * `too many SQL variables at offset 997` — fact 3's negative control (101 binds).
//   * `no such table: driver_missing` — fact 2's rollback probe, thrown mid-unit by design.
//   * `table broken has no column named nope` — `migrations.spec.ts`'s deliberately broken migration.
//   * `UNIQUE constraint failed: _write_log.id` — the replayed write in `migrations.spec.ts`.

import { env } from "cloudflare:workers";
import { describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../src/db/do-sql";
import { type Sql, statement } from "../src/db/sql";

/** A named installation's DO, which owns the database in this mode (D12). */
const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

describe("the sqlite driver (DO storage)", () => {
	it("round-trips parameters, nulls and unicode", async () => {
		const sql = sqlite("sqlite-driver");
		await sql.batch([
			statement("CREATE TABLE IF NOT EXISTS driver_params (id INTEGER PRIMARY KEY, name TEXT, n TEXT)"),
		]);
		await sql.batch([statement("DELETE FROM driver_params")]);
		await sql.batch([
			statement("INSERT INTO driver_params (id, name, n) VALUES (?,?,?)", [1, "λ-ünicode", null]),
		]);

		expect(await sql.all({ sql: "SELECT id, name, n FROM driver_params" })).toEqual([
			{ id: 1, name: "λ-ünicode", n: null },
		]);
	});

	// `WritesTypedRowsTest`'s subject (retired at 5d65939e): a row stored as JSON — an undo snapshot —
	// reads back as it was written. v4 has no PDO and one storage family, so what survives is the
	// property itself: the values a read returns are typed (an INTEGER is a number, a REAL is a number
	// and not "1.5", a NULL is null), which is what makes the payload round-trip lossless.
	it("returns typed values, so a row survives a JSON round trip unchanged", async () => {
		const sql = sqlite("sqlite-driver");
		await sql.batch([
			statement(
				"CREATE TABLE IF NOT EXISTS driver_typed (id INTEGER PRIMARY KEY, price REAL, note TEXT, gone TEXT)",
			),
		]);
		await sql.batch([statement("DELETE FROM driver_typed")]);
		await sql.batch([
			statement("INSERT INTO driver_typed (id, price, note, gone) VALUES (?,?,?,?)", [7, 1.5, "x", null]),
		]);

		const [row] = await sql.all({ sql: "SELECT id, price, note, gone FROM driver_typed" });

		expect(row).toStrictEqual({ id: 7, price: 1.5, note: "x", gone: null });
		expect(JSON.parse(JSON.stringify(row))).toStrictEqual(row);
	});

	it("binds 100 variables and refuses the 101st", async () => {
		const sql = sqlite("sqlite-driver");
		// Distinct aliases: SQLite names every expression column "?" and the row would carry one key.
		const placeholders = (n: number): string =>
			new Array(n)
				.fill(0)
				.map((_, i) => `? AS c${i}`)
				.join(", ");

		const [row] = await sql.all({ sql: `SELECT ${placeholders(100)}`, params: new Array(100).fill(1) });
		expect(Object.keys(row ?? {})).toHaveLength(100);

		await expect(
			sql.all({ sql: `SELECT ${placeholders(101)}`, params: new Array(101).fill(1) }),
		).rejects.toThrow(/too many SQL variables/);
	});

	it("reports no per-statement count, whether or not a row changed (D26)", async () => {
		const sql = sqlite("sqlite-driver");
		await sql.batch([statement("CREATE TABLE IF NOT EXISTS driver_counts (id INTEGER PRIMARY KEY)")]);
		await sql.batch([statement("DELETE FROM driver_counts")]);

		const changed = await sql.batch([statement("INSERT INTO driver_counts (id) VALUES (1)")]);
		const unchanged = await sql.batch([statement("UPDATE driver_counts SET id = 1 WHERE id = 2")]);

		// The point is the equality: the backend does not report, so the two cases are indistinguishable
		// here and a handler that branched on `changes` would take the same branch in both.
		expect(changed).toEqual([{ changes: null, lastRowId: null }]);
		expect(unchanged).toEqual(changed);
	});

	it("leaves nothing behind when a statement in the unit fails, DDL included", async () => {
		const sql = sqlite("sqlite-driver");
		await sql.batch([statement("DROP TABLE IF EXISTS driver_unit")]);

		await expect(
			sql.batch([
				statement("CREATE TABLE driver_unit (id INTEGER PRIMARY KEY)"),
				statement("INSERT INTO driver_unit (id) VALUES (1)"),
				statement("INSERT INTO driver_missing (id) VALUES (1)"),
			]),
		).rejects.toThrow(/no such table: driver_missing/);

		expect(await sql.all({ sql: "SELECT name FROM sqlite_master WHERE name = 'driver_unit'" })).toEqual([]);
	});

	it("returns a BLOB as an ArrayBuffer, not the rqlite driver's Uint8Array", async () => {
		const sql = sqlite("sqlite-driver");
		await sql.batch([
			statement("CREATE TABLE IF NOT EXISTS driver_blob (id INTEGER PRIMARY KEY, data BLOB)"),
		]);
		await sql.batch([statement("DELETE FROM driver_blob")]);
		await sql.batch([
			statement("INSERT INTO driver_blob (id, data) VALUES (?,?)", [1, new Uint8Array([1, 2, 255, 0])]),
		]);

		const [row] = await sql.all({
			sql: "SELECT data, typeof(data) AS t, length(data) AS n FROM driver_blob",
		});
		expect(row?.t).toBe("blob");
		expect(row?.n).toBe(4);
		expect(row?.data).toBeInstanceOf(ArrayBuffer);
		expect(Array.from(new Uint8Array(row?.data as ArrayBuffer))).toEqual([1, 2, 255, 0]);
	});

	it("refuses to name or tune its own engine: no PRAGMA, no sqlite_version()", async () => {
		const sql = sqlite("sqlite-driver");
		// Fact 5. The sandbox answers both with a refusal rather than a value, which is why the
		// absence is the contract: a statement here that returns a row would be a backend change.
		await expect(sql.all({ sql: "PRAGMA journal_mode" })).rejects.toThrow(/SQLITE_AUTH/);
		await expect(sql.all({ sql: "SELECT sqlite_version() AS v" })).rejects.toThrow(
			/not authorized to use function/,
		);
	});
});
