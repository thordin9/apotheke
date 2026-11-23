// Row-level seed parity (plan §5 S0.3): the schema comparison proves the tables are the same shape;
// this proves the rows a fresh install ends with are the same rows. Same fixture as the schema test —
// `v4/test/fixtures/php-schema-dump.php` migrates a throwaway SQLite database with the app's own
// runner, so `seed_rows` IS what PHP writes, with its clock pinned to 2026-11-23T12:00:00Z.
//
// One column cannot match byte for byte: bcrypt salts the digest, so the fixture records
// `password_hash` as a marker and the real hash is verified against the password instead. The test
// says which row that is, rather than normalising quietly.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { D1Sql } from "../src/db/d1";
import { durableObjectSql, type InstallationStub } from "../src/db/do-sql";
import { runMigrations } from "../src/db/migration-runner";
import { runSeeds } from "../src/db/seed";
import type { Sql } from "../src/db/sql";
import { loadConfig } from "../src/platform/config";
import { verifyPassword } from "../src/platform/passwords";
import fixture from "./fixtures/php-v4.0.0-schema.json";
import { resetSchema } from "./helpers/schema";

const CLOCK = "2026-11-23 12:00:00";
const config = loadConfig({
	APP_PASSWORD: fixture.seed_config.app_password,
	TZ: fixture.seed_config.timezone,
});

const d1 = (): Sql => new D1Sql(env.DB);
const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

const seedFresh = async (sql: Sql): Promise<void> => {
	const run = await runMigrations(sql, { now: CLOCK });
	expect(run.fresh).toBe(true);
	await runSeeds(sql, { config, now: CLOCK, fresh: run.fresh });
};

const readSettings = async (sql: Sql) =>
	(await sql.all({ sql: "SELECT name, value, updated_at FROM settings ORDER BY name" })).map((r) => ({
		name: String(r.name),
		value: String(r.value ?? ""),
		updated_at: String(r.updated_at),
	}));

const readRoles = async (sql: Sql) =>
	(
		await sql.all({ sql: "SELECT role_key, name, perms, builtin, created_at FROM roles ORDER BY role_key" })
	).map((r) => ({
		role_key: String(r.role_key),
		name: String(r.name),
		perms: String(r.perms),
		builtin: Number(r.builtin),
		created_at: String(r.created_at),
	}));

describe("row parity with the PHP seed (G0, rows)", () => {
	beforeEach(async () => {
		await resetSchema(d1());
		await resetSchema(sqlite("seed-parity"));
	});

	it("writes the same 64 settings rows PHP writes, on D1", async () => {
		const sql = d1();
		await seedFresh(sql);

		expect(await readSettings(sql)).toEqual(fixture.seed_rows.settings);
	});

	it("writes the same 64 settings rows PHP writes, on local DO SQLite", async () => {
		const sql = sqlite("seed-parity");
		await seedFresh(sql);

		expect(await readSettings(sql)).toEqual(fixture.seed_rows.settings);
	});

	it("writes the same roles rows PHP writes, on D1", async () => {
		const sql = d1();
		await seedFresh(sql);

		expect(await readRoles(sql)).toEqual(fixture.seed_rows.roles);
	});

	it("writes the same roles rows PHP writes, on local DO SQLite", async () => {
		const sql = sqlite("seed-parity");
		await seedFresh(sql);

		expect(await readRoles(sql)).toEqual(fixture.seed_rows.roles);
	});

	it("creates the same first admin, with a hash of the same password", async () => {
		const sql = sqlite("seed-parity");
		await seedFresh(sql);

		const rows = await sql.all({
			sql: "SELECT username, username_key, role, created_at, password_hash FROM users",
		});
		expect(rows).toHaveLength(fixture.seed_rows.users.length);
		const mine = rows[0];
		const php = fixture.seed_rows.users[0];
		expect(mine?.username).toBe(php?.username);
		expect(mine?.username_key).toBe(php?.username_key);
		expect(mine?.role).toBe(php?.role);
		expect(mine?.created_at).toBe(php?.created_at);
		// The one value that cannot match: bcrypt salts every digest.
		expect(String(mine?.password_hash)).toMatch(/^\$2y\$12\$/);
		expect(await verifyPassword(fixture.seed_config.app_password, String(mine?.password_hash))).toBe(true);
	});
});
