// The sign-in throttle (plan §5 S1.2, §4 rule 4): a port of `modules/Auth/RateLimiter.php` over the
// shared `auth_attempts` table, so v3 and v4 count the same attempts while both run.
//
// The counters are v3's own: sign-in counts per client address *and* account (10 per 15 minutes) and
// per client address across every name (30 per 15 minutes); registration counts per address (5 per
// hour). The key is legacy `client_key()`: the first 32 hex digits of
// `sha256(address, or address NUL account-key)`, where the account key is the repository's
// case-folded name — the client address itself is never stored.
//
// The attempt is recorded *before* it is counted, so two requests at the same instant see each other
// and no more than the limit gets through; a refused attempt takes its row back. Rows older than a day
// are removed on every check, as v3 does.
//
// When the table cannot be read or written the error is not caught here: the request fails closed
// (the sign-in does not happen) rather than treating an unavailable counter as permission to try again.
// That is the same direction v3 takes, where `WriteFailed` becomes a 500.

import { type Sql, statement } from "../db/sql";
import { sha256Hex } from "./digest";

/** `RateLimiter::LIMITS`: kind => [attempts, window in seconds]. */
export const LIMITS: Readonly<Record<string, readonly [number, number]>> = {
	login: [10, 900],
	login_address: [30, 900],
	register: [5, 3600],
};

/** `RateLimiter::KEEP_SECONDS`. */
export const KEEP_SECONDS = 86_400;

/**
 * `UserRepository::key()` for a user name: the case-folded name. v3 folds with `mb_strtolower()`;
 * ASCII names are the same either way, and a non-ASCII name folds identically in JS for the scripts
 * PHP's default locale handles. This is the one place the two runtimes could disagree, so the
 * fixture records the folded value for a case-mixed name.
 */
export const accountKey = (username: string): string => username.trim().toLowerCase();

/** `RateLimiter::addressScope()`: an IPv6 client counts by its /64 (IPv4 stays whole, IPv4-mapped too). */
export const addressScope = (ip: string): string => {
	const value = ip.trim();
	if (!value.includes(":")) {
		return value;
	}
	const groups = value.split(":");
	if (groups.length < 3) {
		return value;
	}
	// The heuristic PHP implements through inet_pton: only a plain, non-mapped IPv6 address is scoped.
	const mapped = /^::(ffff:)?/i.test(value) || value.startsWith("64:ff9b:");
	if (mapped) {
		return value;
	}
	return `${groups.slice(0, 4).join(":")}::/64`;
};

/** `RateLimiter::bucket()`: the counter's key. */
export const bucket = async (
	kind: string,
	clientAddress: string,
	account: string | null,
): Promise<string> => {
	const material =
		kind === "login" ? `${clientAddress}\u0000${accountKey(account ?? "")}` : addressScope(clientAddress);
	return (await sha256Hex(material)).slice(0, 32);
};

/**
 * `RateLimiter::succeeded()`: a sign-in that worked takes back the counter it just added — the
 * account's own bucket goes entirely, and the address bucket loses the one row this attempt made.
 * Without it a burst of typos followed by the right password would leave the account one attempt
 * from a lockout it never earned.
 *
 * v3 deletes the address row by the id its insert returned; `do-sql` reports no `last_row_id` under
 * the local-SQLite driver, so this takes the newest row for that address at this second — the same
 * attempt, and the same effect when two requests raced.
 */
export const succeeded = async (
	sql: Sql,
	input: { readonly client: string; readonly account?: string; readonly now: number },
): Promise<void> => {
	const accountKeyValue = await bucket("login", input.client, input.account ?? null);
	const addressKey = await bucket("login_address", input.client, null);
	await sql.batch([
		statement("DELETE FROM auth_attempts WHERE kind = ? AND client = ?", ["login", accountKeyValue]),
		statement(
			`DELETE FROM auth_attempts WHERE id = (
				SELECT id FROM auth_attempts WHERE kind = ? AND client = ? AND at = ? ORDER BY id DESC LIMIT 1
			)`,
			["login_address", addressKey, input.now],
		),
	]);
};

/** A refusal: what the caller turns into a 429 with `Retry-After`. */
export interface Throttled {
	readonly ok: false;
	readonly retryAfterSeconds: number;
	readonly message: string;
}

export interface Allowed {
	readonly ok: true;
}

export type ThrottleResult = Allowed | Throttled;

/**
 * `RateLimiter::hit()`: refuse when `kind` was tried too often, otherwise record the attempt.
 * Callers pass the client address v3 would see (`ClientAddress`: `CF-Connecting-IP`, then the first
 * forwarded value, then the peer address).
 */
export const hit = async (
	sql: Sql,
	input: { readonly kind: string; readonly client: string; readonly account?: string; readonly now: number },
): Promise<ThrottleResult> => {
	const limit = LIMITS[input.kind];
	if (limit === undefined) {
		throw new Error(`Unknown throttle kind: ${input.kind}`);
	}
	const [max, window] = limit;
	const key = await bucket(input.kind, input.client, input.account ?? null);
	await sql.batch([
		statement("DELETE FROM auth_attempts WHERE at < ?", [input.now - KEEP_SECONDS]),
		statement("INSERT INTO auth_attempts (kind, client, at) VALUES (?, ?, ?)", [input.kind, key, input.now]),
	]);
	const rows = await sql.all(
		statement("SELECT COUNT(*) AS n FROM auth_attempts WHERE kind = ? AND client = ? AND at >= ?", [
			input.kind,
			key,
			input.now - window,
		]),
	);
	const seen = Number(rows[0]?.n ?? 0);
	if (seen > max) {
		// Take the attempt back. The row is found by its own key rather than by an insert id: the
		// local-SQLite driver reports no `last_row_id` (do-sql.ts), and a counter must not depend on
		// which backend it is counting on. A row with the same kind, client and second is the same
		// attempt as far as this limit is concerned, so removing one of them is the intended effect
		// even when two requests raced.
		await sql.batch([
			statement(
				`DELETE FROM auth_attempts WHERE id = (
					SELECT id FROM auth_attempts WHERE kind = ? AND client = ? AND at = ? ORDER BY id DESC LIMIT 1
				)`,
				[input.kind, key, input.now],
			),
		]);
		const scope = input.kind === "login" ? "for this account from this address" : "from this address";
		const minutes = Math.ceil(window / 60);
		return {
			ok: false,
			retryAfterSeconds: window,
			message: `Too many attempts ${scope} – please wait ${minutes} minutes and try again.`,
		};
	}
	return { ok: true };
};
