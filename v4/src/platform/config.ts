// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
// The config loader (plan §5 S0.3): the single defaults source the settings seed reads.
//
// It is a port of `config_defaults()` plus the normalisation half of `config_load()` in
// modules/Core/Config/config-functions.php, limited to the values a *setting default* can read
// (`cfg:<path>`, `cfg_auth`). Precedence is the same: built-in default < environment, and an empty
// environment variable is ignored, so a compose file's `APP_PASSWORD: ${APP_PASSWORD:-}` never
// wipes a value.
//
// What is deliberately not here: database and storage wiring. The driver is chosen by the binding
// and `DB_DRIVER` (§10), and file storage is the `Blobs` adapter — neither is an app setting.

import type { ReadConsistency } from "../db/rqlite";
import type { ConfigVars, DatabaseVars } from "../env";

export interface AppConfig {
	/** `APOTHEKE_HOSTED`: sign-in is on for a hosted installation even without APP_PASSWORD. */
	readonly hosted: boolean;
	readonly appPassword: string;
	/** Trailing `/` removed, as `config_load()` does. */
	readonly publicUrl: string;
	readonly currency: string;
	readonly timezone: string;
	readonly pageSize: number;
	readonly expirySoonDays: number;
	/** `Config\Consent::$required`: false when COOKIE_CONSENT=off, and then every request carries consent. */
	readonly consentRequired: boolean;
	readonly uploads: {
		readonly maxMb: number;
		readonly fullMax: number;
		readonly thumbMax: number;
	};
}

/** The built-in defaults, from `config_defaults()`. Only the keys a setting default reads. */
export const CONFIG_DEFAULTS = {
	hosted: false,
	appPassword: "",
	publicUrl: "",
	currency: "EUR",
	timezone: "UTC",
	pageSize: 60,
	expirySoonDays: 30,
	consentRequired: true,
	uploads: { maxMb: 20, fullMax: 1600, thumbMax: 480 },
} as const;

/** An empty or absent variable is not a value (`config_load()` skips it). */
const given = (raw: string | undefined): string | null => {
	if (raw === undefined || raw.trim() === "") {
		return null;
	}
	return raw;
};

/** PHP's `(int)$v`: a leading integer, or 0 when there is none. */
const leadingInt = (raw: string): number => {
	const match = /^\s*[+-]?\d+/.exec(raw);
	return match === null ? 0 : Number.parseInt(match[0], 10);
};

/** `max(1, (int)$v)` with the fallback used when the variable is absent or empty. */
const positiveInt = (raw: string | undefined, fallback: number): number => {
	const v = given(raw);
	return Math.max(1, v === null ? fallback : leadingInt(v));
};

/** `filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true`. */
const flag = (raw: string | undefined): boolean => {
	const v = given(raw);
	return v !== null && ["1", "true", "on", "yes"].includes(v.trim().toLowerCase());
};

/** `mb_substr(trim($v), 0, 8) ?: 'EUR'` — code points, not bytes. */
const currency = (raw: string | undefined): string => {
	const v = given(raw);
	if (v === null) {
		return CONFIG_DEFAULTS.currency;
	}
	const trimmed = v.trim();
	return [...trimmed].slice(0, 8).join("") || CONFIG_DEFAULTS.currency;
};

export const loadConfig = (env: ConfigVars): AppConfig => ({
	hosted: flag(env.APOTHEKE_HOSTED),
	appPassword: env.APP_PASSWORD ?? "",
	publicUrl: (env.PUBLIC_URL ?? "").replace(/\/+$/, ""),
	currency: currency(env.CURRENCY),
	timezone: given(env.TZ) ?? CONFIG_DEFAULTS.timezone,
	pageSize: positiveInt(env.PAGE_SIZE, CONFIG_DEFAULTS.pageSize),
	// `min(3650, max(1, (int)$v))`, the one config value PHP clamps in both directions.
	expirySoonDays: Math.min(3650, positiveInt(env.EXPIRY_SOON_DAYS, CONFIG_DEFAULTS.expirySoonDays)),
	// `Consent::requiredFrom()`: anything but `off` (case and space ignored) requires the cookie.
	consentRequired: (given(env.COOKIE_CONSENT) ?? "").trim().toLowerCase() !== "off",
	uploads: {
		maxMb: positiveInt(env.MAX_UPLOAD_MB, CONFIG_DEFAULTS.uploads.maxMb),
		fullMax: positiveInt(env.IMAGE_FULL_MAX, CONFIG_DEFAULTS.uploads.fullMax),
		thumbMax: positiveInt(env.IMAGE_THUMB_MAX, CONFIG_DEFAULTS.uploads.thumbMax),
	},
});
/**
 * How the app reaches its database (§10). One place decides, so no handler ever reads `DB_DRIVER`:
 * hosted D1 (`d1`), the self-host default over HTTP (`rqlite`), or the single process's own SQLite
 * (`sqlite`). `loadDatabaseConfig()` validates the whole contract once, at boot — a malformed
 * `RQLITE_URL` fails closed there rather than falling back to another driver later.
 */
