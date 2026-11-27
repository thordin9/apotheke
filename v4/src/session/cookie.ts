// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
// The session cookie (plan §4 rule 4, §5 S1.2): v3's name and flags, derived the way v3 derives
// them — not the way the plan's first wording had them.
//
// The oracle is `modules/Core/Http/SessionGate.php` (`COOKIE`, `SECURE_COOKIE`, `cookieName()`,
// `requestId()`), `RequestScheme::secureCookie()` and `modules/Core/Filters/SessionCookie.php`'s
// `session_set_cookie_params()`. `v4/test/session/cookie.spec.ts` compares the constant names and the
// derived Secure bit against `test/fixtures/php-session.json`, which `v4/scripts/gen-session-fixture.sh`
// captures by calling those classes — so this file cannot drift from v3 without a fixture change.
//
// Why it is derived and not a constant: `__Host-` requires `Secure`, and a self-hoster opening
// `http://192.168.x.x` is not a secure context. A blind `__Host-` prefix would be dropped by the
// browser without a word, so the name follows the same Secure decision the flags do.

/** `SessionGate::COOKIE`. */
export const COOKIE = "apothekesid";

/** `SessionGate::SECURE_COOKIE`: host-only and Secure by the browser's own rule. */
export const SECURE_COOKIE = "__Host-apothekesid";

/** `SessionGate::cookieName(bool $secure)`. */
export const cookieName = (secure: boolean): string => (secure ? SECURE_COOKIE : COOKIE);

/** What the request tells us about its scheme. A Worker has no `$_SERVER`; this is its equivalent. */
export interface SchemeInput {
	/** The request URL as the runtime sees it (`https:` behind Cloudflare, `http:` under `wrangler dev`). */
	readonly url: URL;
	/** The `Host` header as sent, which need not match the URL's host. */
	readonly host: string;
	/** `X-Forwarded-Proto` as sent; only its first value counts, as in v3. */
	readonly forwardedProto: string;
	/** `trust_proxy` from the settings row (v3's `RequestScheme::proxyTrusted()`). */
	readonly trustedProxy: boolean;
	/** `PUBLIC_URL`, trailing slash removed; empty when unset. */
	readonly publicUrl: string;
}

/** A host name without its port, lower-cased; null when the header is not `name[:port]`. */
export const hostName = (header: string): string | null => {
	const value = header.trim().toLowerCase();
	if (value === "") {
		return null;
	}
	const literal = /^\[([0-9a-f:.]+)\](?::\d{1,5})?$/.exec(value);
	if (literal !== null) {
		return literal[1] ?? null;
	}
	const match = /^([a-z0-9_.-]+)(?::\d{1,5})?$/.exec(value);
	return match?.[1] ?? null;
};

/** `RequestScheme::isHttps()`: direct HTTPS, port 443, or a trusted proxy's first forwarded value. */
export const isHttps = (input: SchemeInput): boolean => {
	if (input.url.protocol === "https:") {
		return true;
	}
	if (input.url.port === "443") {
		return true;
	}
	if (!input.trustedProxy) {
		return false;
	}
	const first = (input.forwardedProto.split(",")[0] ?? "").trim().toLowerCase();
	return first === "https";
};

/**
 * `RequestScheme::secureCookie()`: HTTPS, or `PUBLIC_URL` is https and this request uses its host —
 * so an install reached through its public address stays Secure behind a proxy v3 does not trust,
 * while opening it directly by address keeps working over plain HTTP.
 */
export const secureCookie = (input: SchemeInput): boolean => {
	if (isHttps(input)) {
		return true;
	}
	if (!input.publicUrl.toLowerCase().startsWith("https://")) {
		return false;
	}
	let want: string;
	try {
		want = new URL(input.publicUrl).hostname.toLowerCase();
	} catch {
		return false;
	}
	const got = hostName(input.host);
	return got !== null && want.replace(/^\[|\]$/g, "") === got;
};

/** The request's cookies, by name. First occurrence wins, as browsers send them. */
export const parseCookies = (header: string | null): ReadonlyMap<string, string> => {
	const out = new Map<string, string>();
	if (header === null || header.trim() === "") {
		return out;
	}
	for (const part of header.split(";")) {
		const at = part.indexOf("=");
		if (at === -1) {
			continue;
		}
		const name = part.slice(0, at).trim();
		if (name !== "" && !out.has(name)) {
			out.set(name, part.slice(at + 1).trim());
		}
	}
	return out;
};

/**
 * `SessionGate::requestId()`: the name this request's scheme sets, and — on HTTPS only — the plain
 * name as a fallback, so a session issued before the rename survives it. v3 keeps that fallback for
 * one release; v4 must read it for as long as v3 writes it.
 */
export const requestSessionId = (cookies: ReadonlyMap<string, string>, secure: boolean): string | null => {
	const named = cookies.get(cookieName(secure));
	if (named !== undefined) {
		return named;
	}
	return secure ? (cookies.get(COOKIE) ?? null) : null;
};

/** The session cookie names a request carried, as `SessionGate::carried()` lists them. */
export const carriedCookies = (cookies: ReadonlyMap<string, string>): readonly string[] =>
	[SECURE_COOKIE, COOKIE].filter((name) => cookies.has(name));

/** PHP's `setcookie()` date form: `D, d-M-Y H:i:s \G\M\T`, in UTC. */
export const httpDate = (at: number): string => {
	const days = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
	const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
	const d = new Date(at * 1000);
	const pad = (n: number): string => String(n).padStart(2, "0");
	return `${days[d.getUTCDay()]} ${pad(d.getUTCDate())}-${months[d.getUTCMonth()]}-${d.getUTCFullYear()} ${pad(
		d.getUTCHours(),
	)}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())} GMT`;
};

/** The attributes `SessionCookie.php` sets on every session cookie, in PHP's order. */
const attributes = (secure: boolean): string => `path=/; HttpOnly; SameSite=Lax${secure ? "; Secure" : ""}`;

/** A fresh session cookie: the value, a lifetime in seconds (persistent, not browser-session). */
export const sessionCookieLine = (input: {
	readonly name: string;
	readonly value: string;
	readonly secure: boolean;
	readonly lifetimeSeconds: number;
	readonly now: number;
}): string =>
	`${input.name}=${input.value}; expires=${httpDate(input.now + input.lifetimeSeconds)}; Max-Age=${
		input.lifetimeSeconds
	}; ${attributes(input.secure)}`;

/**
 * `SessionGate::expireCookies()`: an empty value, `Max-Age=0` and an expiry in the past. `__Host-`
 * names are Secure by definition; the plain name is expired without it, which is the shape
 * `v4/test/session/cookie.spec.ts` pins and `Expires`/`Max-Age` both carry (a cache that honours
 * only one of them must still drop it).
 */
export const expiryCookieLine = (input: { readonly name: string; readonly now: number }): string =>
	`${input.name}=deleted; expires=${httpDate(input.now - 1)}; Max-Age=0; path=/; HttpOnly; SameSite=Lax${
		input.name === SECURE_COOKIE ? "; Secure" : ""
	}`;
