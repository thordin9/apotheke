// The seed phase (plan §5 S0.3, decision 2 of the phase-0 report).
//
// The schema steps deliberately did not write configuration rows: those read configuration and, in
// one case, a password hash. They are replayed here, in one unit of work, in the order PHP applied
// them, so a fresh v4 install ends with the same rows a fresh PHP install has:
//
//   0013 settings_seed()      every settings key that is missing, with its default
//   0018's adopt path         settings.password_hash → the account "admin", then the key is cleared
//   0022 FreshInstallRoles    the editor and viewer permission lists
//   0023 settings_seed()      (autofill keys — covered by the pass above, which uses current defaults)
//   0024–0027 row writes      (their values are current defaults on a fresh install, so no-op here)
//
// Two rules that are not obvious:
//   - The replay is gated on `fresh`. A v3.72 database already owes none of it, and 0025's
//     "force autofill_auto off" would otherwise overrule an admin's choice on every upgrade. Only
//     the idempotent half — insert the keys that are missing — runs everywhere, which is what
//     PHP's settings_seed() does at its last settings migration.
//   - A short APP_PASSWORD refuses the seed (PHP's `appPasswordProblem()`), which the readiness
//     gate turns into a 503 with the reason: louder than seeding a password nobody can type.

import type { AppConfig } from "../platform/config";
import { hashPassword } from "../platform/passwords";
import { SEED_DONE, SEED_STAMP } from "./migration-runner";
import { allSettingDefaults, decodeSetting, encodeSetting } from "./settings-schema";
import type { Sql, Statement } from "./sql";

/** `Accounts::PASSWORD_MIN`. */
export const PASSWORD_MIN = 8;

/** The PHP message, verbatim, so an operator sees the same line on both runtimes. */
export const appPasswordProblem = (password: string): string | null => {
	if (password.includes("\0")) {
		return "APP_PASSWORD cannot contain a null character.";
	}
	if (password === "" || (password.trim() !== "" && [...password].length >= PASSWORD_MIN)) {
		return null;
	}
	return `APP_PASSWORD is too short: use at least ${PASSWORD_MIN} characters (not only spaces), or leave it empty.`;
};

/** `FreshInstallRoles::PERMS`, in `Permissions::stored()` form. */
export const FRESH_INSTALL_ROLE_PERMS: Readonly<Record<string, readonly string[]>> = {
	editor: [
		"view",
		"create",
		"edit",
		"delete",
		"move",
		"bulk",
		"lend",
		"import",
		"export",
		"stocktake",
		"labels",
		"backup",
	],
	viewer: ["view", "export", "labels", "backup-only"],
};

/** The seed refused to run; the message is shown to the operator on `/readyz`. */
export class SeedRefusedError extends Error {
	constructor(message: string) {
		super(message);
		this.name = "SeedRefusedError";
	}
}

export interface SeedOptions {
	readonly config: AppConfig;
	/** The stored UTC form, from the injected clock. */
	readonly now: string;
	/** True when `schema_migrations` was empty before this install's migration run. */
	readonly fresh: boolean;
	/** Test seam for the hash function; production uses bcryptjs. */
	readonly hash?: (plain: string) => Promise<string>;
}

export interface SeedRun {
	/** Settings keys this run inserted, in definition order. */
	readonly inserted: readonly string[];
	/** Roles whose permission list was re-applied (fresh installs only). */
	readonly rolesUpdated: readonly string[];
	/** Whether this run created the first `admin` account. */
	readonly adminCreated: boolean;
}

const ENVELOPE = (now: string): Statement => ({
	sql: "INSERT INTO _write_log (id, created_at) VALUES (?, ?)",
	params: [crypto.randomUUID(), now],
});

export const runSeeds = async (sql: Sql, options: SeedOptions): Promise<SeedRun> => {
	const { config, now, fresh } = options;

	const rows = await sql.all({ sql: "SELECT name, value FROM settings" });
	const have = new Map(rows.map((r) => [String(r.name), String(r.value ?? "")]));
	const defaults = allSettingDefaults(config);
	const missing = [...defaults.keys()].filter((key) => !have.has(key));

	const storedHash = have.has("password_hash") ? decodeSetting(have.get("password_hash") ?? null) : null;
	const passwordHash = typeof storedHash === "string" ? storedHash : "";
	if (!have.has("password_hash")) {
		const problem = appPasswordProblem(config.appPassword);
		if (problem !== null) {
			throw new SeedRefusedError(problem);
		}
	}

	// Hashing is async and the row is written from this value, so it happens before planning.
	const seededHash =
		!have.has("password_hash") && config.appPassword !== ""
			? await (options.hash ?? hashPassword)(config.appPassword)
			: "";

	const statements: Statement[] = [ENVELOPE(now)];

	for (const key of missing) {
		const value = key === "password_hash" ? seededHash : (defaults.get(key) ?? null);
		statements.push({
			sql: "INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)",
			params: [key, encodeSetting(value), now],
		});
	}

	// 0018's adopt path, performed here because on a fresh v4 install that migration ran before any
	// settings row existed. On an upgrade the key is already '' and there is nothing to adopt.
	const users = await sql.all({ sql: "SELECT COUNT(*) AS n FROM users" });
	const authEnabled = have.has("auth_enabled")
		? decodeSetting(have.get("auth_enabled") ?? null) === true
		: defaults.get("auth_enabled") === true;
	const adoptable = passwordHash !== "" ? passwordHash : seededHash;
	let adminCreated = false;
	if (Number(users[0]?.n ?? 0) === 0 && authEnabled && adoptable !== "") {
		statements.push({
			sql: "INSERT INTO users (username, username_key, password_hash, role, created_at) VALUES (?,?,?,?,?)",
			params: ["admin", "admin", adoptable, "admin", now],
		});
		statements.push({
			sql: "UPDATE settings SET value = ? WHERE name = 'password_hash'",
			params: [encodeSetting("")],
		});
		adminCreated = true;
	}

	if (fresh) {
		for (const [role, perms] of Object.entries(FRESH_INSTALL_ROLE_PERMS)) {
			statements.push({
				sql: "UPDATE roles SET perms = ? WHERE role_key = ? AND builtin = 1",
				params: [JSON.stringify(perms), role],
			});
		}
	}

	statements.push({
		sql: "INSERT INTO app_settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value",
		params: [SEED_STAMP, SEED_DONE],
	});

	await sql.batch(statements);

	return {
		inserted: missing,
		rolesUpdated: fresh ? Object.keys(FRESH_INSTALL_ROLE_PERMS) : [],
		adminCreated,
	};
};
