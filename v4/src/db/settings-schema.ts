// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
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

/**
 * The fields the Users & sign-in form edits, with the labels, types, help and options PHP's
 * `SettingsSchema::DEFINITIONS` carries for them (`sectionKeys('security')` without the protected
 * `auth_enabled` and `password_hash`). `SETTING_SPECS` above still owns the defaults — that table is
 * the one place a default is spelled — and this one is only what the form prints.
 *
 * The rendered result is pinned, not this table: `test/fixtures/php-security.json` is the output of
 * the real PHP view, so a label or a help line that moves here without moving there is a red test.
 */
export interface SectionField {
	readonly key: string;
	readonly label: string;
	readonly type: string;
	readonly help: string;
	readonly options: Readonly<Record<string, unknown>>;
}

export const SECURITY_SECTION_FIELDS: readonly SectionField[] = [
	{
		key: "auth_guest_disabled",
		label: "Disable guest browsing",
		type: "bool",
		help: "On: visitors must sign in before they see anything. Off: they can look around with the permissions of the Guest role.",
		options: {},
	},
	{
		key: "auth_register_disabled",
		label: "Disable registrations",
		type: "bool",
		help: "Off: anyone who can reach this address can create an account at /register (limited to 5 per hour per address).",
		options: {},
	},
	{
		key: "auth_register_role",
		label: "Role for new registrations",
		type: "role",
		help: "Admin cannot be chosen.",
		options: {},
	},
	{
		key: "auth_register_approval",
		label: "Registrations need an admin\u2019s approval",
		type: "bool",
		help: "New accounts can only sign in once an admin approves them under Users.",
		options: {},
	},
	{
		key: "session_days",
		label: "Stay signed in for (days)",
		type: "int",
		help: "The longest a sign-in lasts, counted from sign-in. Later visits do not extend it. The server ends it when this time has passed.",
		options: { min: 1, max: 365 },
	},
	{
		key: "session_idle_hours",
		label: "Sign out after idle (hours)",
		type: "int",
		help: "End a sign-in after this many hours with no request. 1\u20138760 (365 days). The stay-signed-in limit above still applies.",
		options: { min: 1, max: 8760 },
	},
	{
		key: "trust_proxy",
		label: "Trust the reverse proxy",
		type: "bool",
		help: "Turn on only when the app sits behind your own HTTPS proxy (nginx, Caddy, Traefik) that sets X-Forwarded-Proto. Then sign-in cookies are marked Secure. Leave off when the app is reachable directly.",
		options: {},
	},
];
