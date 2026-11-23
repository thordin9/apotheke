// The database-backed settings and their defaults — a port of the `DEFINITIONS` table in
// modules/Settings/SettingsSchema.php, key for key and default for default.
//
// Only the *defaults* are ported here, because that is what the seed phase needs: PHP's
// `settings_seed()` inserts every key that is missing with `setting_default($k)`. Readers, forms and
// validation arrive with the Control Panel (phase 1); this table is the one place a default is
// spelled, so a later reader has nothing to re-derive.
//
// A default is one of:
//   - a literal, stored as JSON (`null`, `true`, `60`, `"Apotheke"`);
//   - `"cfg:<path>"`: a config value, validated exactly as PHP validates it — an invalid value
//     becomes `null` rather than refusing the install, which is PHP's `setting_default()` rule;
//   - `"cfg_auth"`: sign-in on when APP_PASSWORD is set, or hosted;
//   - `"cfg_hash"`: the APP_PASSWORD hash (the seed hashes it; see seed.ts).

import type { AppConfig } from "../platform/config";

/** What a setting row stores: the JSON encoding of one of these. */
export type SettingValue = string | number | boolean | null;

export type SettingSpec = SettingValue | `cfg:${string}` | "cfg_auth" | "cfg_hash";

/** In PHP's definition order, so a diff against SettingsSchema.php reads straight down. */
export const SETTING_SPECS: readonly (readonly [string, SettingSpec])[] = [
	["app_name", "Apotheke"],
	["app_tagline", "the storehouse for your stuff"],
	["public_url", "cfg:public_url"],
	["accent", "#5b3b8e"],
	["theme_default", "auto"],
	["icon_background", "#FBF1E3"],
	["logo", ""],
	["currency", "cfg:currency"],
	["money_format", "en"],
	["currency_position", "after"],
	["date_format", "Y-m-d"],
	["time_format", "H:i"],
	["timezone", "cfg:timezone"],
	["page_size", "cfg:page_size"],
	["default_sort", "recent"],
	["list_view", "grid"],
	["recent_limit", 8],
	["recent_scope", "device"],
	["search_typos", true],
	["overview_open", true],
	["default_quantity", 1],
	["default_min_qty", null],
	["default_tags", ""],
	["default_notes", ""],
	["default_purchase_today", false],
	["default_expiry_months", null],
	["label_width_mm", 60],
	["label_height_mm", 70],
	["label_columns", 3],
	["label_gap_mm", 4],
	["label_show_sub", true],
	["label_show_url", true],
	["label_border", true],
	["expiry_soon_days", "cfg:expiry_soon_days"],
	["lend_due_days", null],
	["saved_filters_max", 50],
	["undo_seconds", 30],
	["stocktake_apply_moves", true],
	["max_photos", 12],
	["max_upload_mb", "cfg:uploads.max_mb"],
	["image_full_max", "cfg:uploads.full_max"],
	["image_thumb_max", "cfg:uploads.thumb_max"],
	["jpeg_quality", 82],
	["attach_types", "pdf_images"],
	["attach_max_mb", 10],
	["attach_max_per_item", 10],
	["autofill_enabled", false],
	["autofill_auto", false],
	["autofill_url", ""],
	["autofill_timeout", 50],
	["autofill_model", ""],
	["autofill_image_size", 0],
	["autofill_max_image_bytes", "auto"],
	["autofill_token", ""],
	["auth_enabled", "cfg_auth"],
	["auth_guest_disabled", true],
	["auth_register_disabled", true],
	["auth_register_role", "viewer"],
	["auth_register_approval", false],
	["session_days", 30],
	["session_idle_hours", 168],
	["trust_proxy", false],
	["import_create_missing", true],
	["password_hash", "cfg_hash"],
];

export const SETTING_KEYS: readonly string[] = SETTING_SPECS.map(([key]) => key);

/** The stored form of a value: PHP's `json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`. */
export const encodeSetting = (value: SettingValue): string => JSON.stringify(value);

