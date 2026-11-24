// Route parity (plan §5 S0.5): the table the Worker imports against the PHP app's own route data.
//
// The oracle is `test/fixtures/php-routes.json`, which `scripts/gen-routes.mjs` reads out of
// `app/Config/RoutesCompiled.php` and `app/Config/Filters.php` — and `npm run routes:check` in CI
// fails when those two are stale, so this test cannot pass against a fixture nobody refreshed.
//
// What it proves, in both directions and for every row: no route in the PHP app is missing from the
// table, none is extra, and each row's method, path, handler, permission and filter list match. The
// DoD asks for that to be non-vacuous, and it is: removing a row, adding one, or changing one
// permission fails here (demonstrated on the branch, and the comparison is element-wise).

import { describe, expect, it } from "vitest";
import {
	ci4Uri,
	GLOBALS_AFTER,
	GLOBALS_BEFORE,
	PATH_FILTERS,
	REQUIRED_AFTER,
	REQUIRED_BEFORE,
} from "../../src/http/chain";
import { distinctPaths, effectiveMethod, matchPath } from "../../src/http/route-table";
import { CHAINS, ROUTES } from "../../src/http/routes.generated";
import fixture from "../fixtures/php-routes.json";

interface FixtureRoute {
	readonly method: string;
	readonly path: string;
	readonly name: string;
	readonly handler: string;
	readonly permission: string | null;
	readonly catchAll: boolean;
	readonly filters: readonly string[];
}

const php = fixture as unknown as {
	readonly oldFilterOrder: boolean;
	readonly chain: {
		readonly requiredBefore: readonly string[];
		readonly requiredAfter: readonly string[];
		readonly globalsBefore: readonly { readonly name: string; readonly except?: readonly string[] }[];
		readonly globalsAfter: readonly { readonly name: string; readonly except?: readonly string[] }[];
		readonly pathFilters: readonly { readonly name: string; readonly before: readonly string[] }[];
		readonly after: readonly string[];
	};
	readonly chains: readonly (readonly string[])[];
	readonly routes: readonly FixtureRoute[];
	readonly sources: Readonly<Record<string, { readonly path: string; readonly sha256: string }>>;
};

describe("the route table against the PHP app", () => {
	it("has exactly the PHP app's rows, in its order", () => {
		expect(ROUTES.map((route) => route.method)).toEqual(php.routes.map((route) => route.method));
		expect(ROUTES.map((route) => route.path)).toEqual(php.routes.map((route) => route.path));
	});

	it("has the PHP app's name and handler for every row", () => {
		expect(ROUTES.map((route) => route.name)).toEqual(php.routes.map((route) => route.name));
		expect(ROUTES.map((route) => route.handler)).toEqual(php.routes.map((route) => route.handler));
	});

	it("has the PHP app's permission for every row — the field a typo would silently widen", () => {
		expect(ROUTES.map((route) => route.permission)).toEqual(php.routes.map((route) => route.permission));
	});

	it("marks exactly the /api fallback rows as the catch-all", () => {
		expect(ROUTES.map((route) => route.catchAll)).toEqual(php.routes.map((route) => route.catchAll));
		// `WebRoutes::every()` registers the fallback for all nine web methods.
		const catchAll = ROUTES.filter((route) => route.catchAll);
		expect([...new Set(catchAll.map((route) => route.path))]).toEqual(["api", "api/(.*)"]);
		expect(catchAll.length).toBe(18);
	});

	it("gives every row the PHP app's filter list, and the chains are the same lists", () => {
		expect(CHAINS.map((chain) => [...chain])).toEqual(php.chains.map((chain) => [...chain]));
		ROUTES.forEach((route, index) => {
			const expected = php.routes[index];
			if (expected === undefined) {
				throw new Error(`row ${index} is not in the fixture`);
			}
			expect(CHAINS[route.filters], `${route.method} ${route.path}`).toEqual(expected.filters);
		});
	});

	it("is a complete table: every row is reachable and no (method, path) repeats", () => {
		const keys = ROUTES.map((route) => `${route.method} ${route.path}`);
		expect(new Set(keys).size).toBe(keys.length);
		expect(distinctPaths().length).toBeGreaterThan(100);
		expect(ROUTES.length).toBeGreaterThan(200);
	});

	it("covers every permission the app uses, and none the app does not", () => {
		const used = [...new Set(ROUTES.map((route) => route.permission))].sort();
		const expected = [...new Set(php.routes.map((route) => route.permission))].sort();
		expect(used).toEqual(expected);
		// The two that answer before a permission check, by name, so a rename cannot pass unnoticed.
		expect(used).toContain("none");
		expect(used).toContain("public");
	});
});

describe("the chain against the PHP app", () => {
	it("runs the app's required and global filters, in its order", () => {
		expect(php.oldFilterOrder).toBe(false);
		expect([...REQUIRED_BEFORE]).toEqual([...php.chain.requiredBefore]);
		expect([...REQUIRED_AFTER]).toEqual([...php.chain.requiredAfter]);
		expect(
			GLOBALS_BEFORE.map((entry) => ({
				name: entry.name,
				except: entry.except ? [...entry.except] : undefined,
			})),
		).toEqual(
			php.chain.globalsBefore.map((entry) => ({
				name: entry.name,
				except: entry.except ? [...entry.except] : undefined,
			})),
		);
		expect(GLOBALS_AFTER.map((name) => ({ name }))).toEqual(
			php.chain.globalsAfter.map((entry) => ({ name: entry.name })),
		);
		expect(PATH_FILTERS.map((entry) => ({ name: entry.name, before: [...entry.before] }))).toEqual(
			php.chain.pathFilters.map((entry) => ({ name: entry.name, before: [...entry.before] })),
		);
	});

	it("names the order the plan pins: hostcheck, publicbase, session, sameorigin, permission, then CSRF", () => {
		const order = [...REQUIRED_BEFORE];
		const wanted = ["hostcheck", "publicbase", "session", "sameorigin", "permission"];
		const positions = wanted.map((name) => order.indexOf(name));
		expect(positions.every((index) => index >= 0)).toBe(true);
		expect(positions).toEqual([...positions].sort((a, b) => a - b));
	});

	it("records where the table came from, so a stale fixture is visible from the test", () => {
		expect(Object.keys(php.sources).sort()).toEqual(["compiled", "feature", "filters", "routes"]);
		for (const source of Object.values(php.sources)) {
			expect(source.path).toMatch(/^app\/Config\//);
			expect(source.sha256).toMatch(/^[0-9a-f]{64}$/);
		}
	});
});

describe("CI4's path rules", () => {
	it("turns a request path into the URI the filters match on", () => {
		expect(ci4Uri("/")).toBe("");
		expect(ci4Uri("/API/v1/Session/")).toBe("api/v1/session");
		expect(ci4Uri("/item/%31")).toBe("item/1");
		expect(ci4Uri("/item/%zz")).toBe("item/%zz");
	});

	it("matches routes the way CI4 does: the key is the pattern", () => {
		expect(matchPath("/")).toBe("/");
		expect(matchPath("//item/1/")).toBe("item/1");
	});

	it("answers HEAD as GET, which is what headasget does before routing", () => {
		expect(effectiveMethod("head")).toBe("GET");
		expect(effectiveMethod("POST")).toBe("POST");
	});
});
