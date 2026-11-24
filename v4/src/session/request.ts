// The session one request carries (plan §5 S1.2, D20): `SessionCookie`/`SessionGate`/`SessionToken`,
// ported as a per-request object rather than as PHP's process-wide `$_SESSION`.
//
// The workerd model is why this is an object and not a global: every request starts with nothing, and
// a long-lived isolate must not carry one request's session into the next (`SessionCookie` says the
// same thing for PHP-FPM workers — #76 L-3). `load()` is the I/O half (load the row, check the epoch,
// stamp); `flush()` is the write half, one batch, run after the handler. The filters see the
// decisions, not the round trips: `session` attaches the identity, `consent` refuses the write.
//
// What is deliberately *not* here: PHP's session lock. v3 holds a session open across a request and
// locks it; v4 loads once, decides, and writes one batch, so the worst a race can do is lose a stamp.

import type { Sql, Statement } from "../db/sql";
import { consentGiven, keepOnlyConsentCookies } from "./consent";
import {
	carriedCookies,
	cookieName,
	expiryCookieLine,
	parseCookies,
	requestSessionId,
	sessionCookieLine,
} from "./cookie";
import { newToken } from "./csrf";
import { type DecodedSession, encodeSession, type SessionValues } from "./php-session";
import {
	authenticate,
	destroySession,
	type Identity,
	loadSession,
	SESSION_ID,
	sessionExpired,
	sessionKey,
} from "./store";

/** `SessionGate::TOUCH_SECONDS`: the activity stamp moves at most once a minute. */
export const TOUCH_SECONDS = 60;

/** The two limits `SessionGate` reads: `session_days` and `session_idle_hours`. */
export interface SessionLimits {
	readonly lifetimeSeconds: number;
	readonly idleSeconds: number;
}

export interface OpenSessionInput {
	readonly sql: Sql;
	readonly request: Request;
	readonly now: number;
	/** `RequestScheme::secureCookie()` for this request. */
	readonly secure: boolean;
	readonly limits: SessionLimits;
}

const EMPTY: DecodedSession = {
	uid: null,
	authEpoch: "",
	csrf: "",
	lastActivity: 0,
	signedInAt: 0,
	rest: {},
};

/**
 * PHP's session id: 43 characters from `[A-Za-z0-9,-]` — base64url's alphabet with `_` written as
 * `,`, which is the form `DatabaseSessionHandler` accepts and v3 stores.
 */
export const newSessionId = (): string => {
	const bytes = new Uint8Array(32);
	crypto.getRandomValues(bytes);
	let binary = "";
	for (const byte of bytes) {
		binary += String.fromCharCode(byte);
	}
	return btoa(binary).replace(/=+$/, "").replace(/_/g, ",").slice(0, 43);
};

/** Whether a value is a session id v3 would accept. */
export const isValidSessionId = (id: string): boolean => SESSION_ID.test(id);

/** The `Sql` a detached session carries: it is never used, and a use is a test that meant to pass one. */
const NO_DB: Sql = {
	all: () => Promise.reject(new Error("detached SessionRequest: no database")),
	batch: () => Promise.reject(new Error("detached SessionRequest: no database")),
};

/** One request's session: what it loaded, what it decided, and what must be written back. */
export class SessionRequest {
	readonly sql: Sql;
	readonly now: number;
	readonly secure: boolean;
	readonly name: string;
	readonly cookies: ReadonlyMap<string, string>;
	readonly consent: boolean;
	readonly carried: readonly string[];
	readonly limits: SessionLimits;

	/** The cookie value this request is using, or null when it names nothing. */
	id: string | null;
	values: DecodedSession = EMPTY;
	/** The token a page prints; "" when there is none, which is what a first visit prints. */
	csrf = "";
	identity: Identity | null = null;

	private readonly lines: string[] = [];
	private readonly writes: Statement[] = [];

	constructor(input: OpenSessionInput) {
		this.sql = input.sql;
		this.now = input.now;
		this.secure = input.secure;
		this.limits = input.limits;
		this.cookies = parseCookies(input.request.headers.get("cookie"));
		this.consent = consentGiven(this.cookies);
		this.carried = carriedCookies(this.cookies);
		this.name = cookieName(input.secure);
		this.id = requestSessionId(this.cookies, input.secure);
	}

