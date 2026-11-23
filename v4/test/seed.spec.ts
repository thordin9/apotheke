// The seed phase (plan §5 S0.3, decision 2): what a fresh install owes, what an upgrade does not
// owe again, and the two facts the phase-0 report was told to prove about 0018's adopt path.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../src/db/do-sql";
import { runMigrations, SEED_STAMP, seedState } from "../src/db/migration-runner";
import { MIGRATIONS } from "../src/db/migrations";
import { appPasswordProblem, FRESH_INSTALL_ROLE_PERMS, runSeeds, SeedRefusedError } from "../src/db/seed";
import { decodeSetting, SETTING_KEYS } from "../src/db/settings-schema";
import { type Sql, statement } from "../src/db/sql";
import { loadConfig } from "../src/platform/config";
import { verifyPassword } from "../src/platform/passwords";
import { resetSchema } from "./helpers/schema";

const CLOCK = "2026-11-23 12:00:00";
const PASSWORD = "correct horse battery staple 2026";

const config = loadConfig({ APP_PASSWORD: PASSWORD, TZ: "UTC" });

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

const settingRows = async (sql: Sql) =>
	new Map(
		(await sql.all({ sql: "SELECT name, value FROM settings" })).map((r) => [
			String(r.name),
			String(r.value ?? ""),
		]),
	);

describe("the seed phase", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = sqlite("seed");
		await resetSchema(sql);
	});

	it("0018's adopt path is unreachable on a fresh v4 install, and reads no configuration", async () => {
		// A short APP_PASSWORD would make settings_seed() refuse. The migration run does not care:
		// 0018 only reads `settings`, which is empty here because the settings rows are deferred, so
		// its adopt path is a no-op and no configuration is consulted.
		const run = await runMigrations(sql, { now: CLOCK });
		expect(run.fresh).toBe(true);

		const users = await sql.all({ sql: "SELECT COUNT(*) AS n FROM users" });
		expect(Number(users[0]?.n)).toBe(0);
		const settings = await sql.all({ sql: "SELECT COUNT(*) AS n FROM settings" });
		expect(Number(settings[0]?.n)).toBe(0);

		// And the migration step itself cannot read configuration: `MigrationContext` is `now` and
		// `all()` only, which is why a short password cannot stop the schema from building.
		const step = MIGRATIONS.find((m) => m.version === "0018");
		expect(step?.plan).toBeTypeOf("function");
		await expect(
			runSeeds(sql, { config: loadConfig({ APP_PASSWORD: "short" }), now: CLOCK, fresh: true }),
		).rejects.toBeInstanceOf(SeedRefusedError);
	});

	it("writes every settings row, the roles lists and the first admin", async () => {
		const run = await runMigrations(sql, { now: CLOCK });
		const seeded = await runSeeds(sql, { config, now: CLOCK, fresh: run.fresh });

		expect(seeded.inserted).toEqual(SETTING_KEYS);
		const rows = await settingRows(sql);
		expect(rows.size).toBe(SETTING_KEYS.length);
		expect(decodeSetting(rows.get("page_size") ?? null)).toBe(60);
		expect(decodeSetting(rows.get("auth_enabled") ?? null)).toBe(true);
		// 0018 clears the key once the hash has become the account "admin".
		expect(decodeSetting(rows.get("password_hash") ?? null)).toBe("");
		expect(seeded.rolesUpdated).toEqual(["editor", "viewer"]);
		expect(seeded.adminCreated).toBe(true);
		expect(await seedState(sql)).toBe("done");

		const users = await sql.all({ sql: "SELECT username, role, password_hash, created_at FROM users" });
		expect(users).toHaveLength(1);
		expect(users[0]?.username).toBe("admin");
		expect(users[0]?.role).toBe("admin");
		expect(users[0]?.created_at).toBe(CLOCK);
		expect(await verifyPassword(PASSWORD, String(users[0]?.password_hash))).toBe(true);

		const roles = new Map(
			(await sql.all({ sql: "SELECT role_key, perms FROM roles" })).map((r) => [
				String(r.role_key),
				String(r.perms),
			]),
		);
		expect(roles.get("editor")).toBe(JSON.stringify(FRESH_INSTALL_ROLE_PERMS.editor));
		expect(roles.get("viewer")).toBe(JSON.stringify(FRESH_INSTALL_ROLE_PERMS.viewer));
	});

	it("is idempotent: a second run writes nothing new and keeps the admin it made", async () => {
		const run = await runMigrations(sql, { now: CLOCK });
		await runSeeds(sql, { config, now: CLOCK, fresh: run.fresh });
		const second = await runSeeds(sql, { config, now: CLOCK, fresh: false });

		expect(second.inserted).toEqual([]);
		expect(second.adminCreated).toBe(false);
		expect(second.rolesUpdated).toEqual([]);
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM settings" });
		expect(Number(rows[0]?.n)).toBe(SETTING_KEYS.length);
		const users = await sql.all({ sql: "SELECT COUNT(*) AS n FROM users" });
		expect(Number(users[0]?.n)).toBe(1);
		expect(await seedState(sql)).toBe("done");
	});

	it("refuses a short password with PHP's message, and writes nothing", async () => {
		await runMigrations(sql, { now: CLOCK });

		await expect(
			runSeeds(sql, { config: loadConfig({ APP_PASSWORD: "short" }), now: CLOCK, fresh: true }),
		).rejects.toThrow(appPasswordProblem("short") ?? "");
		const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM settings" });
		expect(Number(rows[0]?.n)).toBe(0);
		// The stamp is written by the same unit, so it is not there either: /readyz keeps refusing.
		expect(await seedState(sql)).toBe("pending");
	});

	it("keeps the idempotent half on an upgrade and does not re-run the fresh-only replay", async () => {
		// A database that already has an admin and an admin-chosen setting: only a missing key may be
		// inserted; the roles lists and the seeding of autofill_auto must be left alone.
		await runMigrations(sql, { now: CLOCK });
		await sql.batch([
			statement("INSERT INTO settings (name, value, updated_at) VALUES ('autofill_auto','true',?)", [CLOCK]),
		]);
		await sql.batch([
			statement(
				"INSERT INTO users (username, username_key, password_hash, role, created_at) VALUES ('admin','admin','x','admin',?)",
				[CLOCK],
			),
		]);

		const seeded = await runSeeds(sql, { config, now: CLOCK, fresh: false });
		expect(seeded.inserted).toContain("app_name");
		expect(seeded.inserted).not.toContain("autofill_auto");
		expect(seeded.adminCreated).toBe(false);
		expect((await settingRows(sql)).get("autofill_auto")).toBe("true");
	});

	it("appPasswordProblem matches Accounts::appPasswordProblem", () => {
		expect(appPasswordProblem("")).toBeNull();
		expect(appPasswordProblem("        ")).not.toBeNull();
		expect(appPasswordProblem("12345678")).toBeNull();
		expect(appPasswordProblem("1234567")).toContain("at least 8 characters");
		expect(appPasswordProblem("bad\0pass")).toContain("null character");
	});

	it("leaves the seed stamp reachable for the readiness gate", async () => {
		const run = await runMigrations(sql, { now: CLOCK });
		expect(await seedState(sql)).toBe("pending");
		await runSeeds(sql, { config, now: CLOCK, fresh: run.fresh });
		const stamp = await sql.all({
			sql: "SELECT value FROM app_settings WHERE name = ?",
			params: [SEED_STAMP],
		});
		expect(stamp[0]?.value).toBe("done");
	});
});
