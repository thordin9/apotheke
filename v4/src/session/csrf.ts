// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
// Form CSRF (plan §4 rule 4, §5 S1.2): `modules/Core/Http/CsrfCheck.php`, ported.
//
// The token lives in the session (`csrf` in `sessions.data`) and comes back in the `csrf` form field
// or the `X-CSRF-Token` header; the field wins when both are present, as v3 does. A refusal is 419
// with v3's message — the status the golden HTML fixture and the E2E suite already expect.
//
// The token is 32 random bytes as hex, exactly what `CsrfCheck::newToken()` produces, so a token
// minted by v4 is the shape v3 mints and vice versa.

/** `CsrfCheck::STATUS` / `REASON` / `MESSAGE`. */
export const CSRF_STATUS = 419;
export const CSRF_REASON = "Page Expired";
export const CSRF_MESSAGE = "Your session expired. Reload the page and try again.";

/** Methods that never change state (RFC 9110 safe methods), as `CsrfCheck::SAFE`. */
const SAFE: readonly string[] = ["GET", "HEAD", "OPTIONS", "TRACE"];

/** `CsrfCheck::applies()`. */
export const csrfApplies = (method: string): boolean => !SAFE.includes(method.toUpperCase());

/** `CsrfCheck::newToken()`: 64 hex characters. */
export const newToken = (): string => {
	const bytes = new Uint8Array(32);
	crypto.getRandomValues(bytes);
	return [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
};

/** Constant-time string equality, the equivalent of PHP's `hash_equals()` for two tokens. */
export const tokensEqual = (a: string, b: string): boolean => {
	if (a.length !== b.length || a.length === 0) {
		return false;
	}
	let diff = 0;
	for (let i = 0; i < a.length; i += 1) {
		diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
	}
	return diff === 0;
};

/**
 * `CsrfCheck::passes()`: the field when present, the header otherwise. An absent session token never
 * passes, and an empty submitted token never passes — v3's rule, and the reason a session without a
 * token cannot be talked into accepting one.
 */
export const csrfPasses = (
	sessionToken: string,
	post: ReadonlyMap<string, string>,
	header: string | null,
): boolean => {
	const sent = post.has("csrf") ? (post.get("csrf") ?? "") : (header ?? "");
	return sessionToken !== "" && sent !== "" && tokensEqual(sessionToken, sent);
};