	/**
	 * A session for a context that has none — a filter unit test, or a caller that has already decided
	 * there is nobody. It reads the request's cookies and consent and nothing else; any database use
	 * is a mistake and throws.
	 */
	static detached(request: Request, secure: boolean): SessionRequest {
		return new SessionRequest({
			sql: NO_DB,
			request,
			now: Math.floor(Date.now() / 1000),
			secure,
			limits: { lifetimeSeconds: 30 * 86_400, idleSeconds: 168 * 3_600 },
		});
	}

	/**
	 * `SessionCookie::before()`: load only a cookie that names a live session; end a session whose
	 * consent was withdrawn; start nothing. Consent is the gate in front of all of it (D20).
	 */
	/** Whether a session is loaded: an account, or the token a page was served with. */
	get loaded(): boolean {
		return this.values.uid !== null || this.values.csrf !== "";
	}

	async load(): Promise<void> {
		if (!this.consent && this.carried.length === 0) {
			return; // no consent and nothing carried: no session is loaded or started
		}
		if (!this.consent) {
			await this.endCarried();
			return;
		}
		if (this.id === null) {
			return;
		}
		const stored = await loadSession(this.sql, this.id, this.now);
		if (stored === null) {
			this.id = null;
			return;
		}
		if (stored.values.uid === null) {
			if (sessionExpired(stored.values, this.expiryLimits())) {
				await destroySession(this.sql, this.id);
				this.id = null;
				return;
			}
			this.values = stored.values;
		} else {
			const identity = await authenticate(this.sql, {
				id: this.id,
				now: this.now,
				idleSeconds: this.limits.idleSeconds,
				absoluteSeconds: this.limits.lifetimeSeconds,
			});
			if (identity === null) {
				this.id = null; // `authenticate()` has already removed a row it refused
				return;
			}
			this.identity = identity;
			this.values = identity.values;
		}
		await this.stamp();
	}

	/**
	 * `SessionToken::get()`: a page that renders a form asks for the token, and the session starts on
	 * demand — but only with consent. Without one there is no session, so no token, which is exactly
	 * what a first visit's page prints.
	 */
	async ensureToken(): Promise<string> {
		if (this.csrf !== "") {
			return this.csrf;
		}
		if (!this.consent) {
			return "";
		}
		const token = newToken();
		const id = newSessionId();
		this.values = { ...EMPTY, csrf: token, lastActivity: this.now };
		this.id = id;
		this.push(await this.insert(id, this.values));
		this.lines.push(this.cookieLine(id));
		this.csrf = token;
		return token;
	}

	/**
	 * `SessionGate::signIn()`: a new id (`session_regenerate_id(true)`) so an id a page was served
	 * with cannot become a signed-in one, the account and its epoch in the row, and a fresh token.
	 */
	async signIn(input: { readonly userId: number; readonly authEpoch: string }): Promise<void> {
		const previous = this.id;
		const id = newSessionId();
		this.values = {
			...EMPTY,
			uid: input.userId,
			authEpoch: input.authEpoch,
			csrf: newToken(),
			lastActivity: this.now,
			signedInAt: this.now,
		};
		this.id = id;
		this.csrf = this.values.csrf;
		this.identity = {
			userId: input.userId,
			values: this.values,
			expiresAt: this.now + this.limits.lifetimeSeconds,
		};
		if (previous !== null) {
			this.push({ sql: "DELETE FROM sessions WHERE id = ?", params: [await sessionKey(previous)] });
		}
		this.push(await this.insert(id, this.values));
		this.lines.push(this.cookieLine(id));
	}

	/** `SessionGate::signOut()`: the row goes, so a copied cookie names nothing, and the cookie expires. */
	async signOut(): Promise<void> {
		if (this.id !== null) {
			this.push({ sql: "DELETE FROM sessions WHERE id = ?", params: [await sessionKey(this.id)] });
		}
		for (const name of this.namesToExpire()) {
			this.lines.push(expiryCookieLine({ name, now: this.now }));
		}
		this.id = null;
		this.values = EMPTY;
		this.csrf = "";
		this.identity = null;
	}

