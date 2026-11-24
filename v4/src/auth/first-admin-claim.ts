// `FirstAdminClaim` (plan §5 S1.2b, D22/D24): the hosted installation's one-time first-admin link.
//
// The port keeps v3's shape, not just its words: the token's SHA-256 and its expiry are one `settings`
// row (`hosted_first_admin_claim`), the token itself is never stored, and the row's delete and the
// admin's insert are one write unit — so a second use of the same link creates nothing and is refused.
// Only an *active* admin blocks a claim; a pending or disabled one does not, which is v3's
// `NO_ACTIVE_ADMIN` and the reason an install whose admin was demoted can be claimed again.
//
// Two differences from v3, both forced by the Worker:
//
//   * v3 asserts "no active admin" inside the unit and the failed assert rolls the whole unit back.
//     A v4 write unit is one batch with no rollback, so the guard travels *in* the insert
//     (`SELECT … WHERE NOT EXISTS (…)`): a claim that loses to a concurrent admin inserts no row
//     rather than inserting and being undone. The answer is still 410 "no longer valid", and the
//     claim row is still removed — the same outcome, reached by a statement instead of a lock.
//   * `HOSTED_CLAIM_HOURS` stays out of the environment contract (D24), so the window is v3's default
//     of 72 hours, clamped the way v3 clamps it. A hosted install that ran v3 at 24 hours gets 72 in
//     v4: the longer first-admin window, the less safe direction, recorded in the plan rather than
//     discovered at a deploy.

import { type Sql, statement, utcNow } from "../db/sql";
import { hashPassword } from "../platform/passwords";
import { tokensEqual } from "../session/csrf";
import { sha256Hex } from "../session/digest";

/** The settings row (no Control Panel key has this name). `FirstAdminClaim::SETTING`. */
export const SETTING = "hosted_first_admin_claim";

/** `FirstAdminClaim::ADMIN_EXISTS`: `create()` refuses with this (409) while an active admin exists. */
export const ADMIN_EXISTS = "An active admin already exists, so no claim was made.";

/** `FirstAdminClaim::NO_LONGER_VALID`: the answer once an admin exists, or once the row is gone. */
export const NO_LONGER_VALID = "This claim link is no longer valid.";

/** `FirstAdminClaim`'s two other refusals, with the statuses v3 gives them. */
export const NOT_VALID = "This claim link is not valid. Ask for a new one.";
export const EXPIRED = "This claim link has expired. Ask for a new one.";
export const ALREADY_USED = "This claim link has already been used.";

/** `FirstAdminClaim::NO_ACTIVE_ADMIN`. */
const NO_ACTIVE_ADMIN = "NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND status = 'active')";

/** The reads the checks share: one name's key, an active admin's existence, and the new account. */
const NAME_TAKEN = "SELECT 1 AS one FROM users WHERE username_key = ? LIMIT 1";
const ADMIN_ROW = `SELECT 1 AS one FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1`;
const READ_USER = "SELECT id, username, role, status, password_hash, session_epoch FROM users WHERE id = ?";

/** `Accounts::USERNAME_MAX` / `Accounts::PASSWORD_MIN`. */
const USERNAME_MAX = 40;
const PASSWORD_MIN = 8;

/** v3's default `hosted_claim_hours`, clamped as `ApothekeConfig` clamps it: `min(720, max(1, n))`. */
export const CLAIM_HOURS = Math.min(720, Math.max(1, 72));

/** A refusal the caller turns into the claim page; `status` is v3's `UserError` code. */
export class ClaimError extends Error {
	constructor(
		message: string,
		readonly status: number,
	) {
		super(message);
		this.name = "ClaimError";
	}
}

/** `Accounts::nameValid()`'s one message for a bad shape. */
const SHAPE = `User names use letters, digits and . _ @ - (at most ${USERNAME_MAX} characters, no spaces).`;

/** `Accounts::nameValid()`: NUL refused before trimming, then the shape, then the name being free. */
const validName = async (sql: Sql, raw: string): Promise<string> => {
	if (raw.includes("\0")) {
		throw new ClaimError(SHAPE, 422);
	}
	const name = raw.trim();
	if (name === "") {
		throw new ClaimError("Enter a user name.", 422);
	}
	if ([...name].length > USERNAME_MAX || !/^[\p{L}\p{N}][\p{L}\p{N}._@-]*$/u.test(name)) {
		throw new ClaimError(SHAPE, 422);
	}
	const taken = await sql.all(statement(NAME_TAKEN, [accountKey(name)]));
	if (taken.length > 0) {
		throw new ClaimError(`The user name \u201c${name}\u201d is taken.`, 422);
	}
	return name;
};

/** `Accounts::passwordValid()`: characters for the minimum, bytes for the maximum, as PHP counts them. */
const validPassword = (next: string, confirm: string): string => {
	if ([...next].length < PASSWORD_MIN) {
		throw new ClaimError(`Use at least ${PASSWORD_MIN} characters for the password.`, 422);
	}
	if (new TextEncoder().encode(next).length > 200) {
		throw new ClaimError("That password is too long.", 422);
	}
	if (next.includes("\0")) {
		throw new ClaimError("Passwords cannot contain a null character.", 422);
	}
	if (next !== confirm) {
		throw new ClaimError("The two passwords do not match.", 422);
	}
	return next;
};

/** `UserRepository::key()`: `mb_strtolower(trim($name))`. */
export const accountKey = (name: string): string => name.trim().toLowerCase();

/** `FirstAdminClaim::create()`'s token: 32 random bytes, base64url, 43 characters. */
const newClaimToken = (): string => {
	const bytes = new Uint8Array(32);
	crypto.getRandomValues(bytes);
	let binary = "";
	for (const byte of bytes) {
		binary += String.fromCharCode(byte);
	}
	return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
};

