// The golden HTML test (plan §5 S1.1): the page v4 renders against the page PHP rendered.
//
// The oracle is `test/fixtures/php-html.json`, captured by `v4/scripts/gen-html-fixtures.sh` from a
// real v3 install (migrated, seeded, served by `php -S`) — not hand-written and not adjusted to
// match what v4 does. It is compared byte for byte after exactly two normalisations, the two the
// slice allows: the CSRF token (empty here on both sides, and a session's token later) and a session
// id, which no page prints today but a later one might.
//
// That means the test catches what a screenshot cannot: a changed whitespace run, a reordered
// attribute, a different indent, a missing `data-` attribute. If v4 and v3 disagree about a byte, one
// of them changed and this test says which.

import { SELF } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import fixture from "../fixtures/php-html.json";

/** The two allowed normalisations. Everything else is compared as it came. */
const normalise = (html: string): string =>
	html
		.replace(/(<meta name="csrf" content=")[^"]*(")/g, "$1<CSRF>$2")
		.replace(/(<input type="hidden" name="csrf" value=")[^"]*(")/g, "$1<CSRF>$2")
		// A session id, should a later page print one in a form field or a data attribute.
		.replace(/(data-session(?:id)?=")[^"]*(")/g, "$1<SESSION>$2")
		.replace(/\b(?:PHPSESSID|session)=[A-Za-z0-9,-]{16,}/g, "session=<SESSION>");

/** `localhost` is what `hostcheck` accepts without `PUBLIC_URL` being set, as the other specs do. */
const fetchPage = (path: string, init: RequestInit = {}) => SELF.fetch(`http://localhost${path}`, init);

describe("/login is the page v3 renders", () => {
	it("answers 200 with the PHP app's bytes", async () => {
		const response = await fetchPage("/login");
		expect(response.status).toBe(200);
		expect(response.headers.get("content-type")).toBe(fixture.pages.login.contentType);
		expect(response.headers.get("cache-control")).toBe(fixture.pages.login.cacheControl);
		expect(normalise(await response.text())).toBe(normalise(fixture.pages.login.body));
	});

	it("carries the page security headers, from the chain and not a second copy", async () => {
		const response = await fetchPage("/login");
		expect(response.headers.get("x-content-type-options")).toBe("nosniff");
		expect(response.headers.get("x-frame-options")).toBe("SAMEORIGIN");
		expect(response.headers.get("referrer-policy")).toBe("same-origin");
		expect(response.headers.get("content-security-policy")).toBe(
			"default-src 'self'; connect-src 'self' blob:; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
		);
	});

	it("answers HEAD with the same headers and no body", async () => {
		const response = await fetchPage("/login", { method: "HEAD" });
		expect(response.status).toBe(200);
		expect(response.headers.get("content-type")).toBe(fixture.pages.login.contentType);
		expect(await response.text()).toBe("");
	});
});

describe("the page error pages are v3's", () => {
	it("answers an unknown page with the 404 error page", async () => {
		const response = await fetchPage("/nope");
		expect(response.status).toBe(404);
		expect(response.headers.get("content-type")).toBe(fixture.pages.notFound.contentType);
		expect(response.headers.get("cache-control")).toBe(fixture.pages.notFound.cacheControl);
		expect(normalise(await response.text())).toBe(normalise(fixture.pages.notFound.body));
	});

	it("answers a wrong method on a page with the 405 error page and Allow", async () => {
		const response = await fetchPage("/settings", { method: "POST" });
		expect(response.status).toBe(405);
		expect(response.headers.get("allow")).toBe(fixture.pages.methodNotAllowed.allow);
		expect(response.headers.get("content-type")).toBe(fixture.pages.methodNotAllowed.contentType);
		expect(response.headers.get("cache-control")).toBe(fixture.pages.methodNotAllowed.cacheControl);
		expect(normalise(await response.text())).toBe(normalise(fixture.pages.methodNotAllowed.body));
	});
});
