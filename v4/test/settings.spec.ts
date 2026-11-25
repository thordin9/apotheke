// S1.7's port of `AuthStatusD1Test` (plan D37): "is sign-in on?" — the question v3's entrypoint asked
// through `auth status` before it started, and the one v4 answers once per request in `readPageData()`.
//
// The decision itself is `SettingsService::signInEnabled()` (modules/Settings/SettingsService.php:291)
// and the port keeps its order and its short-circuits: hosted is always on, even before the first
// admin claims the installation; otherwise the stored `auth_enabled` row decides; and only then
// APP_PASSWORD, with the account count read *only* when no password is set.
//
// What is not here, and where it went: v3's four exit codes are the CLI's. "Off" (3) and "on" (0) are
// the boolean asserted below; "APP_PASSWORD too short" (4) refuses the seed in v4
// (`appPasswordProblem`, test/seed.spec.ts) and surfaces as a 503 from readiness; "no database" (1)
// is readiness' `database` problem (test/readiness.spec.ts). Those two are that row's other half and
// are ported where the behaviour lives, not copied into a command v4 does not have.
//
// The count short-circuit is asserted by watching the SQL, not by a mock library: the last case wraps
// the real `Sql`, answers the "FROM users" read with a failure, and shows the answer still stands when
// APP_PASSWORD is set — and that the read does happen when it is not.

import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../src/db/do-sql";
import type { Sql } from "../src/db/sql";
import { loadConfig } from "../src/platform/config";
import { readPageData } from "../src/settings";
import { SLOW_TEST_MS } from "./helpers/deadline";
import { resetSchema } from "./helpers/schema";

const NOW = "2026-01-01 00:00:00";
const PASSWORD = "long-enough";

const SETTINGS_TABLE =
	"CREATE TABLE IF NOT EXISTS settings (name TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)";
const USERS_TABLE = `CREATE TABLE IF NOT EXISTS users (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	username      TEXT NOT NULL,
	username_key  TEXT NOT NULL UNIQUE,
	password_hash TEXT NOT NULL,
	role          TEXT NOT NULL,
	created_at    TEXT NOT NULL,
	last_login_at TEXT,
	status        TEXT NOT NULL DEFAULT 'active',
	session_epoch TEXT NOT NULL DEFAULT ''
)`;

const sqlite = (name: string): Sql =>
	durableObjectSql(env.INSTALLATION.get(env.INSTALLATION.idFromName(name)) as unknown as InstallationStub);

describe("the boot's sign-in decision (AuthStatusD1Test ported)", () => {
	let sql: Sql;

	beforeEach(async () => {
		sql = sqlite("s1.7-signin-state");
		await resetSchema(sql);
		await sql.batch([{ sql: SETTINGS_TABLE }, { sql: USERS_TABLE }]);
	});

	const store = (name: string, value: string): Promise<unknown> =>
		sql.batch([
			{ sql: "INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)", params: [name, value, NOW] },
		]);

	const account = (name: string): Promise<unknown> =>
		sql.batch([
			{
				sql: "INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?,?,'x','admin','active',?)",
				params: [name, name, NOW],
			},
		]);

	const data = (appPassword: string, hosted = false, from: Sql = sql) =>
		readPageData(from, loadConfig({ APP_PASSWORD: appPassword, APOTHEKE_HOSTED: hosted ? "true" : "" }), "/");

	const signIn = async (appPassword: string, hosted = false, from: Sql = sql): Promise<boolean> => {
		const page = await data(appPassword, hosted, from);
		expect(page.page.signInEnabled).toBe(page.signInEnabled);
		return page.signInEnabled;
	};

	it(
		"is off when the stored setting is off, whatever APP_PASSWORD says",
		async () => {
			await store("auth_enabled", "false");
			await account("ada");
			expect(await signIn(PASSWORD)).toBe(false);
			expect(await signIn("")).toBe(false);
			// 1554 ms alone here, 4110 ms when three new specs boot together: the first case boots this spec's
			// installation (36 migrations plus the seed's bcrypt).
		},
		SLOW_TEST_MS,
	);

	it("is on for a stored setting that is on, with APP_PASSWORD alone and no account", async () => {
		await store("auth_enabled", "true");
		expect(await signIn(PASSWORD)).toBe(true);
	});

	it("is off with no account and no APP_PASSWORD, and on once an account exists", async () => {
		await store("auth_enabled", "true");
		expect(await signIn("")).toBe(false);
		await account("ada");
		expect(await signIn("")).toBe(true);
	});

	it("is on for a hosted installation before anyone has claimed it", async () => {
		// With no settings row at all: a hosted install is on regardless, because the first admin
		// arrives through the claim link rather than through APP_PASSWORD.
		expect(await signIn("", true)).toBe(true);
		await store("auth_enabled", "false");
		expect(await signIn("", true)).toBe(true);
		expect(await signIn(PASSWORD, true)).toBe(true);
	});

	it("reads the account count only when APP_PASSWORD is empty", async () => {
		// v3's short-circuit: "APP_PASSWORD first: then the account count is not read at all".
		await store("auth_enabled", "true");
		const asked: string[] = [];
		const counting: Sql = {
			all: async (query) => {
				if (query.sql.includes("FROM users")) {
					asked.push(query.sql);
					throw new Error("the account count was read with APP_PASSWORD set");
				}
				return sql.all(query);
			},
			batch: (statements) => sql.batch(statements),
		};

		expect(await signIn(PASSWORD, false, counting)).toBe(true);
		expect(asked).toEqual([]);
		await expect(signIn("", false, counting)).rejects.toThrow("the account count was read");
		expect(asked.length).toBe(1);
	});
});
