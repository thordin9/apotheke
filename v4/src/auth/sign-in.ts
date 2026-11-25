// One sign-in attempt, shared by `POST /login` and `POST /api/v1/session` (plan §5 S1.4): v3's
// `AuthPages::attemptSignIn()` + `Accounts::signIn()`/`admit()`.
//
// v3's API controller says why this is one function and not two: "Sign-in and sign-out use the same
// code as /login and /logout". The two surfaces differ only in how they *write* the answer — a page
// or a problem+json — so the attempt returns an outcome and each caller maps it.
//
// The order is v3's and it is observable: the per-address limit first (30/15 min, every name), then
// the per-account one (10/15 min, this address), then the password. Each attempt is recorded before
// it is counted and taken back when refused (see `session/throttle.ts`), so two requests at the same
// instant cannot both slip under a limit.
//
// The failure deadline: a wrong name or password waits until `SignInPace`'s deadline after the
// attempt began. A pending account does *not* — v3 verifies the password first, so it already knows
// the answer is not about the password, and a right password on a pending account answers at once.

import { type Sql, statement } from "../db/sql";
import type { Ctx } from "../http/filters";
import { verifyPassword } from "../platform/passwords";
import { sessionEpoch } from "../session/epoch";
import { padSignIn, startSignIn } from "../session/sign-in-pace";
import { accountKey, hit, succeeded } from "../session/throttle";

/** The deadline's start, re-exported so both callers take it from the same module. */
export { startSignIn };

/** `Accounts::USERNAME_MAX`, doubled: `cleanLine()`'s limit for a posted name. */
export const USERNAME_MAX = 40;

/** `Accounts::DUMMY_HASH`: an unknown name costs one verify, so the answer does not time the name. */
const DUMMY_HASH = "$2y$12$rGbXD6ftSShKrjjQ/V0Mp.DKflUCQZ2CgnF1mN0dY.pmyaxcHRNr2";

/** `Accounts::verify()`'s one message for a wrong name and a wrong password. */
export const WRONG = "Wrong user name or password.";

/** `Accounts::admit()`'s refusal for an account an admin has not approved. */
export const PENDING = "Your account is waiting for an admin’s approval.";

/** `Text::cleanLine()`: control characters out, trimmed, and at most `max` characters. */
export const cleanLine = (raw: string, max: number): string => {
	let out = "";
	for (const ch of raw) {
		const code = ch.codePointAt(0) ?? 0;
		if (code < 0x20 || code === 0x7f) {
			continue;
		}
		out += ch;
	}
	return [...out].slice(0, max).join("");
};

/** `ClientAddress`: Cloudflare's header, then the first forwarded value, then nothing. */
export const clientAddress = (ctx: Ctx): string => {
	const cf = (ctx.request.headers.get("cf-connecting-ip") ?? "").trim();
	if (cf !== "") {
		return cf;
	}
	const forwarded = ctx.request.headers.get("x-forwarded-for") ?? "";
	return (forwarded.split(",")[0] ?? "").trim();
};

/** The account row a user name names, or null. `users.username_key` is the repository's key. */
const findUser = async (
	sql: Sql,
	key: string,
): Promise<{
	id: number;
	username: string;
	role: string;
	passwordHash: string;
	status: string;
	sessionEpoch: string;
} | null> => {
	const rows = await sql.all(
		statement(
			"SELECT id, username, role, password_hash, status, session_epoch FROM users WHERE username_key = ?",
			[key],
		),
	);
	const row = rows[0];
	if (row === undefined) {
		return null;
	}
	return {
		id: Number(row.id ?? 0),
		username: String(row.username ?? ""),
		role: String(row.role ?? ""),
		passwordHash: String(row.password_hash ?? ""),
		status: String(row.status ?? "active"),
		sessionEpoch: String(row.session_epoch ?? ""),
	};
};

/** `Accounts::touchLastLogin()`: one column, the app's stored timestamp form. */
const touchLastLogin = async (sql: Sql, userId: number, now: number): Promise<void> => {
	await sql.batch([
		statement("UPDATE users SET last_login_at = ? WHERE id = ?", [
			new Date(now * 1000).toISOString().slice(0, 19).replace("T", " "),
			userId,
		]),
	]);
};

/** What one attempt decided. Each caller writes its own answer; none of them re-decides. */
export type SignInOutcome =
	| { readonly kind: "throttled"; readonly retryAfterSeconds: number; readonly message: string }
	| { readonly kind: "invalid" }
	| { readonly kind: "pending"; readonly message: string }
	| {
			readonly kind: "ok";
			readonly user: { readonly id: number; readonly username: string; readonly role: string };
	  };

export interface SignInInput {
	/** `startSignIn()`'s value: the instant the deadline is measured from. */
	readonly started: number;
	/** The posted name, as sent — a NUL anywhere is checked before trimming (N-221). */
	readonly raw: string;
	/** The cleaned name (`cleanLine($raw, 80)`), which is what the per-account limit counts. */
	readonly who: string;
	readonly password: string;
}

/**
 * One attempt: limits, verify, admit, the new session. A success signs the session in, which rotates
 * the id and mints a new token.
 */
export const attemptSignIn = async (ctx: Ctx, input: SignInInput): Promise<SignInOutcome> => {
	const address = clientAddress(ctx);
	const everyName = await hit(ctx.session.sql, {
		kind: "login_address",
		client: address,
		now: ctx.session.now,
	});
	if (!everyName.ok) {
		return { kind: "throttled", retryAfterSeconds: everyName.retryAfterSeconds, message: everyName.message };
	}
	const thisName = await hit(ctx.session.sql, {
		kind: "login",
		client: address,
		account: input.who,
		now: ctx.session.now,
	});
	if (!thisName.ok) {
		return { kind: "throttled", retryAfterSeconds: thisName.retryAfterSeconds, message: thisName.message };
	}

	// A name with a null character anywhere, checked before trimming, never matches an account.
	const lookup = input.raw.includes("\0") ? input.raw : input.who;
	const user = lookup.includes("\0") ? null : await findUser(ctx.session.sql, accountKey(lookup));
	const matches = await verifyPassword(input.password, user?.passwordHash ?? DUMMY_HASH);
	if (user === null || !matches) {
		// `Accounts::signIn()`: a failure answers at the deadline, not at the hashing time.
		await padSignIn(input.started, ctx.config.signInDeadlineMs);
		return { kind: "invalid" };
	}
	if (user.status !== "active") {
		return { kind: "pending", message: PENDING };
	}
	await touchLastLogin(ctx.session.sql, user.id, ctx.session.now);
	const epoch = await sessionEpoch({
		id: user.id,
		passwordHash: user.passwordHash,
		sessionEpoch: user.sessionEpoch,
	});
	await ctx.session.signIn({ userId: user.id, authEpoch: epoch });
	await succeeded(ctx.session.sql, { client: address, account: input.who, now: ctx.session.now });
	return { kind: "ok", user: { id: user.id, username: user.username, role: user.role } };
};
