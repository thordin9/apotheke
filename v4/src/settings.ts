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
	/**
	 * What the session layer derives from the request (S1.2, D20): `trust_proxy` and `public_url` are
	 * **settings rows, not environment names** — v3 reads both through `Services::settings()` and, when
	 * that read throws, falls back to `trust_proxy = false` and the config's `public_url`, so a database
	 * blip degrades to the plain cookie name rather than to `__Host-` on a host that cannot set it.
	 * The read here either succeeds or fails the request (503), so the fallback is `loadConfig()`'s
	 * `public_url` only when the row itself is absent — which is the same value.
	 */
	readonly security: {
		readonly trustProxy: boolean;
		readonly publicUrl: string;
		readonly sessionDays: number;
		readonly sessionIdleHours: number;
	};
}

const text = (value: SettingValue): string => (typeof value === "string" ? value : "");
const flag = (value: SettingValue): boolean => value === true;
/** `SettingsSchema`'s numeric rows arrive as numbers; anything else falls back to its own default. */
const count = (value: SettingValue, fallback: number): number =>
	typeof value === "number" && Number.isFinite(value) ? value : fallback;

/**
 * `CookieConsent::request()`: the cookie by its exact name (`apotheke_consent`) holding an accepted
 * value (`v1`). Attributes never reach the server, so a host-only cookie and one with a Domain both
 * count. COOKIE_CONSENT=off (`required` false) makes every request consented, as in v3.
 */
export const consentGiven = (cookieHeader: string | null, required = true): boolean => {
	if (!required) return true;
	for (const part of (cookieHeader ?? "").split(";")) {
		const at = part.indexOf("=");
		if (at === -1) continue;
		if (part.slice(0, at).trim() !== CONSENT_COOKIE) continue;
		return part.slice(at + 1).trim() === CONSENT_CURRENT;
	}
	return false;
};

/** `Config\Consent::COOKIE` and `::CURRENT`: the first cookie the app ever has. */
/*
 * v3 keeps `ACCEPTED` as an array (`['v1']`) beside `CURRENT`, so a later banner version is a change
 * to that array — code, not a settings row. This port compares against one accepted value, so bumping
 * the banner means teaching `consentGiven` a set here, not editing a config key; nothing in the
 * settings schema carries the accepted versions.
 */
const CONSENT_COOKIE = "apotheke_consent";
const CONSENT_CURRENT = "v1";

/** One query for every settings row, then PHP's decisions over the decoded values. */
export const readPageData = async (
	sql: Sql,
	config: AppConfig,
	requestUri: string,
	consent = false,
): Promise<PageData> => {
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
			// `CookieConsent::request()`: false until the visitor accepts, and then the notice is gone.
			consent,
			// The menu's sign-out branch reads it (`Page::menu()`); same decision as `signInEnabled`.
			signInEnabled,
		},
		signInEnabled,
		registrationOpen,
		guestBrowsing: signInEnabled && !flag(values.get("auth_guest_disabled") ?? null),
		security: {
			trustProxy: flag(values.get("trust_proxy") ?? null),
			// `SettingsSchema`'s `public_url` decodes to a string; an unset row is the config's value,
			// which is the same fallback v3 takes when the settings read throws.
			publicUrl: text(values.get("public_url") ?? null) || config.publicUrl,
			sessionDays: count(values.get("session_days") ?? null, 30),
			sessionIdleHours: count(values.get("session_idle_hours") ?? null, 168),
		},
	};
};

/** `accountCount() === 0`, only asked by the hosted branch of `registrationOpen`. */
const hasAccounts = async (sql: Sql): Promise<boolean> => {
	const rows = await sql.all({ sql: "SELECT COUNT(*) AS n FROM users" });
	const count = rows[0]?.n;
	return typeof count === "number" && count > 0;
};
