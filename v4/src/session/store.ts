// The session store (plan §4 rule 4, §5 S1.2): the `sessions` table 0034 creates —
// `id, data, expires_at, lock_token, locked_until` — read and written the way
// `modules/Core/Http/DatabaseSessionHandler.php` writes it on the D1 driver.
//
// `id` is `key()`: the SHA-256 (hex) of the value the cookie carries, never the cookie value itself,
// so a stolen database row is not a stolen session. `data` is PHP's session serialisation
// (`php-session.ts`). Locking is deliberately not ported here: v3's lock exists because PHP holds a
// session open across a request; a v4 request loads the row once, decides, and writes one batch, and
// an optimistic write is the same guarantee for a single-request lifetime.

import { type Sql, statement } from "../db/sql";
import { sha256Hex } from "./digest";
import { epochHolds, sessionEpoch } from "./epoch";
import { type DecodedSession, decodeSession, encodeSession, type SessionValues } from "./php-session";

/** `DatabaseSessionHandler::ID`: what a session id may look like before it is hashed. */
export const SESSION_ID = /^[A-Za-z0-9,-]{22,256}$/;

/** One live session as the store returns it. */
export interface StoredSession {
	readonly key: string;
	readonly data: string;
	readonly expiresAt: number;
	readonly values: DecodedSession;
}

/** `key()`: the row id for a cookie value. */
export const sessionKey = (id: string): Promise<string> => sha256Hex(id);

const expiredRowCondition = "expires_at <= ?";

/** The row a cookie names, or null when there is none — or when it has expired, in which case it is removed. */
export const loadSession = async (sql: Sql, id: string, now: number): Promise<StoredSession | null> => {
	if (!SESSION_ID.test(id)) {
		return null;
	}
	const key = await sessionKey(id);
	const rows = await sql.all(
		statement("SELECT data, expires_at FROM sessions WHERE id = ? AND expires_at > ?", [key, now]),
	);
	const row = rows[0];
	if (row === undefined) {
		// An expired row is not a session, and leaving it for a later reader is how a copied cookie keeps working.
		await sql.batch([statement(`DELETE FROM sessions WHERE id = ? AND ${expiredRowCondition}`, [key, now])]);
		return null;
	}
	const data = String(row.data ?? "");
	return { key, data, expiresAt: Number(row.expires_at ?? 0), values: decodeSession(data) };
};

/** Create or replace the row a session id owns, with a fresh lifetime. */
export const writeSession = async (
	sql: Sql,
	input: {
		readonly id: string;
		readonly values: SessionValues;
		readonly now: number;
		readonly lifetimeSeconds: number;
	},
): Promise<StoredSession> => {
	// D114: the same gate `loadSession` and `destroySession` apply, in the same file, so a write cannot
	// store a row no later reader would return. Thrown before the digest is computed and before any
	// batch is sent; the caller is told, because a silent no-op is a caller bug nobody ever sees.
	if (!SESSION_ID.test(input.id)) {
		throw new TypeError("a session whose id does not match SESSION_ID can never be loaded");
	}
	const key = await sessionKey(input.id);
	const data = encodeSession(input.values);
	const expiresAt = input.now + input.lifetimeSeconds;
	await sql.batch([
		statement(
			`INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, '', 0)
			 ON CONFLICT(id) DO UPDATE SET data = excluded.data, expires_at = excluded.expires_at,
			   lock_token = '', locked_until = 0`,
			[key, data, expiresAt],
		),
	]);
	return { key, data, expiresAt, values: decodeSession(data) };
};

/** Extend a session's lifetime and store its data — the write `session_write_close()` ends a request with. */
export const touchSession = async (
	sql: Sql,
	input: {
		readonly key: string;
		readonly values: SessionValues;
		readonly now: number;
		readonly lifetimeSeconds: number;
	},
): Promise<number> => {
	const expiresAt = input.now + input.lifetimeSeconds;
	await sql.batch([
		statement("UPDATE sessions SET data = ?, expires_at = ? WHERE id = ?", [
			encodeSession(input.values),
			expiresAt,
			input.key,
		]),
	]);
	return expiresAt;
};

/** `SessionGate::signOut()`: the row is gone, so a copied cookie names nothing. */
export const destroySession = async (sql: Sql, id: string): Promise<void> => {
	if (!SESSION_ID.test(id)) {
		return;
	}
	await sql.batch([statement("DELETE FROM sessions WHERE id = ?", [await sessionKey(id)])]);
};

/**
 * `SessionGate::expired()`: a signed-in session ends after `session_idle_hours` without a request or
 * `session_days` after sign-in (later requests never move that); an anonymous one after `session_days`
 * without use. A signed-in session without a recorded sign-in time gets one on this request instead of
 * being ended on it.
 */
/** The three fields the expiry rules read — a caller need not have decoded the rest. */
export interface ExpiryView {
	readonly uid: number | null;
	readonly lastActivity: number;
	readonly signedInAt: number;
}

export const sessionExpired = (
	values: ExpiryView,
	lifetime: { readonly now: number; readonly idleSeconds: number; readonly absoluteSeconds: number },
): boolean => {
	if (values.uid === null) {
		return values.lastActivity > 0 && lifetime.now - values.lastActivity >= lifetime.absoluteSeconds;
	}
	if (values.signedInAt > 0 && lifetime.now - values.signedInAt >= lifetime.absoluteSeconds) {
		return true;
	}
	return values.lastActivity > 0 && lifetime.now - values.lastActivity >= lifetime.idleSeconds;
};

/** Who a session belongs to, once the epoch has been checked against the account. */
export interface Identity {
	readonly userId: number;
	readonly values: DecodedSession;
	readonly expiresAt: number;
}

/**
 * The identity a request carries, or null. The epoch is the point of this function: a session written
 * before a password change or a "sign out everywhere" holds an epoch the account no longer has, and it
 * stops authenticating here even though its row is still live — that is what "sign out everywhere"
 * means, and it is why the check is not the row's existence.
 */
export const authenticate = async (
	sql: Sql,
	input: {
		readonly id: string | null;
		readonly now: number;
		readonly idleSeconds: number;
		readonly absoluteSeconds: number;
	},
): Promise<Identity | null> => {
	if (input.id === null) {
		return null;
	}
	const stored = await loadSession(sql, input.id, input.now);
	if (stored === null || sessionExpired(stored.values, input)) {
		if (stored !== null) {
			await destroySession(sql, input.id);
		}
		return null;
	}
	const uid = stored.values.uid;
	if (uid === null) {
		return null;
	}
	const rows = await sql.all(
		statement("SELECT id, password_hash, session_epoch FROM users WHERE id = ?", [uid]),
	);
	const user = rows[0];
	if (user === undefined) {
		return null;
	}
	const current = await sessionEpoch({
		id: Number(user.id ?? 0),
		passwordHash: String(user.password_hash ?? ""),
		sessionEpoch: String(user.session_epoch ?? ""),
	});
	if (!epochHolds(stored.values.authEpoch, current)) {
		await destroySession(sql, input.id);
		return null;
	}
	return { userId: uid, values: stored.values, expiresAt: stored.expiresAt };
};
