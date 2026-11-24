// The session cookie, against the fixture the PHP classes produced (plan §4 rule 4, §5 S1.2).
//
// Nothing here is a remembered value: the names, the Secure bit for each request shape and the expiry
// line's shape come from `test/fixtures/php-session.json`, which `v4/scripts/gen-session-fixture.sh`
// captures by calling `SessionGate`, `RequestScheme` and `SessionCookie`'s own configuration.

import { describe, expect, it } from "vitest";
import {
	COOKIE,
	carriedCookies,
	cookieName,
	expiryCookieLine,
	hostName,
	httpDate,
	parseCookies,
	requestSessionId,
	SECURE_COOKIE,
	secureCookie,
	sessionCookieLine,
} from "../../src/session/cookie";
import fixture from "../fixtures/php-session.json";

describe("the session cookie", () => {
	it("is v3's name, and the __Host- name only when the request derives Secure", () => {
		expect(COOKIE).toBe(fixture.cookie.plain);
		expect(SECURE_COOKIE).toBe(fixture.cookie.secure);
		for (const row of fixture.cookie.names) {
			expect(cookieName(row.secure), row.name).toBe(row.name);
		}
	});

	it("derives Secure exactly as RequestScheme::secureCookie() does, for every fixture shape", () => {
		expect(fixture.secureCookie.length).toBeGreaterThan(0);
		for (const row of fixture.secureCookie) {
			const got = secureCookie({
				url: new URL(row.input.url),
				host: row.input.host,
				forwardedProto: row.input.forwardedProto,
				trustedProxy: row.input.trustedProxy,
				publicUrl: row.input.publicUrl,
			});
			expect(got, row.name).toBe(row.secure);
		}
	});

	it("reads __Host- first, then the plain name on HTTPS only — v3's one-release fallback", () => {
		const plain = parseCookies("apothekesid=abc");
		expect(requestSessionId(plain, false)).toBe("abc");
		expect(requestSessionId(plain, true)).toBe("abc");
		const secure = parseCookies("__Host-apothekesid=xyz");
		expect(requestSessionId(secure, true)).toBe("xyz");
		// On plain HTTP the __Host- name is not what v3 writes, so it is not what v3 reads either.
		expect(requestSessionId(secure, false)).toBeNull();
		const both = parseCookies("__Host-apothekesid=xyz; apothekesid=abc");
		expect(requestSessionId(both, true)).toBe("xyz");
		expect(carriedCookies(both)).toEqual(["__Host-apothekesid", COOKIE]);
	});

	it("never carries a Domain, and marks Secure only when the name is __Host-", () => {
		const lifetime = 30 * 86_400;
		const plain = sessionCookieLine({
			name: COOKIE,
			value: "abc",
			secure: false,
			lifetimeSeconds: lifetime,
			now: 0,
		});
		const host = sessionCookieLine({
			name: SECURE_COOKIE,
			value: "abc",
			secure: true,
			lifetimeSeconds: lifetime,
			now: 0,
		});
		expect(plain.toLowerCase()).not.toContain("domain=");
		expect(host.toLowerCase()).not.toContain("domain=");
		expect(plain).toContain("path=/; HttpOnly; SameSite=Lax");
		expect(plain).not.toContain("Secure");
		expect(host).toContain("path=/; HttpOnly; SameSite=Lax; Secure");
		// PHP's lifetime is the session lifetime in seconds: persistent, not browser-session.
		expect(plain).toContain("Max-Age=2592000");
		expect(plain).toContain(`expires=${httpDate(lifetime)}`);
	});

	it("expires the cookie in the shape SessionGate::isExpiry() pins", () => {
		expect(expiryCookieLine({ name: COOKIE, now: 1_764_000_000 })).toBe(
			`${COOKIE}=deleted; expires=${httpDate(1_763_999_999)}; Max-Age=0; path=/; HttpOnly; SameSite=Lax`,
		);
		expect(expiryCookieLine({ name: SECURE_COOKIE, now: 1_764_000_000 })).toBe(
			`${SECURE_COOKIE}=deleted; expires=${httpDate(1_763_999_999)}; Max-Age=0; path=/; HttpOnly; SameSite=Lax; Secure`,
		);
	});

	it("splits a Host header the way the PHP allow-list does", () => {
		expect(hostName("Apotheke.Example:8443")).toBe("apotheke.example");
		expect(hostName("[::1]:9091")).toBe("::1");
		expect(hostName(" not a host ")).toBeNull();
		expect(hostName("")).toBeNull();
	});
});