/** The stored text back to a value; an unreadable row reads as `null`, as PHP's JSON decode does. */
export const decodeSetting = (stored: string | null): SettingValue => {
	if (stored === null) {
		return null;
	}
	try {
		const parsed: unknown = JSON.parse(stored);
		return typeof parsed === "string" || typeof parsed === "number" || typeof parsed === "boolean"
			? parsed
			: null;
	} catch {
		return null;
	}
};

/**
 * The bounds a config-backed default must satisfy, from the `options` of each definition. A value
 * outside them is a `null` default — PHP's `setting_default()` catches the validation error and
 * returns null, so an install with an odd `PAGE_SIZE` seeds `null` rather than failing.
 */
const INT_BOUNDS: Readonly<Record<string, readonly [number, number]>> = {
	page_size: [1, 500],
	max_upload_mb: [1, 200],
	image_full_max: [320, 8000],
	image_thumb_max: [64, 2000],
	expiry_soon_days: [1, 3650],
};

const intWithin = (value: number, [min, max]: readonly [number, number]): number | null =>
	Number.isInteger(value) && value >= min && value <= max ? value : null;

/** `url` validation: rtrim '/', `http(s)://host[/path]`, at most 200 characters, empty is allowed. */
const asUrl = (raw: string): string | null => {
	const value = raw.replace(/\/+$/, "");
	if (value === "") {
		return "";
	}
	return /^https?:\/\/[A-Za-z0-9.\-[\]:]+(\/[^\s]*)?$/.test(value) && value.length <= 200 ? value : null;
};

/** `timezone` validation: a name `DateTimeZone` accepts; an unknown one is null. */
const asTimezone = (raw: string): string | null => {
	if (raw.trim() === "") {
		return null;
	}
	try {
		new Intl.DateTimeFormat("en", { timeZone: raw });
		return raw;
	} catch {
		return null;
	}
};

/** `string` with `max` and `required`: the config value is trimmed and length-capped. */
const asString = (raw: string, max: number): string | null => {
	const value = raw.trim();
	return value !== "" && [...value].length <= max ? value : null;
};

const CONFIG_PATHS: Readonly<Record<string, (config: AppConfig) => SettingValue>> = {
	public_url: (c) => asUrl(c.publicUrl),
	currency: (c) => asString(c.currency, 8),
	timezone: (c) => asTimezone(c.timezone),
	page_size: (c) => intWithin(c.pageSize, INT_BOUNDS.page_size ?? [1, 500]),
	max_upload_mb: (c) => intWithin(c.uploads.maxMb, INT_BOUNDS.max_upload_mb ?? [1, 200]),
	image_full_max: (c) => intWithin(c.uploads.fullMax, INT_BOUNDS.image_full_max ?? [320, 8000]),
	image_thumb_max: (c) => intWithin(c.uploads.thumbMax, INT_BOUNDS.image_thumb_max ?? [64, 2000]),
	expiry_soon_days: (c) => intWithin(c.expirySoonDays, INT_BOUNDS.expiry_soon_days ?? [1, 3650]),
};

/**
 * `setting_default($key, cfg())` for one key. `password_hash` returns `''` here: the seed inserts
 * the hash itself, because hashing is async and only the seed knows the plaintext.
 */
export const settingDefault = (key: string, config: AppConfig): SettingValue => {
	const spec = SETTING_SPECS.find(([k]) => k === key)?.[1];
	if (spec === undefined) {
		return null;
	}
	if (spec === "cfg_auth") {
		// Hosted: sign-in is always on, also before the first admin claims the installation.
		return config.appPassword !== "" || config.hosted;
	}
	if (spec === "cfg_hash") {
		return config.appPassword !== "" ? "" : "";
	}
	if (typeof spec === "string" && spec.startsWith("cfg:")) {
		const read = CONFIG_PATHS[key];
		return read === undefined ? null : read(config);
	}
	return spec;
};

/** Every key's default, as the seed writes them. */
export const allSettingDefaults = (config: AppConfig): ReadonlyMap<string, SettingValue> =>
	new Map(SETTING_SPECS.map(([key]) => [key, settingDefault(key, config)]));
