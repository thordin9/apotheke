// S1.7: `RefusalCacheControlTest` (N-19) — a refusal is never cached.
//
// v3's test (tests/Modules/Core/RefusalCacheControlTest.php, retired in 5d65939e) asserted one
// property on four refusals: CSRF 419, the same-origin 403, the dropped-body 413 and the exception
// handler's error pages. For every one of them the HTML page carries a `Cache-Control` containing
// `no-store`, and a client that wants JSON gets `application/json; charset=utf-8` with exactly
// `no-store`. That is v3's own split — exact where v3 asserted exact, contains where v3 asserted
// contains — and D136 put the answers back in v3's shape:
//
//   - the 419 keeps both v3 shapes — the error page for a page, `{"ok":false,"error":"Your session
//     expired."}` for a client that wants JSON — and every refusal answers exactly `no-store` on the
//     JSON shape, which is narrower than v3's page spelling (plan §5, S1.5's named narrowing: see
//     `ERROR_PAGE_CACHE`, which the 404 page below still sends);
//   - the 403 (`sameorigin`) and the 413 (`posttoolarge`) render the app's error page on a page path
//     and legacy JSON for a fetch, as `SameOriginFilter::before()` and `PostTooLargeFilter::before()`
//     do; problem+json is `/api`'s shape, and the 413 keeps it there. Until D136 both answered
//     problem+json on a page path, which this file reported rather than pinned;
//   - the 503 (a throw out of `handleRoute` or the session flush) is answered through
//     `readiness.ts`' own `problem()`, which now sends `no-store` as v3's `PlainHandler` did;
//     `test/readiness.spec.ts` asserts it there, beside the answer itself.
//   - `testErrorPages`' JSON 500 half stays dropped: `src/http/` has no top-level exception
//     renderer, so an unhandled throw is the runtime's own 500, not a v4 answer.

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

const ORIGIN_MESSAGE =
	"This request came from another site and was refused. Open the app directly and try again.";

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

	it("answers the same-origin 403 as v3 does: the error page, and legacy JSON for a fetch", async () => {
		const crossSite = { "sec-fetch-site": "cross-site" } as const;

		const page = run("sameorigin", "/theme", { method: "POST", headers: crossSite });
		expect(page.status).toBe(403);
		expect(page.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(page.headers.get("cache-control")).toContain("no-store");
		expect(await page.text()).toContain(ORIGIN_MESSAGE);

		const json = run("sameorigin", "/theme", { method: "POST", headers: { ...crossSite, ...JSON_WANTED } });
		expect(json.status).toBe(403);
		expect(json.headers.get("content-type")).toBe("application/json; charset=utf-8");
		expect(json.headers.get("cache-control")).toBe("no-store");
		expect(await json.text()).toBe(JSON.stringify({ ok: false, error: ORIGIN_MESSAGE }));
	});

	it("answers the dropped-body 413 as v3 does, and keeps problem+json under /api", async () => {
		const large = { "content-length": String(99 * 1024 * 1024) } as const;
		const message = "Upload too large (max 20 MB).";

		const page = run("posttoolarge", "/save", { method: "POST", headers: large });
		expect(page.status).toBe(413);
		expect(page.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(page.headers.get("cache-control")).toContain("no-store");
		expect(await page.text()).toContain(message);

		const json = run("posttoolarge", "/save", { method: "POST", headers: { ...large, ...JSON_WANTED } });
		expect(json.status).toBe(413);
		expect(json.headers.get("content-type")).toBe("application/json; charset=utf-8");
		expect(json.headers.get("cache-control")).toBe("no-store");
		expect(await json.text()).toBe(JSON.stringify({ ok: false, error: message }));

		// `/api` goes by the declared length and answers problem+json (`ApiResponse::problem()`).
		const api = run("posttoolarge", "/api/v1/imports", { method: "POST", headers: large });
		expect(api.status).toBe(413);
		expect(api.headers.get("content-type")).toBe("application/problem+json");
		expect(api.headers.get("cache-control")).toBe("no-store");
		expect(((await api.json()) as { code?: unknown }).code).toBe("upload.too_large");
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
