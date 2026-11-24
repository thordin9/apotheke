// The cookie-consent contract (plan §5 S1.2, D20): `Config\Consent` and `CookieConsent`, ported.
//
// Consent is the first cookie the app ever has, and it is the gate in front of the session: without
// it no session is loaded or started, no cookie leaves but the consent cookie (and a session's own
// expiry), and a write is refused with 403 `consent_required`. That is v3's `ConsentFilter` plus
// `SessionCookie.php`'s early return, which is the dependency D20 named.
//
// Two PHP knobs are deliberately not ported, and the reason is the sixteen-name contract (§11):
// `COOKIE_CONSENT=off` and `COOKIE_DOMAIN` are not among the names v4 reads, so consent is always
// required and the consent cookie is always host-only. `COOKIE_DOMAIN` is the wider one — it exists
// so a deployment can share consent across subdomains — and adding it is a change to §11's sixteen
// names, which is a plan decision, not an implementation detail.

/** `Consent::COOKIE`. */
export const CONSENT_COOKIE = "apotheke_consent";

/** `Consent::ACCEPTED`: the values that count as consent. */
export const CONSENT_ACCEPTED: readonly string[] = ["v1"];

/** `Consent::CURRENT`: what Accept sets. */
export const CONSENT_CURRENT = "v1";

/** `Consent::MAX_AGE`: 180 days. */
export const CONSENT_MAX_AGE = 15_552_000;

/** `CookieConsent::STATUS` / `CODE` / `MESSAGE`. */
export const CONSENT_STATUS = 403;
export const CONSENT_CODE = "consent_required";
export const CONSENT_MESSAGE =
	"Cookies are needed to sign in or save changes. Accept cookies in the notice at the top of the page, then try again.";

/** `CookieConsent::MAX_RETURN`: the longest return path POST /consent sends back, as sent. */
export const CONSENT_MAX_RETURN = 2048;

/** `CookieConsent::SAFE`: methods that need no session, so no consent. */
const SAFE: readonly string[] = ["GET", "HEAD", "OPTIONS"];

/** `CookieConsent::given()`: the cookie by its exact name with an accepted value. */
export const consentGiven = (cookies: ReadonlyMap<string, string>): boolean => {
	const value = cookies.get(CONSENT_COOKIE);
	return value !== undefined && CONSENT_ACCEPTED.includes(value);
};

/** `CookieConsent::needed()`: a request with this method changes state, so it needs a session. */
export const consentNeeded = (method: string): boolean => !SAFE.includes(method.toUpperCase());

/**
 * `CookieConsent::cookie()`: the Set-Cookie value POST /consent sends on Accept. Host-only, because
 * `COOKIE_DOMAIN` is not in the contract — v3's empty default is the same line.
 */
export const consentCookieLine = (secure: boolean): string =>
	`${CONSENT_COOKIE}=${CONSENT_CURRENT}; Path=/; Max-Age=${CONSENT_MAX_AGE}${
		secure ? "; Secure" : ""
	}; SameSite=Lax`;

/** `CookieConsent::expired()`: the line POST /consent sends on Decline. */
export const consentExpiryLine = (secure: boolean): string =>
	`${CONSENT_COOKIE}=; Path=/; Max-Age=0${secure ? "; Secure" : ""}; SameSite=Lax`;

/**
 * `CookieConsent::returnPath()`: a path on this site ("/…"), else "/". Refused also once to three
 * times percent-decoded: no leading "/", "//" anywhere, a backslash, control characters. Bytes above
 * ASCII are percent-encoded (a `Location` header carries ASCII only), and a path longer than
 * `MAX_RETURN` after that is "/".
 */
export const returnPath = (raw: unknown): string => {
	if (typeof raw !== "string" || raw.includes(" ")) {
		return "/";
	}
	let decoded = raw;
	for (let round = 0; round < 4; round += 1) {
		if (!decoded.startsWith("/") || decoded.includes("//") || decoded.includes("\\")) {
			return "/";
		}
		// `[\x00-\x1F\x7F]`, written as a code-point test so the range stays readable.
		for (const ch of decoded) {
			const code = ch.codePointAt(0) ?? 0;
			if (code <= 0x1f || code === 0x7f) {
				return "/";
			}
		}
		let next: string;
		try {
			next = decodeURIComponent(decoded);
		} catch {
			next = decoded; // a malformed escape is not a way around the check above
		}
		if (next === decoded) {
			break;
		}
		decoded = next;
	}
	// `preg_replace_callback('/[\x80-\xFF]/')` over the bytes as sent: one UTF-8 byte each.
	let out = "";
	for (const byte of new TextEncoder().encode(raw)) {
		out += byte > 0x7f ? `%${byte.toString(16).toUpperCase()}` : String.fromCharCode(byte);
	}
	return out.length > CONSENT_MAX_RETURN ? "/" : out;
};

/**
 * `SessionGate::isExpiry()`: the line that ends a session cookie. `ConsentFilter::keepOnlyConsentCookie()`
 * keeps it even without consent, because a browser that declined must still lose the session it had.
 */
export const isSessionExpiryLine = (line: string): boolean => {
	const at = line.indexOf("=");
	if (at === -1) {
		return false;
	}
	const name = line.slice(0, at);
	return (name === "apothekesid" || name === "__Host-apothekesid") && line.includes("=deleted;");
};

/**
 * `ConsentFilter::keepOnlyConsentCookie()`: without consent, the only `Set-Cookie` values that may
 * leave are the consent cookie's own and a session cookie's expiry. Every other answer has its
 * cookies removed, which is the backstop behind "a session filter starts nothing without consent".
 */
export const keepOnlyConsentCookies = (lines: readonly string[]): readonly string[] => {
	if (lines.length === 0) {
		return lines;
	}
	return lines.filter(
		(line) => line.startsWith(`${CONSENT_COOKIE}=`) || isSessionExpiryLine(line),
	);
};
