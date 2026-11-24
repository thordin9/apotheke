// Each filter on its own (plan §5 S0.5, "each filter independently testable"), plus the registry
// itself: every name the chain can run exists, and the deferred list is exactly the one written down.

import { describe, expect, it } from "vitest";
import {
	GLOBALS_AFTER,
	GLOBALS_BEFORE,
	PATH_FILTERS,
	REQUIRED_AFTER,
	REQUIRED_BEFORE,
} from "../../src/http/chain";
import { DEFERRED, FILTERS, HOST_REFUSAL, splitHost } from "../../src/http/filters";
import { matchRoute } from "../../src/http/route-table";
import { createContext, type RouterConfig } from "../../src/http/router";
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
const HTTPS: RouterConfig = { ...CONFIG, publicUrl: "https://apotheke.example", https: true };

/** Runs one filter on a request, the way the chain would: through a real context. */
const run = (
	name: string,
	path: string,
	init: RequestInit = {},
	config: RouterConfig = CONFIG,
): Response | null => {
	const filter = FILTERS[name];
	if (filter === undefined) {
		throw new Error(`no filter ${name}`);
	}
	const ctx = createContext(new Request(`http://localhost${path}`, init), config);
	const response = filter.before?.(ctx) ?? null;
	return response;
};

describe("the registry", () => {
	it("has a filter for every name the chain can run", () => {
		const names = [REQUIRED_BEFORE, REQUIRED_AFTER, GLOBALS_AFTER].flat();
		for (const global of GLOBALS_BEFORE) {
			names.push(global.name);
		}
		for (const path of PATH_FILTERS) {
			names.push(path.name);
		}
		for (const name of names) {
			expect(FILTERS[name], name).toBeDefined();
		}
	});

	it("declares the deferred filters by name, and only the ones phase 0 cannot carry yet", () => {
		expect(Object.keys(DEFERRED).sort()).toEqual([
			"api",
			"apicsrf",
			"formfields",
			"harness",
			"opsaccounts",
			"opsbackup",
			"requestcleanup",
			"sessioncsrf",
		]);
	});

	it("fails closed in the filters it defers, or names what closes the gap", () => {
		// The three that gate access refuse rather than pass; the rest cannot decide anything in phase 0.
		expect(DEFERRED.sessioncsrf).toContain("refused");
		expect(DEFERRED.apicsrf).toContain("refused");
	});
});

describe("hostcheck", () => {
	it("accepts local names and IP literals", () => {
		for (const host of ["localhost", "localhost:9091", "127.0.0.1:9091", "[::1]:9091", "app.localhost"]) {
			expect(splitHost(host), host).not.toBeNull();
		}
		expect(run("hostcheck", "/any", { headers: { host: "localhost:9091" } })).toBeNull();
	});

	it("refuses an unknown name with 421 and legacy's text, which never repeats the name", () => {
		const response = run("hostcheck", "/any", { headers: { host: "evil.example" } });
		expect(response?.status).toBe(421);
		expect(response?.headers.get("content-type")).toBe("text/plain; charset=UTF-8");
		expect(response?.headers.get("x-frame-options")).toBe("SAMEORIGIN");
		return response?.text().then((body) => {
			expect(body).toBe(HOST_REFUSAL);
			expect(body).not.toContain("evil");
		});
	});

	it("accepts PUBLIC_URL's host, and only that host", () => {
		expect(run("hostcheck", "/any", { headers: { host: "apotheke.example" } }, HTTPS)).toBeNull();
		expect(run("hostcheck", "/any", { headers: { host: "other.example" } }, HTTPS)?.status).toBe(421);
	});
});

describe("originalsguard", () => {
	it("answers 404 without security headers for any spelling of /originals/", () => {
		for (const path of ["/originals/1.jpg", "/media/originals/1.jpg", "/ORIGINALS/x", "/a/originals"]) {
			const response = run("originalsguard", path);
			expect(response?.status, path).toBe(404);
			expect(response?.headers.get("x-frame-options")).toBeNull();
		}
	});

	it("passes a path that only looks like it", () => {
		expect(run("originalsguard", "/originalsx/1.jpg")).toBeNull();
	});
});