export type DatabaseConfig =
	| { readonly mode: "d1" }
	| { readonly mode: "sqlite" }
	| {
			readonly mode: "rqlite";
			/** `http(s)://host:port`, no trailing slash, no query and no fragment. */
			readonly url: string;
			readonly auth: { readonly username: string; readonly password: string } | null;
			readonly readConsistency: ReadConsistency;
	  };

export class DatabaseConfigError extends Error {
	constructor(message: string) {
		super(message);
		this.name = "DatabaseConfigError";
	}
}

const READ_CONSISTENCY: readonly ReadConsistency[] = ["weak", "strong", "none"];

/** `RQLITE_URL` → the base URL, or a message an operator can act on. */
const rqliteUrl = (raw: string): string => {
	let url: URL;
	try {
		url = new URL(raw.trim());
	} catch {
		throw new DatabaseConfigError("RQLITE_URL is not a URL: expected http(s)://host:4001.");
	}
	if (url.protocol !== "http:" && url.protocol !== "https:") {
		throw new DatabaseConfigError(`RQLITE_URL must be http or https, not ${url.protocol}`);
	}
	if (url.search !== "" || url.hash !== "") {
		throw new DatabaseConfigError(
			"RQLITE_URL carries no query or fragment: the driver adds its own (level, blob_array, transaction).",
		);
	}

	return `${url.origin}${url.pathname.replace(/\/+$/, "")}`;
};

/** `RQLITE_AUTH` is the `username:password` from rqlite's `-auth` file; the password may contain `:`. */
const rqliteAuth = (raw: string | undefined): { username: string; password: string } | null => {
	const value = given(raw);
	if (value === null) {
		return null;
	}
	const separator = value.indexOf(":");
	if (separator <= 0) {
		throw new DatabaseConfigError("RQLITE_AUTH must be username:password, as in rqlite's -auth file.");
	}

	return { username: value.slice(0, separator), password: value.slice(separator + 1) };
};

const readConsistency = (raw: string | undefined): ReadConsistency => {
	const value = given(raw)?.trim().toLowerCase() ?? "weak";
	if (!READ_CONSISTENCY.includes(value as ReadConsistency)) {
		throw new DatabaseConfigError(`RQLITE_READ_CONSISTENCY=${value} is not weak, strong or none.`);
	}

	return value as ReadConsistency;
};

/**
 * The database contract (§10): `DB_DRIVER` names the backend, and an unset driver is inferred —
 * `rqlite` when `RQLITE_URL` is set (the self-host default), `d1` otherwise (hosted, where the binding
 * is the selector). Anything else is a refusal, never a fallback: an install that reached the wrong
 * database is worse than one that did not start.
 */
export const loadDatabaseConfig = (env: DatabaseVars): DatabaseConfig => {
	const url = given(env.RQLITE_URL);
	const driver = given(env.DB_DRIVER)?.trim().toLowerCase() ?? (url === null ? "d1" : "rqlite");

	if (driver === "d1") {
		return { mode: "d1" };
	}
	if (driver === "sqlite") {
		return { mode: "sqlite" };
	}
	if (driver === "rqlite") {
		if (url === null) {
			throw new DatabaseConfigError(
				"DB_DRIVER=rqlite needs RQLITE_URL (http://host:4001) — the app will not guess a database.",
			);
		}

		return {
			mode: "rqlite",
			url: rqliteUrl(url),
			auth: rqliteAuth(env.RQLITE_AUTH),
			readConsistency: readConsistency(env.RQLITE_READ_CONSISTENCY),
		};
	}

	throw new DatabaseConfigError(`DB_DRIVER=${driver} is not a driver: use d1, rqlite or sqlite.`);
};
