// The chain's order (plan §5 S0.5): asserted by running it, not by reading it.
//
// `routeRequest()` returns the filters that actually ran, in order. This test spells the expected
// order out as a literal — the order plan §5 and `app/Config/Filters.php` agree on — and compares. A
// filter moved, dropped or added fails here whether or not `chain.ts` was edited to match, and the
// parity test separately proves the literal is the PHP app's order.
//
// It also proves the runtime chain and the generated table cannot drift: for all 281 rows, the chain
// a request on that path runs is the list the row points at.

import { describe, expect, it } from "vitest";
import { beforeChain } from "../../src/http/chain";
import { matchPath } from "../../src/http/route-table";
import { type RouterConfig, routeRequest } from "../../src/http/router";
import { CHAINS, ROUTES } from "../../src/http/routes.generated";
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

const request = (path: string, method = "GET"): Request => new Request(`http://localhost${path}`, { method });

/** The fourteen required filters, then globals, then the per-path list — CI4's order, spelled out. */
const REQUIRED = [
	"opsbackup",
	"opsaccounts",
	"headasget",
	"harness",
	"originalsguard",
	"hostcheck",
	"publicbase",
	"api",
	"routemethod",
	"posttoolarge",
	"consent",
	"session",
	"sameorigin",
	"permission",
];

const AFTER = ["after:securityheaders", "after:requestcleanup", "after:api", "after:consent"];

describe("the filter chain a request runs", () => {
	it("refuses at `permission` on a page route that needs a session, before the globals", async () => {
		const routed = await routeRequest(request("/item/1"), CONFIG);
		// `permission` is required, so it runs before the globals: the CSRF filters never see this one.
		expect(routed.trace).toEqual([...REQUIRED, "after:requestcleanup", "after:api", "after:consent"]);
		expect(routed.response.status).toBe(401);
	});

	it("runs the globals when the permission check passes, and reaches the handler", async () => {
		const routed = await routeRequest(request("/login"), CONFIG);
		expect(routed.trace).toEqual([...REQUIRED, "formfields", ...AFTER]);
		// `/login` renders since S1.1; `/register` is the same chain without a handler.
		expect(routed.response.status).toBe(200);
		const unported = await routeRequest(request("/register"), CONFIG);
		expect(unported.trace).toEqual([...REQUIRED, "formfields", ...AFTER]);
		expect(unported.response.status).toBe(501);
	});

	it("runs the globals-minus-except list, then apicsrf last, on an /api route", async () => {
		const routed = await routeRequest(request("/api/v1/session"), CONFIG);
		expect(routed.trace).toEqual([
			...REQUIRED,
			// `sessioncsrf` is excepted for api and api/*, `formfields` too; `apicsrf` is the path rule.
			"apicsrf",
			...AFTER,
		]);
	});

	it("keeps formfields but drops sessioncsrf on /login, which checks its own token", async () => {
		const routed = await routeRequest(request("/login"), CONFIG);
		expect(routed.trace).toEqual([...REQUIRED, "formfields", ...AFTER]);
	});

	it("skips the globals after filters on a refusal, but keeps the required ones", async () => {
		const routed = await routeRequest(request("/item/1"), CONFIG);
		expect(routed.trace.filter((name) => name.startsWith("after:"))).toEqual([
			"after:requestcleanup",
			"after:api",
			"after:consent",
		]);
	});

	it("turns HEAD into GET in headasget, before routing", async () => {
		const head = await routeRequest(request("/api/v1/session", "HEAD"), CONFIG);
		const get = await routeRequest(request("/api/v1/session", "GET"), CONFIG);
		expect(head.trace).toEqual(get.trace);
		expect(head.response.status).toBe(get.response.status);
	});
});

describe("the table and the runtime chain", () => {
	it("agree on every one of the rows", async () => {
		for (const route of ROUTES) {
			const expected = CHAINS[route.filters];
			if (expected === undefined) {
				throw new Error(`${route.method} ${route.path}: no chain ${route.filters}`);
			}
			// CI4 matches the URI without its slashes; `beforeChain` applies the same trimming.
			expect(beforeChain(route.path), `${route.method} ${route.path}`).toEqual([...expected]);
		}
	});

	it("has no path whose chain is empty", async () => {
		for (const route of ROUTES) {
			expect(beforeChain(route.path).length, route.path).toBeGreaterThanOrEqual(14);
		}
	});

	it("starts every chain with the required filters in the order the plan pins", async () => {
		for (const route of ROUTES) {
			expect(beforeChain(route.path).slice(0, REQUIRED.length), route.path).toEqual(REQUIRED);
		}
	});

	it("applies apicsrf to /api and nothing else", async () => {
		expect(beforeChain("/api/v1/session")).toContain("apicsrf");
		expect(beforeChain("/api")).toContain("apicsrf");
		expect(beforeChain("/apix")).not.toContain("apicsrf");
		expect(beforeChain("/")).not.toContain("apicsrf");
	});

	it("trims the path the way CI4 does before matching the except lists", async () => {
		expect(beforeChain("/api/")).not.toContain("sessioncsrf");
		expect(matchPath("/api/")).toBe("api");
	});
});
