// S1.7: `RefusalCacheControlTest` (N-19) — a refusal is never cached.
//
// v3's test (tests/Modules/Core/RefusalCacheControlTest.php, retired in 5d65939e) asserted one
// property on four refusals: CSRF 419, the same-origin 403, the dropped-body 413 and the exception
// handler's error pages. For every one of them the HTML page carries a `Cache-Control` containing
// `no-store`, and a client that wants JSON gets `application/json; charset=utf-8` with exactly
// `no-store`.
//
// v4 keeps the property and changes two of its shapes, and the difference is stated here rather than
// hidden behind a loose matcher:
//
//   - the 419 keeps both v3 shapes — the error page for a page, `{"ok":false,"error":"Your session
//     expired."}` for a client that wants JSON — and every filter refusal answers exactly `no-store`,
//     which is narrower than v3's `no-store, max-age=0, no-cache` (plan §5, S1.5's named narrowing:
//     see `ERROR_PAGE_CACHE`, which the 404 page below still sends);
//   - the 403 and the 413 answer `application/problem+json` on both shapes, where v3 rendered the
//     error page for a page request. `docs/router.md` (the table's `origin.refused` and
//     `upload.too_large` rows) already states that answer, so these two tests assert it; what the
//     plan does not carry is a decision that a page-path refusal is JSON — reported, not assumed;
//   - `testErrorPages`' JSON half (a 500 answered `no-store` by v3's `PlainHandler`) has no v4
//     counterpart: a throw out of `handleRoute` or the session flush is caught in `src/index.ts` and
//     answered 503 through `readiness.ts`' own `problem()`, which sends no `Cache-Control` at all.
//     That is reported as a finding rather than asserted here — it is a failure answer, not a filter
//     refusal, and `/readyz` shares the helper. The page half ports below as the 404 error page.

import { describe, expect, it } from "vitest";
import { FILTERS } from "../../src/http/filters";
import { createContext, type RouterConfig } from "../../src/http/router";
import { CSRF_MESSAGE } from "../../src/session/csrf";
import { TEST_LOGIN, TEST_PAGE } from "../helpers/page";

const CONFIG: RouterConfig = {
	publicUrl: "",
	https: false,
	hosted: false,
	maxUploadMb: 20,
	page: TEST_PAGE,
	login: TEST_LOGIN,
	signInEnabled: true,
};

/** One filter on one request, the way the chain runs it: through a real context. */
const run = (name: string, path: string, init: RequestInit = {}): Response => {
	const filter = FILTERS[name];
	if (filter === undefined) {
		throw new Error(`no filter ${name}`);
	}
	const response =
		filter.before?.(createContext(new Request(`http://localhost${path}`, init), CONFIG)) ?? null;
	if (response === null) {
		throw new Error(`${name} did not refuse ${path}`);
	}
	return response;
};

/** `X-Requested-With: fetch` is v3's `WantsJson`: the same refusal, the JSON shape. */
const JSON_WANTED = { "x-requested-with": "fetch" } as const;

const codeOf = async (response: Response): Promise<unknown> =>
	((await response.json()) as { code?: unknown }).code;

describe("a refusal is never cached (RefusalCacheControlTest)", () => {
	it("answers a CSRF 419 as the error page, or as exactly no-store for a client that wants JSON", async () => {
		const page = run("sessioncsrf", "/theme", { method: "POST" });
		expect(page.status).toBe(419);
		expect(page.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(page.headers.get("cache-control")).toBe("no-store");
		expect(await page.text()).toContain(CSRF_MESSAGE);

		const json = run("sessioncsrf", "/theme", { method: "POST", headers: JSON_WANTED });
		expect(json.status).toBe(419);
		expect(json.headers.get("content-type")).toBe("application/json; charset=utf-8");
		expect(json.headers.get("cache-control")).toBe("no-store");
		expect(await json.text()).toBe('{"ok":false,"error":"Your session expired."}');
	});

	it("answers the same-origin 403 with exactly no-store, on a page request and on a fetch", async () => {
		const crossSite = { "sec-fetch-site": "cross-site" } as const;
		for (const headers of [crossSite, { ...crossSite, ...JSON_WANTED }]) {
			const refusal = run("sameorigin", "/theme", { method: "POST", headers });
			expect(refusal.status).toBe(403);
			expect(refusal.headers.get("content-type")).toBe("application/problem+json");
			expect(refusal.headers.get("cache-control")).toBe("no-store");
			expect(await codeOf(refusal)).toBe("origin.refused");
		}
	});

	it("answers the dropped-body 413 with exactly no-store, on a page request and on a fetch", async () => {
		const large = { "content-length": String(99 * 1024 * 1024) } as const;
		for (const headers of [large, { ...large, ...JSON_WANTED }]) {
			const refusal = run("posttoolarge", "/save", { method: "POST", headers });
			expect(refusal.status).toBe(413);
			expect(refusal.headers.get("content-type")).toBe("application/problem+json");
			expect(refusal.headers.get("cache-control")).toBe("no-store");
			expect(await codeOf(refusal)).toBe("upload.too_large");
		}
	});

	it("keeps CI4's no-store header on the 404 error page", async () => {
		const page = run("routemethod", "/nope");
		expect(page.status).toBe(404);
		expect(page.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		// CI4's own spelling, not the single `no-store` every other refusal sends: `ERROR_PAGE_CACHE`.
		expect(page.headers.get("cache-control")).toBe("no-store, max-age=0, no-cache");
		expect(await page.text()).toContain("Page not found.");
	});
});
