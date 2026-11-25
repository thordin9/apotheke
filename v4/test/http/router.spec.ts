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

import { env } from "cloudflare:workers";
import { beforeAll, describe, expect, it } from "vitest";
import { durableObjectSql, type InstallationStub } from "../../src/db/do-sql";
import { isApi } from "../../src/http/problem";
import { type RouterConfig, routeRequest } from "../../src/http/router";
import { ROUTES } from "../../src/http/routes.generated";
import { openSession } from "../../src/session/request";
import { TEST_LOGIN, TEST_PAGE } from "../helpers/page";
import { samplePath as sample } from "../helpers/paths";

const CONFIG: RouterConfig = {
	publicUrl: "",
	https: false,
	hosted: false,
	maxUploadMb: 20,
	page: TEST_PAGE,
	login: TEST_LOGIN,
	signInEnabled: true,
};

/** Verbs a `Request` cannot be constructed with (workerd forbids them, as browsers do). */
const UNCONSTRUCTABLE = new Set(["CONNECT", "TRACE"]);

/**
 * Every row, asked once. `routeRequest` is async since S1.2 (the sign-in handlers touch the
 * database), so the sweep is built in `beforeAll` rather than at module load.
 */
let requests: { route: (typeof ROUTES)[number]; path: string; response: Response }[] = [];

/**
 * The sweep runs on a real database since S1.4: `GET /api/v1/session` is the first route whose
 * handler reads one, and a detached session refuses a read by design.
 */
const sql = durableObjectSql(
	env.INSTALLATION.get(env.INSTALLATION.idFromName("s0.5-router-sweep")) as unknown as InstallationStub,
);

