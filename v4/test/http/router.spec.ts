// The router end to end over the whole table (plan §5 S0.5): nothing serves, and nothing throws.
//
// This is the fail-closed promise made checkable. Every row in the generated table is turned into a
// request and run through the real chain. The allowed answers are exactly: a refusal (401/403/404/
// 405/413/421), `501 route.not_implemented` for a route whose controller is not ported, and the one
// 2xx phase 0 has — the CORS preflight (204) — because it answers no data and needs no session.
// Nothing answers 200.
//
// It also pins the two answers §4 freezes: the /api 404 is problem+json with the API header set, and
// a 405 carries `Allow`.

import { describe, expect, it } from "vitest";
import { isApi } from "../../src/http/problem";
import { type RouterConfig, routeRequest } from "../../src/http/router";
import { ROUTES } from "../../src/http/routes.generated";
import { TEST_LOGIN, TEST_PAGE } from "../helpers/page";
import { samplePath as sample } from "../helpers/paths";

const CONFIG: RouterConfig = {
	publicUrl: "",
	https: false,
	hosted: false,
	maxUploadMb: 20,
	page: TEST_PAGE,
	login: TEST_LOGIN,
};

/** Verbs a `Request` cannot be constructed with (workerd forbids them, as browsers do). */
const UNCONSTRUCTABLE = new Set(["CONNECT", "TRACE"]);

const requests = ROUTES.filter((route) => !UNCONSTRUCTABLE.has(route.method)).map((route) => {
	const path = `/${sample(route.path)}`;
	return {
		route,
		path,
		response: routeRequest(new Request(`http://localhost${path}`, { method: route.method }), CONFIG).response,
	};
});

describe("every route in the table", () => {
	it("answers one of the surface's answers, and the 2xx rows are exactly /login and the preflight", () => {
		const answered = new Set<number>();
		const twoHundreds: string[] = [];
		for (const { route, path, response } of requests) {
			answered.add(response.status);
			const label = `${route.method} ${path}`;
			if (response.status >= 200 && response.status < 300) {
				twoHundreds.push(`${route.method} ${path}`);
				if (route.method === "OPTIONS" && isApi(path)) {
					// The CORS preflight: answers no data and needs no session.
					expect(response.status).toBe(204);
					continue;
				}
				// S1.1's one live page. A route that starts answering 200 without being in this
				// list fails here — which is what "no route silently starts answering" means.
				expect(route.name, label).toBe("login");
				expect(response.status).toBe(200);
				continue;
			}
			expect([401, 403, 404, 405, 413, 421, 501], label).toContain(response.status);
		}
		// The page 2xx rows are exactly the login page, asked as GET and as HEAD.
		expect(twoHundreds.filter((row) => !row.startsWith("OPTIONS "))).toEqual(["GET /login", "HEAD /login"]);
		// Every row is asked with its own verb, so no 405 appears here; the separate 405 test covers it.
		expect([...answered].sort((a, b) => a - b)).toEqual([200, 204, 401, 403, 404, 501]);
	});

	it("carries problem+json on every refusal, text/html only from the page answers", () => {
		for (const { route, path, response } of requests) {
			const type = response.headers.get("content-type") ?? "";
			const label = `${route.method} ${path} ${response.status}`;
			if (response.status === 204) {
				expect(type, label).toBe("");
				continue;
			}
			if (type === "text/html; charset=UTF-8") {
				// The page answers: /login and the 404/405 error pages. /api never renders one.
				expect(isApi(path), label).toBe(false);
				continue;
			}
			expect(type === "application/problem+json" || type === "text/plain; charset=UTF-8", label).toBe(true);
		}
	});

	it("answers 501, not 200 and not an exception, for a controller that is not ported", () => {
		for (const { route, path, response } of requests) {
			if (response.status === 501) {
				expect(route.handler.length, `${route.method} ${route.path}`).toBeGreaterThan(0);
				expect(isApi(path) && route.catchAll, "the /api fallback is a 404, not this").toBe(false);
			}
		}
	});
});

describe("the answers phase 0 is allowed to give", () => {
	const json = (path: string, init: RequestInit = {}) =>
		routeRequest(new Request(`http://localhost${path}`, init), CONFIG).response;

	it("refuses a route that needs a session with 401 problem+json, never a redirect to a page that does not exist", async () => {
		const response = json("/item/1");
		expect(response.status).toBe(401);
		expect(response.headers.get("x-frame-options")).toBe("SAMEORIGIN");
		expect(await response.json()).toMatchObject({
			type: "about:blank",
			title: "Unauthorized",
			status: 401,
			code: "auth.required",
		});
	});

	it("answers the /api fallback with the frozen 404 and the API header set (no X-Frame-Options)", async () => {
		const response = json("/api/v1/nope");
		expect(response.status).toBe(404);
		expect(await response.json()).toMatchObject({ code: "not_found", detail: "No such API endpoint." });
		expect(response.headers.get("x-frame-options")).toBeNull();
		expect(response.headers.get("cross-origin-resource-policy")).toBe("same-site");
		expect(response.headers.get("cache-control")).toBe("no-store");
	});

	it("answers a wrong method on /api with 405 problem+json and Allow", async () => {
		const response = json("/api/v1/session", { method: "PUT" });
		expect(response.status).toBe(405);
		expect(response.headers.get("allow")).toBe("GET, HEAD, POST, DELETE");
		expect(await response.json()).toMatchObject({ code: "method_not_allowed" });
	});

	it("answers a wrong method on a page with v3's 405 error page and Allow", async () => {
		const response = json("/item/1", { method: "DELETE" });
		expect(response.status).toBe(405);
		expect(response.headers.get("allow")).toBe("GET, HEAD");
		expect(response.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(await response.text()).toContain("<p>This page does not take this kind of request.</p>");
	});

	it("answers a fetch that wants JSON with JSON, not the 405 page (WantsJson)", async () => {
		const response = json("/item/1", { method: "DELETE", headers: { "x-requested-with": "fetch" } });
		expect(response.status).toBe(405);
		expect(response.headers.get("content-type")).toBe("application/json; charset=utf-8");
		expect(await response.json()).toMatchObject({ ok: false, error: "Method not allowed" });
	});

	it("refuses an unsafe /api write that cannot be checked, with 403", async () => {
		const response = json("/api/v1/session", { method: "POST" });
		expect(response.status).toBe(403);
		expect(await response.json()).toMatchObject({ code: "csrf.unavailable" });
	});

	it("answers 501 for a route whose controller is P1/P2, and names the handler", async () => {
		// `/register` is still unported; `/login` renders since S1.1 and is not 501 any more.
		const response = json("/register");
		expect(response.status).toBe(501);
		const body = (await response.json()) as { detail: string; code: string };
		expect(body.code).toBe("route.not_implemented");
		expect(body.detail).toContain("SignInController::register");
	});

	it("answers POST /login with 501: the sign-in submission is S1.2", async () => {
		const response = json("/login", { method: "POST" });
		expect(response.status).toBe(501);
		expect(await response.json()).toMatchObject({ code: "route.not_implemented" });
	});

	it("answers an unknown page with v3's 404 error page, not problem+json", async () => {
		const response = json("/nope");
		expect(response.status).toBe(404);
		expect(response.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(response.headers.get("cache-control")).toBe("no-store, max-age=0, no-cache");
		expect(response.headers.get("x-frame-options")).toBe("SAMEORIGIN");
		expect(await response.text()).toContain("<p>Page not found.</p>");
	});
});