describe("headasget and publicbase", () => {
	it("turns HEAD into GET and leaves everything else", () => {
		const head = createContext(new Request("http://localhost/x", { method: "HEAD" }), CONFIG);
		FILTERS.headasget?.before?.(head);
		expect(head.method).toBe("GET");
		const post = createContext(new Request("http://localhost/x", { method: "POST" }), CONFIG);
		FILTERS.headasget?.before?.(post);
		expect(post.method).toBe("POST");
	});

	it("derives the base URL from PUBLIC_URL, or from the request when it is not set", () => {
		const local = createContext(new Request("http://localhost:9091/x"), CONFIG);
		FILTERS.publicbase?.before?.(local);
		expect(local.baseUrl).toBe("http://localhost:9091");
		const configured = createContext(new Request("http://localhost/x"), HTTPS);
		FILTERS.publicbase?.before?.(configured);
		expect(configured.baseUrl).toBe("https://apotheke.example");
	});
});

describe("posttoolarge", () => {
	it("refuses a body past MAX_UPLOAD_MB with 413 and the PHP code", async () => {
		const response = run("posttoolarge", "/api/v1/imports", {
			method: "POST",
			headers: { "content-length": String(21 * 1024 * 1024) },
		});
		expect(response?.status).toBe(413);
		if (response === null) {
			throw new Error("expected a refusal");
		}
		const body = (await response.json()) as { code: string; detail: string };
		expect(body.code).toBe("upload.too_large");
		expect(body.detail).toContain("20 MB");
	});

	it("passes a body within the limit, and a request with no body at all", () => {
		expect(
			run("posttoolarge", "/save", { method: "POST", headers: { "content-length": "1024" } }),
		).toBeNull();
		expect(run("posttoolarge", "/", { method: "GET" })).toBeNull();
	});
});

describe("sameorigin", () => {
	it("refuses a cross-site write with 403", () => {
		expect(
			run("sameorigin", "/theme", { method: "POST", headers: { "sec-fetch-site": "cross-site" } })?.status,
		).toBe(403);
	});

	it("passes a same-origin write, a safe method, and a client that sends nothing", () => {
		expect(
			run("sameorigin", "/theme", { method: "POST", headers: { "sec-fetch-site": "same-origin" } }),
		).toBeNull();
		expect(run("sameorigin", "/theme", { method: "GET" })).toBeNull();
		expect(run("sameorigin", "/theme", { method: "POST" })).toBeNull();
	});

	it("checks Origin when the browser sends no Sec-Fetch-Site", () => {
		expect(
			run("sameorigin", "/theme", { method: "POST", headers: { origin: "http://localhost" } }),
		).toBeNull();
		expect(
			run("sameorigin", "/theme", { method: "POST", headers: { origin: "http://other.example" } })?.status,
		).toBe(403);
	});
});

describe("permission", () => {
	it("passes a route that answers before the check: `none` and the API's `public`", () => {
		expect(matchRoute("GET", "/login")?.permission).toBe("none");
		expect(run("permission", "/login")).toBeNull();
		expect(run("permission", "/api/v1/session")).toBeNull();
	});

	it("refuses a route that needs a session with 401 problem+json and a stable code", async () => {
		const response = run("permission", "/item/1");
		expect(response?.status).toBe(401);
		if (response === null) {
			throw new Error("expected a refusal");
		}
		expect(await response.json()).toEqual({
			type: "about:blank",
			title: "Unauthorized",
			status: 401,
			detail: "Sign in first.",
			code: "auth.required",
		});
	});

	it("refuses a route with no permission recorded to everyone (fail closed)", async () => {
		const ctx = createContext(new Request("http://localhost/item/1"), CONFIG);
		const route = ctx.route;
		if (route === null) {
			throw new Error("expected a route");
		}
		const patched = { ...ctx, route: { ...route, permission: null } };
		const response = FILTERS.permission?.before?.(patched) ?? null;
		expect(response?.status).toBe(403);
		if (response === null) {
			throw new Error("expected a refusal");
		}
		const body = (await response.json()) as { code: string };
		expect(body.code).toBe("permission.denied");
	});
});