beforeAll(async () => {
	requests = await Promise.all(
		ROUTES.filter((route) => !UNCONSTRUCTABLE.has(route.method)).map(async (route) => {
			const path = `/${sample(route.path)}`;
			const request = new Request(`http://localhost${path}`, { method: route.method });
			const session = await openSession({
				sql,
				request,
				now: 1_764_000_000,
				secure: false,
				limits: { lifetimeSeconds: 30 * 86_400, idleSeconds: 168 * 3_600 },
			});
			const routed = await routeRequest(request, CONFIG, session);
			await session.flush();
			return { route, path, response: routed.response };
		}),
	);
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
				// S1.1's one live page, and S1.4's four API routes — of which only the read answers
				// 200 without a session (the writes are refused by cookie consent, `me` by permission).
				// A route that starts answering 2xx without being in this list fails here, which is
				// what "no route silently starts answering" means.
				expect(["login", "api/v1/session"], label).toContain(route.name);
				expect(response.status).toBe(200);
				continue;
			}
			// 303 joined the surface in S1.3: v3's redirect for a page that needs a session.
			expect([303, 401, 403, 404, 405, 413, 421, 501], label).toContain(response.status);
		}
		// The page 2xx rows are exactly the login page, asked as GET and as HEAD.
		// S1.4 added the one conditional 2xx: the session read answers 200 to a request with no
		// session (its writes are refused by consent and its account by permission).
		expect(twoHundreds.filter((row) => !row.startsWith("OPTIONS "))).toEqual([
			"GET /login",
			"GET /api/v1/session",
			"HEAD /login",
		]);
		// Every row is asked with its own verb, so no 405 appears here; the separate 405 test covers it.
		expect([...answered].sort((a, b) => a - b)).toEqual([200, 204, 303, 401, 403, 404, 501]);
	});

	it("carries problem+json on every refusal, text/html only from the page answers", () => {
		for (const { route, path, response } of requests) {
			const type = response.headers.get("content-type") ?? "";
			const label = `${route.method} ${path} ${response.status}`;
			if (response.status === 204 || response.status === 303) {
				// No body: the preflight, and S1.3's redirect to /login.
				expect(type, label).toBe("");
				continue;
			}
			if (type === "") {
				// v3's media refusal: 401 with no body, because a page fetched it as an image.
				expect(label).toContain("/media/");
				continue;
			}
			if (type === "text/html; charset=UTF-8") {
				// The page answers: /login and the 404/405 error pages. /api never renders one.
				expect(isApi(path), label).toBe(false);
				continue;
			}
			// S1.4: the session read answers JSON, not problem+json, and only under /api.
			if (isApi(path) && response.status === 200) {
				expect(type, label).toBe("application/json; charset=utf-8");
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
	const json = async (path: string, init: RequestInit = {}) =>
		(await routeRequest(new Request(`http://localhost${path}`, init), CONFIG)).response;

	it("sends a page request that needs a session to /login, which exists since S1.1", async () => {
		const response = await json("/item/1");
		expect(response.status).toBe(303);
		expect(response.headers.get("location")).toBe("/login");
		expect(response.headers.get("x-frame-options")).toBe("SAMEORIGIN");
	});

	it("answers a fetch that needs a session with 401 JSON, as legacy does", async () => {
		const response = await json("/item/1", { headers: { "x-requested-with": "fetch" } });
		expect(response.status).toBe(401);
		expect(await response.json()).toEqual({ ok: false, error: "Not signed in", redirect: "/login" });
	});

	it("answers the /api fallback with the frozen 404 and the API header set (no X-Frame-Options)", async () => {
		const response = await json("/api/v1/nope");
		expect(response.status).toBe(404);
		expect(await response.json()).toMatchObject({ code: "not_found", detail: "No such API endpoint." });
		expect(response.headers.get("x-frame-options")).toBeNull();
		expect(response.headers.get("cross-origin-resource-policy")).toBe("same-site");
		expect(response.headers.get("cache-control")).toBe("no-store");
	});

	it("answers a wrong method on /api with 405 problem+json and Allow", async () => {
		const response = await json("/api/v1/session", { method: "PUT" });
		expect(response.status).toBe(405);
		expect(response.headers.get("allow")).toBe("GET, HEAD, POST, DELETE");
		expect(await response.json()).toMatchObject({ code: "method_not_allowed" });
	});

	it("answers a wrong method on a page with v3's 405 error page and Allow", async () => {
		const response = await json("/item/1", { method: "DELETE" });
		expect(response.status).toBe(405);
		expect(response.headers.get("allow")).toBe("GET, HEAD");
		expect(response.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(await response.text()).toContain("<p>This page does not take this kind of request.</p>");
	});

	it("answers a fetch that wants JSON with JSON, not the 405 page (WantsJson)", async () => {
		const response = await json("/item/1", { method: "DELETE", headers: { "x-requested-with": "fetch" } });
		expect(response.status).toBe(405);
		expect(response.headers.get("content-type")).toBe("application/json; charset=utf-8");
		expect(await response.json()).toMatchObject({ ok: false, error: "Method not allowed" });
	});

	it("refuses an unsafe /api write without cookie consent, before the CSRF check can even matter", async () => {
		// `consent` is required-before and outranks `apicsrf`: no consent, no session, no token (D20).
		const response = await json("/api/v1/session", { method: "POST" });
		expect(response.status).toBe(403);
		expect(await response.json()).toMatchObject({ code: "consent_required" });
	});

	it("then refuses the same /api write for its CSRF rule, once consent is present", async () => {
		// S1.4 ported `ApiCsrf`: a token-less write on this surface is v3's own `403 csrf.invalid`,
		// not the phase-0 "cannot check this" refusal. `test/http/api-session.spec.ts` proves both
		// directions (refused without the token, accepted with it).
		const response = await json("/api/v1/session", {
			method: "POST",
			headers: { cookie: "apotheke_consent=v1" },
		});
		expect(response.status).toBe(403);
		expect(await response.json()).toEqual({
			type: "about:blank",
			title: "Forbidden",
			status: 403,
			detail: "The request was refused: reload and try again.",
			code: "csrf.invalid",
		});
	});

	it("answers 501 for a route whose controller is P1/P2, and names the handler", async () => {
		// `/register` is still unported; `/login` renders since S1.1 and is not 501 any more.
		const response = await json("/register");
		expect(response.status).toBe(501);
		const body = (await response.json()) as { detail: string; code: string };
		expect(body.code).toBe("route.not_implemented");
		expect(body.detail).toContain("SignInController::register");
	});

	it("refuses POST /login without consent, and 419s it without a token once consent is present", async () => {
		// Since S1.2 the submission is answered for real; these are its two refusals, in order.
		const refused = await json("/login", { method: "POST" });
		expect(refused.status).toBe(403);
		expect(await refused.text()).toContain("Cookies are needed to sign in or save changes.");

		const untokened = await json("/login", {
			method: "POST",
			headers: {
				cookie: "apotheke_consent=v1",
				"content-type": "application/x-www-form-urlencoded",
			},
			body: "username=ada&password=x",
		});
		expect(untokened.status).toBe(419);
		expect(await untokened.text()).toContain("Your session expired. Reload the page and try again.");
	});

	it("answers an unknown page with v3's 404 error page, not problem+json", async () => {
		const response = await json("/nope");
		expect(response.status).toBe(404);
		expect(response.headers.get("content-type")).toBe("text/html; charset=UTF-8");
		expect(response.headers.get("cache-control")).toBe("no-store, max-age=0, no-cache");
		expect(response.headers.get("x-frame-options")).toBe("SAMEORIGIN");
		expect(await response.text()).toContain("<p>Page not found.</p>");
	});
});
