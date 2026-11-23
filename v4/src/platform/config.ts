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

import type { ConfigVars } from "../env";

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
	uploads: {
		maxMb: positiveInt(env.MAX_UPLOAD_MB, CONFIG_DEFAULTS.uploads.maxMb),
		fullMax: positiveInt(env.IMAGE_FULL_MAX, CONFIG_DEFAULTS.uploads.fullMax),
		thumbMax: positiveInt(env.IMAGE_THUMB_MAX, CONFIG_DEFAULTS.uploads.thumbMax),
	},
});
