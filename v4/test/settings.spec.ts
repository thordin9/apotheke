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

import { SELF } from "cloudflare:test";
import { env } from "cloudflare:workers";
import { beforeEach, describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../src/db/do-sql";
import type { Sql } from "../src/db/sql";
import { loadConfig } from "../src/platform/config";
import { consentGiven, readPageData } from "../src/settings";
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

	it("is off when the stored setting is off, whatever APP_PASSWORD says", async () => {
		await store("auth_enabled", "false");
		await account("ada");
		expect(await signIn(PASSWORD)).toBe(false);
		expect(await signIn("")).toBe(false);
	});

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

// `CookieConsent::request()` (modules/Core/Http/CookieConsent.php:49-64): the exact cookie name and
// one of the accepted values, attributes ignored, and COOKIE_CONSENT=off makes every request consented.
describe("consentGiven", () => {
	it("reads the exact cookie with an accepted value", () => {
		expect(consentGiven("apotheke_consent=v1")).toBe(true);
		expect(consentGiven("theme=dark; apotheke_consent=v1; x=1")).toBe(true);
		expect(consentGiven("apotheke_consent=v2")).toBe(false);
		expect(consentGiven("apotheke_consent=")).toBe(false);
		expect(consentGiven(null)).toBe(false);
	});

	it("does not accept another cookie that merely contains the name", () => {
		expect(consentGiven("not_apotheke_consent=v1")).toBe(false);
		expect(consentGiven("apotheke_consent_extra=v1")).toBe(false);
	});

	it("treats COOKIE_CONSENT=off as consent for every request", () => {
		expect(consentGiven(null, false)).toBe(true);
		expect(consentGiven("apotheke_consent=v2", false)).toBe(true);
	});

	it("is what readPageData prints into the page: the notice is gone once the cookie is there", async () => {
		const sql = sqlite("s1.7-consent-page");
		await resetSchema(sql);
		await sql.batch([{ sql: SETTINGS_TABLE }]);
		const config = loadConfig({ APP_PASSWORD: PASSWORD });
		expect((await readPageData(sql, config, "/", false)).page.consent).toBe(false);
		expect((await readPageData(sql, config, "/", true)).page.consent).toBe(true);
	});
});

// `Theme::pref()` (modules/Layout/Theme.php:19-22, called from `Page::theme()`, `Page.php:57-60`) and
// `Theme::valid()` (`:25-28`): the cookie's value when it is one of `auto` | `light` | `dark`, else the
// `theme_default` row, which v4 clamps to the same three. `POST /theme` is P2, so nothing in v4 writes
// the cookie — the cases below set the header the client would send (D152).
describe("the theme: Theme::pref() over Theme::valid()", () => {
	/**
	 * One database per test, and as many settings rows as a case needs. A fresh install is 36
	 * migrations: three of those per test put `takes the three valid values` at 5053 ms against the
	 * 5000 ms default on the D1 leg (run 45775453850, 2026-11-26), where one install is ~1.2 s and a
	 * settings write is ~40 ms. The work is the install, not the assertion, so the assertion keeps a
	 * five-second budget by not paying for installs it does not need.
	 */
	const withSetting = async (name: string) => {
		const sql = sqlite(`s1.7-theme-${name}`);
		await resetSchema(sql);
		await sql.batch([{ sql: SETTINGS_TABLE }]);
		const config = loadConfig({ APP_PASSWORD: PASSWORD });
		return async (stored: string, cookies: string | null = null): Promise<string> => {
			await sql.batch([
				{ sql: "DELETE FROM settings WHERE name = ?", params: ["theme_default"] },
				{
					sql: "INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)",
					params: ["theme_default", JSON.stringify(stored), NOW],
				},
			]);
			return (await readPageData(sql, config, "/", false, cookies)).page.theme;
		};
	};

	it("takes the three valid values", async () => {
		const theme = await withSetting("values");
		expect(await theme("auto")).toBe("auto");
		expect(await theme("light")).toBe("light");
		expect(await theme("dark")).toBe("dark");
	});

	it("answers auto for anything else, case included", async () => {
		const theme = await withSetting("invalid");
		expect(await theme("Dark")).toBe("auto");
		expect(await theme("solarized")).toBe("auto");
		expect(await theme("")).toBe("auto");
	});

	it("takes the cookie over the setting, and the setting when the cookie is no better", async () => {
		// `auto` as the setting on purpose: a cookie matched case-insensitively or by prefix would answer
		// `light`/`dark` and be caught here, where a `dark` setting would let `Dark` pass as its own
		// fallback.
		const theme = await withSetting("cookie");
		expect(await theme("auto", "theme=light")).toBe("light");
		expect(await theme("auto", "theme=dark")).toBe("dark");
		expect(await theme("auto", "theme=auto")).toBe("auto");
		// Not one of the three, so the setting answers: `Theme::pref()` returns its default for every
		// other string, and v3 compared with `in_array(..., true)`.
		expect(await theme("auto", "theme=Dark")).toBe("auto");
		expect(await theme("auto", "theme=solarized")).toBe("auto");
		expect(await theme("auto", "theme=")).toBe("auto");
		expect(await theme("auto", "theme=lightx")).toBe("auto");
		expect(await theme("auto", "themes=light")).toBe("auto");
		// Among other cookies, in either position.
		expect(await theme("auto", "apotheke_consent=v1; theme=light")).toBe("light");
		expect(await theme("auto", "theme=light; apotheke_consent=v1")).toBe("light");
		// And the setting still answers when the cookie is absent.
		expect(await theme("dark", null)).toBe("dark");
	});

	it("reaches the shell through the Worker, so the entrypoint passes the header on", async () => {
		// The unit cases above call `readPageData`; this one goes through `src/index.ts`, which is where
		// the cookie header is handed over — the wiring a unit test cannot see (D152).
		const plain = await SELF.fetch("http://localhost/login");
		expect(plain.status).toBe(200);
		expect(await plain.text()).toContain('<html lang="en" data-theme="auto"');

		const dark = await SELF.fetch("http://localhost/login", { headers: { cookie: "theme=dark" } });
		expect(dark.status).toBe(200);
		expect(await dark.text()).toContain('<html lang="en" data-theme="dark"');

		// An invalid value is not a theme: the setting answers, as `Theme::pref()` says.
		const bad = await SELF.fetch("http://localhost/login", { headers: { cookie: "theme=solarized" } });
		expect(await bad.text()).toContain('<html lang="en" data-theme="auto"');
	});
});
