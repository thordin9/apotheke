// The `sessions.data` column (plan §4 rule 4, §5 S1.2 DoD): PHP's own session serialisation, because
// the row v4 writes must be the row v3 would have written and v3 reads it with
// `session.serialize_handler=php`.
//
// The format is `name|serialized;` repeated, e.g.
//
//     uid|i:7;auth_epoch|s:16:"0123456789abcdef";csrf|s:64:"…";last_activity|i:1700000000;
//
// `modules/Core/Http/SessionGate::signIn()` fixes the keys and their order: `uid`, `auth_epoch`,
// `csrf`, `last_activity`, `signed_in_at`. `v4/scripts/gen-session-fixture.sh` captures a real
// `session_encode()` of exactly those values, and `v4/test/session/php-session.spec.ts` requires this
// encoder to produce that string byte for byte.
//
// The decoder reads what v3 may leave behind (`b:`, `d:`, `N;`), so a session written by PHP never
// fails to load in v4. Anything it cannot parse is refused, not guessed: a half-understood session is
// a wrong identity.

/**
 * The five keys a session may hold, in `SessionGate::signIn()`'s insertion order. `uid` is null for a
 * session that has not signed in — a page that renders a form starts one for its token alone — and a
 * key is written only when it has a value, which is what PHP's `$_SESSION` does: the sign-in writes
 * all five, a first-visit session writes `csrf` and `last_activity`.
 */
export interface SessionValues {
	readonly uid: number | null;
	readonly authEpoch: string;
	readonly csrf: string;
	readonly lastActivity: number;
	readonly signedInAt: number;
	/** Keys the decoder understood but the session layer does not use, kept so a rewrite does not drop them. */
	readonly rest?: Readonly<Record<string, string | number>>;
}

const utf8Length = (text: string): number => new TextEncoder().encode(text).length;

/** `serialize()` for the value kinds a session holds. */
const encodeValue = (value: string | number): string =>
	typeof value === "number" ? `i:${Math.trunc(value)};` : `s:${utf8Length(value)}:"${value}";`;

/**
 * The `data` string, in v3's order. A key with no value is left out rather than encoded as an empty
 * value: PHP's session array holds only what was set, so a token-only session writes two keys and a
 * signed-in one writes five.
 */
export const encodeSession = (values: SessionValues): string => {
	const all: (readonly [string, string | number | null])[] = [
		["uid", values.uid],
		["auth_epoch", values.authEpoch],
		["csrf", values.csrf],
		["last_activity", values.lastActivity],
		["signed_in_at", values.signedInAt],
	];
	const fields = all.filter((entry): entry is readonly [string, string | number] => {
		const value = entry[1];
		return value !== null && value !== "" && value !== 0;
	});
	for (const [name, value] of Object.entries(values.rest ?? {})) {
		fields.push([name, value]);
	}
	return fields.map(([name, value]) => `${name}|${encodeValue(value)}`).join("");
};

/** What the decoder returns: our five keys when present, plus anything else v3 left. */
export interface DecodedSession {
	readonly uid: number | null;
	readonly authEpoch: string;
	readonly csrf: string;
	readonly lastActivity: number;
	readonly signedInAt: number;
	/** Keys this decoder understood but the session layer does not use, kept so a rewrite does not drop them. */
	readonly rest: Readonly<Record<string, string | number>>;
}

/** One field's value, read from `text` at `at`; the position is returned because PHP's strings are byte-counted. */
const readValue = (text: string, at: number): { readonly value: string | number; readonly next: number } => {
	if (text.startsWith("i:", at)) {
		const end = text.indexOf(";", at + 2);
		if (end === -1) {
			throw new Error("Malformed session data.");
		}
		const raw = text.slice(at + 2, end);
		const n = Number.parseInt(raw, 10);
		if (!Number.isFinite(n) || String(n) !== raw) {
			throw new Error("Malformed session data.");
		}
		return { value: n, next: end + 1 };
	}
	if (text.startsWith("s:", at)) {
		const colon = text.indexOf(":", at + 2);
		const length = Number.parseInt(text.slice(at + 2, colon), 10);
		if (!Number.isFinite(length) || length < 0 || text[colon + 1] !== '"') {
			throw new Error("Malformed session data.");
		}
		const start = colon + 2;
		const end = text.indexOf('";', start);
		if (end === -1) {
			throw new Error("Malformed session data.");
		}
		const raw = text.slice(start, end);
		// PHP stores the byte length; a string whose bytes disagree with it did not come from PHP.
		if (utf8Length(raw) !== length) {
			throw new Error("Malformed session data.");
		}
		return { value: raw, next: end + 2 };
	}
	if (text.startsWith("b:", at)) {
		const raw = text.slice(at + 2, at + 3);
		if ((raw !== "0" && raw !== "1") || text[at + 3] !== ";") {
			throw new Error("Malformed session data.");
		}
		return { value: raw === "1" ? 1 : 0, next: at + 4 };
	}
	if (text.startsWith("N;", at)) {
		return { value: 0, next: at + 2 };
	}
	throw new Error("Malformed session data.");
};

/** Decode a `sessions.data` value. Throws on anything PHP would not have written. */
export const decodeSession = (data: string): DecodedSession => {
	const rest: Record<string, string | number> = {};
	let uid: number | null = null;
	let authEpoch = "";
	let csrf = "";
	let lastActivity = 0;
	let signedInAt = 0;
	let at = 0;
	while (at < data.length) {
		const bar = data.indexOf("|", at);
		if (bar === -1) {
			throw new Error("Malformed session data.");
		}
		const name = data.slice(at, bar);
		if (name === "") {
			throw new Error("Malformed session data.");
		}
		const read = readValue(data, bar + 1);
		at = read.next;
		switch (name) {
			case "uid":
				uid = typeof read.value === "number" ? read.value : null;
				break;
			case "auth_epoch":
				authEpoch = typeof read.value === "string" ? read.value : "";
				break;
			case "csrf":
				csrf = typeof read.value === "string" ? read.value : "";
				break;
			case "last_activity":
				lastActivity = typeof read.value === "number" ? read.value : 0;
				break;
			case "signed_in_at":
				signedInAt = typeof read.value === "number" ? read.value : 0;
				break;
			default:
				rest[name] = read.value;
		}
	}
	return { uid, authEpoch, csrf, lastActivity, signedInAt, rest };
};