interface StoredClaim {
	readonly sha256: string;
	readonly expires: number;
}

/** The row's value, or null when there is none or it is not the object `create()` writes. */
const storedClaim = async (sql: Sql): Promise<{ raw: string; claim: StoredClaim } | null> => {
	const rows = await sql.all(statement("SELECT value FROM settings WHERE name = ?", [SETTING]));
	const raw = rows[0]?.value;
	if (typeof raw !== "string") {
		return null;
	}
	let decoded: unknown;
	try {
		decoded = JSON.parse(raw);
	} catch {
		return null;
	}
	const sha256 = (decoded as { sha256?: unknown }).sha256;
	const expires = (decoded as { expires?: unknown }).expires;
	if (typeof sha256 !== "string" || typeof expires !== "number" || !Number.isInteger(expires)) {
		return null;
	}
	return { raw, claim: { sha256, expires } };
};

/** `adminExists()`: a read before the unit, which guards the same condition itself. */
const adminExists = async (sql: Sql): Promise<boolean> => {
	const rows = await sql.all(statement(ADMIN_ROW));
	return rows.length > 0;
};

/** `dropAfterAdmin()`: remove the claim row, whatever it holds, then refuse. */
const dropAfterAdmin = async (sql: Sql): Promise<never> => {
	await sql.batch([statement("DELETE FROM settings WHERE name = ?", [SETTING])]);
	throw new ClaimError(NO_LONGER_VALID, 410);
};

/**
 * `FirstAdminClaim::create()`: a new claim's token (shown once, never stored) and when it expires.
 * Refused with 409 while an active admin exists — the read refuses early, and the guarded insert
 * refuses again if an admin appeared in between, so the second check is the one that decides.
 */
export const createClaim = async (sql: Sql, now: number): Promise<{ token: string; expiresAt: string }> => {
	if (await adminExists(sql)) {
		throw new ClaimError(ADMIN_EXISTS, 409);
	}
	const token = newClaimToken();
	const expires = now + CLAIM_HOURS * 3600;
	const value = JSON.stringify({ sha256: await sha256Hex(token), expires });
	const stamp = utcNow(new Date(now * 1000));
	const results = await sql.batch([
		statement("DELETE FROM settings WHERE name = ?", [SETTING]),
		statement(
			`INSERT INTO settings (name, value, updated_at)
			 SELECT ?, ?, ? WHERE ${NO_ACTIVE_ADMIN}`,
			[SETTING, value, stamp],
		),
	]);
	if ((results[1]?.changes ?? 0) !== 1) {
		throw new ClaimError(ADMIN_EXISTS, 409);
	}
	return { token, expiresAt: `${new Date(expires * 1000).toISOString().slice(0, 19).replace("T", " ")} UTC` };
};

/** The account `claim()` created, in the shape `SessionRequest.signIn()` and the session epoch need. */
export interface ClaimedAdmin {
	readonly id: number;
	readonly username: string;
	readonly role: string;
	readonly status: string;
	readonly passwordHash: string;
	readonly sessionEpoch: string;
}

export interface ClaimInput {
	readonly token: string;
	readonly username: string;
	readonly password: string;
	readonly confirm: string;
	readonly now: number;
}

/**
 * `FirstAdminClaim::claim()`: use the claim — a new active `admin`, once. The claim's delete and the
 * account's insert are one batch; the insert also requires that no active admin exists, so the two
 * guards hold together. Throws `ClaimError` with v3's message and status for every refusal.
 */
export const claimAdmin = async (sql: Sql, input: ClaimInput): Promise<ClaimedAdmin> => {
	const stored = await storedClaim(sql);
	if (await adminExists(sql)) {
		return dropAfterAdmin(sql);
	}
	if (stored === null || !tokensEqual(stored.claim.sha256, await sha256Hex(input.token))) {
		throw new ClaimError(NOT_VALID, 403);
	}
	if (stored.claim.expires <= input.now) {
		throw new ClaimError(EXPIRED, 410);
	}
	const name = await validName(sql, input.username);
	const hash = await hashPassword(validPassword(input.password, input.confirm));
	const stamp = utcNow(new Date(input.now * 1000));
	const results = await sql.batch([
		statement(
			`INSERT INTO users (username, username_key, password_hash, role, status, created_at)
			 SELECT ?, ?, ?, 'admin', 'active', ?
			 WHERE ${NO_ACTIVE_ADMIN} AND EXISTS (SELECT 1 FROM settings WHERE name = ? AND value = ?)`,
			[name, accountKey(name), hash, stamp, SETTING, stored.raw],
		),
		statement("DELETE FROM settings WHERE name = ? AND value = ?", [SETTING, stored.raw]),
	]);
	const inserted = results[0];
	if (inserted === undefined || inserted.changes !== 1) {
		// The row is gone (a second use) or an admin exists (the insert's own guard). Both are v3's 410;
		// the admin case is the one that says "no longer valid", the other "already been used".
		if (await adminExists(sql)) {
			throw new ClaimError(NO_LONGER_VALID, 410);
		}
		throw new ClaimError(ALREADY_USED, 410);
	}
	const id = inserted.lastRowId;
	if (id === null) {
		throw new Error("the claim's insert reported no row id");
	}
	const rows = await sql.all(statement(READ_USER, [id]));
	const row = rows[0];
	if (row === undefined) {
		throw new Error("the new account could not be read back.");
	}
	return {
		id: Number(row.id ?? 0),
		username: String(row.username ?? ""),
		role: String(row.role ?? "admin"),
		status: String(row.status ?? "active"),
		passwordHash: String(row.password_hash ?? ""),
		sessionEpoch: String(row.session_epoch ?? ""),
	};
};