describe("the CSRF filters", () => {
	it("refuse an unsafe request that cannot be checked, rather than allow it", () => {
		expect(run("sessioncsrf", "/theme", { method: "POST" })?.status).toBe(403);
		expect(run("apicsrf", "/api/v1/settings", { method: "POST" })?.status).toBe(403);
	});

	it("pass safe methods through", () => {
		expect(run("sessioncsrf", "/theme")).toBeNull();
		expect(run("apicsrf", "/api/v1/settings")).toBeNull();
	});
});

describe("the after filters", () => {
	const applyAfter = (name: string, path: string, response: Response, config = CONFIG): Response => {
		const filter = FILTERS[name];
		if (filter?.after === undefined) {
			throw new Error(`no after filter ${name}`);
		}
		return filter.after(createContext(new Request(`http://localhost${path}`), config), response);
	};

	it("securityheaders adds the page's four headers", () => {
		const out = applyAfter(
			"securityheaders",
			"/item/1",
			new Response("<html>", { headers: { "content-type": "text/html" } }),
		);
		expect(out.headers.get("x-content-type-options")).toBe("nosniff");
		expect(out.headers.get("x-frame-options")).toBe("SAMEORIGIN");
		expect(out.headers.get("referrer-policy")).toBe("same-origin");
		expect(out.headers.get("content-security-policy")).toContain("default-src 'self'");
	});

	it("securityheaders leaves a problem+json answer alone, and keeps a stricter CSP", () => {
		const problem = new Response("{}", {
			status: 404,
			headers: { "content-type": "application/problem+json" },
		});
		expect(applyAfter("securityheaders", "/x", problem).headers.get("x-frame-options")).toBeNull();
		const own = new Response("css", {
			headers: { "content-security-policy": "default-src 'none'", "content-type": "text/css" },
		});
		expect(applyAfter("securityheaders", "/theme.css", own).headers.get("content-security-policy")).toBe(
			"default-src 'none'",
		);
	});

	it("securityheaders drops the Content-Type of a bodyless answer", () => {
		const out = applyAfter(
			"securityheaders",
			"/x",
			new Response(null, { status: 204, headers: { "content-type": "text/html" } }),
		);
		expect(out.headers.get("content-type")).toBeNull();
	});

	it("api answers a preflight with 204 and gives /api answers the API header set", () => {
		expect(run("api", "/api/v1/session", { method: "OPTIONS" })?.status).toBe(204);
		expect(run("api", "/item/1", { method: "OPTIONS" })).toBeNull();
		const out = applyAfter(
			"api",
			"/api/v1/session",
			new Response("{}", { headers: { "content-type": "application/json" } }),
			HTTPS,
		);
		expect(out.headers.get("referrer-policy")).toBe("no-referrer");
		expect(out.headers.get("strict-transport-security")).toBe("max-age=31536000");
		expect(out.headers.get("cross-origin-resource-policy")).toBe("same-site");
		expect(out.headers.get("x-frame-options")).toBeNull();
		const plain = applyAfter(
			"api",
			"/item/1",
			new Response("{}", { headers: { "content-type": "application/json" } }),
		);
		expect(plain.headers.get("referrer-policy")).toBeNull();
	});
});

describe("routemethod", () => {
	it("passes a route that matches", () => {
		expect(run("routemethod", "/item/1")).toBeNull();
	});

	it("answers 405 with Allow for a path that exists under another verb", () => {
		const response = run("routemethod", "/item/1", { method: "GET" });
		expect(response).toBeNull();
		const wrong = createContext(new Request("http://localhost/item/1", { method: "POST" }), CONFIG);
		const refusal = FILTERS.routemethod?.before?.(wrong) ?? null;
		expect(refusal?.status).toBe(405);
		expect(refusal?.headers.get("allow")).toBe("GET, HEAD");
	});

	it("answers 404 problem+json for a path no route serves, and for a doubled slash under /api", async () => {
		const pages = createContext(new Request("http://localhost/nope"), CONFIG);
		expect(FILTERS.routemethod?.before?.(pages)?.status).toBe(404);
		const api = createContext(new Request("http://localhost/api//v1/x"), CONFIG);
		const refusal = FILTERS.routemethod?.before?.(api) ?? null;
		expect(refusal?.status).toBe(404);
		if (refusal === null) {
			throw new Error("expected a refusal");
		}
		const body = (await refusal.json()) as { code: string };
		expect(body.code).toBe("not_found");
	});
});