	/** The `Set-Cookie` values this request's answer carries — consent applied, as `ConsentFilter` does. */
	cookieLines(): readonly string[] {
		return this.consent ? this.lines : keepOnlyConsentCookies(this.lines);
	}

	/**
	 * One batch, after the handler. A write failure is not caught: the answer must not claim a session
	 * it did not store, and v3 fails the request through `Writes::check` for the same reason.
	 */
	async flush(): Promise<void> {
		if (this.writes.length === 0) {
			return;
		}
		const batch = this.writes.splice(0, this.writes.length);
		await this.sql.batch(batch);
	}

	/**
	 * The activity stamp and the token, for a session this request loaded: `last_activity` at most
	 * once a minute, `signed_in_at` once for a session that has none, and a token if it has none.
	 */
	private async stamp(): Promise<void> {
		let next: SessionValues = {
			uid: this.values.uid,
			authEpoch: this.values.authEpoch,
			csrf: this.values.csrf,
			lastActivity: this.values.lastActivity,
			signedInAt: this.values.signedInAt,
			rest: this.values.rest,
		};
		let changed = false;
		if (next.lastActivity === 0 || this.now - next.lastActivity >= TOUCH_SECONDS) {
			next = { ...next, lastActivity: this.now };
			changed = true;
		}
		if (next.uid !== null && next.signedInAt === 0) {
			next = { ...next, signedInAt: this.now };
			changed = true;
		}
		if (next.csrf === "" && this.consent) {
			next = { ...next, csrf: newToken() };
			changed = true;
		}
		this.csrf = next.csrf;
		this.values = {
			uid: next.uid,
			authEpoch: next.authEpoch,
			csrf: next.csrf,
			lastActivity: next.lastActivity,
			signedInAt: next.signedInAt,
			rest: next.rest ?? {},
		};
		if (!changed || this.id === null) {
			return;
		}
		this.push({
			sql: "UPDATE sessions SET data = ?, expires_at = ? WHERE id = ?",
			params: [encodeSession(next), this.now + this.limits.lifetimeSeconds, await sessionKey(this.id)],
		});
	}

	/** No consent and a cookie was carried: end that session instead of loading it. */
	private async endCarried(): Promise<void> {
		if (this.id !== null) {
			const stored = await loadSession(this.sql, this.id, this.now);
			if (stored !== null) {
				await destroySession(this.sql, this.id);
			}
		}
		for (const name of this.namesToExpire()) {
			this.lines.push(expiryCookieLine({ name, now: this.now }));
		}
		this.id = null;
	}

	/** Every name a sign-out must expire: what the request carried, and the one this scheme uses. */
	private namesToExpire(): readonly string[] {
		const names = new Set<string>(this.carried);
		if (names.size === 0) {
			names.add(this.name);
		}
		return [...names];
	}

	private async insert(id: string, values: SessionValues): Promise<Statement> {
		return {
			sql: `INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, '', 0)
				ON CONFLICT(id) DO UPDATE SET data = excluded.data, expires_at = excluded.expires_at,
				  lock_token = '', locked_until = 0`,
			params: [await sessionKey(id), encodeSession(values), this.now + this.limits.lifetimeSeconds],
		};
	}

	private push(entry: Statement): void {
		this.writes.push(entry);
	}

	private cookieLine(id: string): string {
		return sessionCookieLine({
			name: this.name,
			value: id,
			secure: this.secure,
			lifetimeSeconds: this.limits.lifetimeSeconds,
			now: this.now,
		});
	}

	private expiryLimits(): {
		readonly now: number;
		readonly idleSeconds: number;
		readonly absoluteSeconds: number;
	} {
		return {
			now: this.now,
			idleSeconds: this.limits.idleSeconds,
			absoluteSeconds: this.limits.lifetimeSeconds,
		};
	}
}

/** The I/O half: build the request's session and load what its cookie names. */
export const openSession = async (input: OpenSessionInput): Promise<SessionRequest> => {
	const session = new SessionRequest(input);
	await session.load();
	return session;
};
