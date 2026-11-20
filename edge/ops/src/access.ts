// Cloudflare Access for apotheke-ops: every request needs a valid Cf-Access-Jwt-Assertion, checked here against the
// team's signing keys (https://<team>.cloudflareaccess.com/cdn-cgi/access/certs). Fails closed: no configuration, no
// token, an unknown key, a bad signature, another issuer or audience, or an expired token is never an operator.

export const ACCESS_JWT_HEADER = "Cf-Access-Jwt-Assertion";

/** How long the team's keys are kept before they are fetched again. */
export const JWKS_TTL_MS = 5 * 60 * 1000;
/** A token whose key is not in the cached set fetches the set again, at most this often. */
export const JWKS_REFRESH_MS = 30 * 1000;
/** Allowed clock difference for nbf and iat. exp has none. */
const SKEW_S = 60;

export type AccessConfig = { team: string; aud: string };
export type AccessEnv = { ACCESS_TEAM_DOMAIN?: string; ACCESS_AUD?: string };
export type Fetch = (input: string, init?: RequestInit) => Promise<Response>;

/** The configured team domain and audience, or null when either is missing or the team domain is not
 *  <team>.cloudflareaccess.com (a scheme and a trailing slash are accepted). */
export function accessConfig(env: AccessEnv): AccessConfig | null {
	const team = String(env.ACCESS_TEAM_DOMAIN ?? "").trim().toLowerCase().replace(/^https:\/\//, "").replace(/\/$/, "");
	const aud = String(env.ACCESS_AUD ?? "").trim();
	if (aud === "" || !/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cloudflareaccess\.com$/.test(team)) return null;
	return { team, aud };
}

type Jwk = JsonWebKey & { kid?: string };
type KeySet = { at: number; keys: Map<string, CryptoKey> };
const keySets = new Map<string, KeySet>();

/** Test hook: forget the cached keys. */
export function clearJwksCache(): void {
	keySets.clear();
}

async function fetchKeys(team: string, fetcher: Fetch, now: number): Promise<KeySet> {
	const res = await fetcher(`https://${team}/cdn-cgi/access/certs`, { redirect: "manual" });
	if (res.status !== 200) {
		await res.body?.cancel();
		throw new Error(`access certs ${res.status}`);
	}
	const body = (await res.json()) as { keys?: unknown };
	const keys = new Map<string, CryptoKey>();
	for (const jwk of Array.isArray(body.keys) ? (body.keys as Jwk[]) : []) {
		if (jwk?.kty !== "RSA" || typeof jwk.kid !== "string" || typeof jwk.n !== "string" || typeof jwk.e !== "string") continue;
		const key = await crypto.subtle
			.importKey("jwk", { kty: "RSA", n: jwk.n, e: jwk.e, alg: "RS256", ext: true }, { name: "RSASSA-PKCS1-v1_5", hash: "SHA-256" }, false, ["verify"])
			.catch(() => null);
		if (key) keys.set(jwk.kid, key);
	}
	const set = { at: now, keys };
	keySets.set(team, set);
	return set;
}

async function keyFor(team: string, kid: string, fetcher: Fetch, now: number): Promise<CryptoKey | undefined> {
	let set = keySets.get(team);
	if (!set || now - set.at >= JWKS_TTL_MS) set = await fetchKeys(team, fetcher, now);
	else if (!set.keys.has(kid) && now - set.at >= JWKS_REFRESH_MS) set = await fetchKeys(team, fetcher, now);
	return set.keys.get(kid);
}

function b64urlBytes(part: string): Uint8Array | null {
	if (!/^[A-Za-z0-9_-]*$/.test(part)) return null;
	try {
		const bin = atob(part.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (part.length % 4)) % 4));
		return Uint8Array.from(bin, (c) => c.charCodeAt(0));
	} catch {
		return null;
	}
}

function b64urlJson(part: string): Record<string, unknown> | null {
	const bytes = b64urlBytes(part);
	if (!bytes) return null;
	try {
		const v = JSON.parse(new TextDecoder().decode(bytes));
		return v !== null && typeof v === "object" && !Array.isArray(v) ? (v as Record<string, unknown>) : null;
	} catch {
		return null;
	}
}

export type AccessResult = { ok: true; email: string } | { ok: false; code: "unauthorized" | "forbidden" | "access_unavailable" };

/** The operator behind request: the email claim of a valid Access token. unauthorized: no valid token for this
 *  application. forbidden: a valid token without an email (for example a service token). access_unavailable: the
 *  team's keys could not be read. */
export async function verifyAccess(request: Request, config: AccessConfig, fetcher: Fetch, nowMs: number): Promise<AccessResult> {
	const token = request.headers.get(ACCESS_JWT_HEADER) ?? "";
	const parts = token.split(".");
	if (parts.length !== 3 || token.length > 16384) return { ok: false, code: "unauthorized" };
	const header = b64urlJson(parts[0]);
	const payload = b64urlJson(parts[1]);
	const sig = b64urlBytes(parts[2]);
	if (!header || !payload || !sig || header.alg !== "RS256" || typeof header.kid !== "string") return { ok: false, code: "unauthorized" };
	let key: CryptoKey | undefined;
	try {
		key = await keyFor(config.team, header.kid, fetcher, nowMs);
	} catch {
		return { ok: false, code: "access_unavailable" };
	}
	if (!key) return { ok: false, code: "unauthorized" };
	const signed = new TextEncoder().encode(`${parts[0]}.${parts[1]}`);
	if (!(await crypto.subtle.verify("RSASSA-PKCS1-v1_5", key, sig, signed))) return { ok: false, code: "unauthorized" };
	const now = Math.floor(nowMs / 1000);
	const aud = Array.isArray(payload.aud) ? payload.aud : [payload.aud];
	if (payload.iss !== `https://${config.team}` || !aud.includes(config.aud)) return { ok: false, code: "unauthorized" };
	if (typeof payload.exp !== "number" || payload.exp <= now) return { ok: false, code: "unauthorized" };
	if (payload.nbf !== undefined && (typeof payload.nbf !== "number" || payload.nbf > now + SKEW_S)) return { ok: false, code: "unauthorized" };
	if (payload.iat !== undefined && (typeof payload.iat !== "number" || payload.iat > now + SKEW_S)) return { ok: false, code: "unauthorized" };
	const email = payload.email;
	if (typeof email !== "string" || email.length > 254 || !/^[^\s@]+@[^\s@]+$/.test(email)) return { ok: false, code: "forbidden" };
	return { ok: true, email: email.toLowerCase() };
}
