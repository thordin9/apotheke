// What a rendered page needs from the settings table (plan §5 S1.1), read once per request.
//
// The PHP app reads settings per key through `SettingsService`; the Worker reads them in one query,
// because one round trip to rqlite or D1 costs what sixty do not. The *decisions* are the PHP ones,
// ported rather than re-invented:
//
//   signInEnabled    hosted ? true : auth_enabled && (APP_PASSWORD set || an account exists)
//   registrationOpen signInEnabled && !auth_register_disabled && !(hosted && no account yet)
//   guestBrowsing    signInEnabled && !auth_guest_disabled
//
// and the shell's own values (`app_name`, `app_tagline`, `accent`, `theme_default`). The theme is
// the `theme` cookie when it holds a valid preference, else `theme_default`; there is no cookie until
// S1.2 stores one, so this reads the setting — the same value a first visit gets from v3.

import { decodeSetting, type SettingValue } from "./db/settings-schema";
import type { Sql } from "./db/sql";
import type { PageSettings } from "./html/page";
import type { AppConfig } from "./platform/config";

/** The shell's values plus the two decisions the login view asks for. */
export interface PageData {
	readonly page: PageSettings;
	readonly signInEnabled: boolean;
	readonly registrationOpen: boolean;
	readonly guestBrowsing: boolean;
}

const text = (value: SettingValue): string => (typeof value === "string" ? value : "");
const flag = (value: SettingValue): boolean => value === true;

/** One query for every settings row, then PHP's decisions over the decoded values. */
export const readPageData = async (sql: Sql, config: AppConfig, requestUri: string): Promise<PageData> => {
	const rows = await sql.all({ sql: "SELECT name, value FROM settings" });
	const values = new Map<string, SettingValue>();
	for (const row of rows) {
		const name = row.name;
		if (typeof name === "string") {
			values.set(name, decodeSetting(typeof row.value === "string" ? row.value : null));
		}
	}

	let signInEnabled: boolean;
	if (config.hosted) {
		signInEnabled = true;
	} else if (!flag(values.get("auth_enabled") ?? null)) {
		signInEnabled = false;
	} else if (config.appPassword !== "") {
		signInEnabled = true;
	} else {
		const counted = await sql.all({ sql: "SELECT COUNT(*) AS n FROM users" });
		const count = counted[0]?.n;
		signInEnabled = typeof count === "number" && count > 0;
	}

	const registrationOpen =
		signInEnabled &&
		!flag(values.get("auth_register_disabled") ?? null) &&
		!(config.hosted && !(await hasAccounts(sql)));

	const theme = text(values.get("theme_default") ?? null) || "auto";

	return {
		page: {
			appName: text(values.get("app_name") ?? null),
			tagline: text(values.get("app_tagline") ?? null),
			theme: theme === "light" || theme === "dark" ? theme : "auto",
			accent: text(values.get("accent") ?? null),
			// No session exists until S1.2, so there is no token to print; the shell prints the empty
			// attribute v3 prints for a first visit, and the golden test normalises it either way.
			csrf: "",
			requestUri,
			// No consent cookie until S1.2: the first-visit render, with the notice shown.
			consent: false,
		},
		signInEnabled,
		registrationOpen,
		guestBrowsing: signInEnabled && !flag(values.get("auth_guest_disabled") ?? null),
	};
};

/** `accountCount() === 0`, only asked by the hosted branch of `registrationOpen`. */
const hasAccounts = async (sql: Sql): Promise<boolean> => {
	const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM users" });
	const count = rows[0]?.n;
	return typeof count === "number" && count > 0;
};
