// Your own password and "sign out everywhere" (plan §5 S1.5): `modules/Auth/Accounts.php`'s
// `checkCurrent()`/`changeOwnPassword()` and `modules/Auth/SignOutEverywhere.php`, ported.
//
// The current-password check is *throttled exactly as a sign-in is*, and that is the point of the
// three lines below rather than an implementation detail: `login_address` (30 per 15 minutes, every
// name) and `login` (10 per 15 minutes, this account from this address) are both counted **before**
// the password is checked, a failure answers at `SignInPace`'s deadline, and a success clears the
// account's counter and takes back its own address row. Without them `/account` is an unthrottled
// password oracle: a signed-in session could guess its own account's password as fast as the worker
// hashes.
//
// The write renews the session epoch, which is what `SignOutEverywhere` does too: every session still
// holding the old epoch stops authenticating on its next request, and the caller signs the current
// session back in with the new one (`keepSignIn`, so `session_days` does not start again).

import { type Sql, statement } from "../db/sql";
import type { Ctx } from "../http/filters";
import { hashPassword, verifyPassword } from "../platform/passwords";
import { padSignIn, startSignIn } from "../session/sign-in-pace";
import { hit, succeeded } from "../session/throttle";
import { clientAddress, DUMMY_HASH } from "./sign-in";
import { find, freshEpoch, UserError, validPassword } from "./users";

/** `Accounts::checkCurrent()`'s one sentence (`Accounts.php:210`). */
export const WRONG_CURRENT = "The current password is not correct.";

/** `SignOutEverywhere::run()` and `changeOwnPassword()`'s missing row (`Accounts.php:227`). */
export const NO_USER = "User not found.";

/** The account a route's `self` permission admitted. */
export interface OwnAccount {
	readonly id: number;
	readonly username: string;
	readonly role: string;
}

/**
 * `Accounts::checkCurrent()`: the per-address cap, the per-account cap, the password check, and the
 * counters a success takes back. Throws v3's 403 at the deadline, and v3's 429 (with the seconds a
 * `Retry-After` needs) when a cap is reached — before the check, so a throttled attempt costs no
 * bcrypt.
 *
 * v3 pads with `usleep()`; the port waits on a timer (`session/sign-in-pace.ts`), with the same
 * stated cost on `/account` as on `/login`: a wrong current password holds the request up to a
 * second of wall time.
 */
export const checkCurrent = async (ctx: Ctx, me: OwnAccount, current: string): Promise<void> => {
	const started = startSignIn();
	const address = clientAddress(ctx);
	const sql = ctx.session.sql;
	const mine = await hit(sql, { kind: "login_address", client: address, now: ctx.session.now });
	if (!mine.ok) {
		throw new UserError(mine.message, 429, mine.retryAfterSeconds);
	}
	const thisName = await hit(sql, {
		kind: "login",
		client: address,
		account: me.username,
		now: ctx.session.now,
	});
	if (!thisName.ok) {
		throw new UserError(thisName.message, 429, thisName.retryAfterSeconds);
	}
	const user = await find(sql, me.id);
	// `Accounts::verify()`: an unknown row is checked against the dummy hash, a NUL byte never
	// matches, and a missing row and a wrong password are the same answer at the same deadline.
	const matches = await verifyPassword(current, user?.passwordHash ?? DUMMY_HASH);
	if (user === null || !matches || current.includes("\u0000")) {
		await padSignIn(started, ctx.config.signInDeadlineMs);
		throw new UserError(WRONG_CURRENT, 403);
	}
	// `$limiter->clear('login', $me['username'])` and `$limiter->forget($mine)`.
	await succeeded(sql, { client: address, account: me.username, now: ctx.session.now });
};

/**
 * `Accounts::changeOwnPassword()`: the check, then the row (which can have gone in between — a
 * deletion by an admin between the permission check and this write is the one case that answers
 * v3's 404), then the new password's own four refusals at 422, then the epoch.
 *
 * Returns the new epoch for the session that stays signed in.
 */
export const changeOwnPassword = async (
	ctx: Ctx,
	me: OwnAccount,
	current: string,
	next: string,
	confirm: string,
): Promise<string> => {
	await checkCurrent(ctx, me, current);
	const sql = ctx.session.sql;
	if ((await find(sql, me.id)) === null) {
		throw new UserError(NO_USER, 404);
	}
	const password = validPassword(next, confirm);
	const epoch = freshEpoch();
	const hash = await hashPassword(password);
	await sql.batch([
		statement("UPDATE users SET password_hash = ?, session_epoch = ? WHERE id = ?", [hash, epoch, me.id]),
	]);
	// D26's read-back: the answer must not claim a write it cannot see.
	const after = await find(sql, me.id);
	if (after === null || after.passwordHash !== hash || after.sessionEpoch !== epoch) {
		throw new UserError("The password was not saved.", 503);
	}
	return epoch;
};

/** `SignOutEverywhere::run()`: a fresh epoch for the account, so its other sessions stop. */
export const signOutEverywhere = async (sql: Sql, userId: number): Promise<string> => {
	if ((await find(sql, userId)) === null) {
		throw new UserError(NO_USER, 404);
	}
	const epoch = freshEpoch();
	await sql.batch([statement("UPDATE users SET session_epoch = ? WHERE id = ?", [epoch, userId])]);
	const after = await find(sql, userId);
	if (after === null || after.sessionEpoch !== epoch) {
		throw new UserError("The session was not renewed.", 503);
	}
	return epoch;
};
