// Everything still unported still says so (plan §5 S1.1's corrected DoD).
//
// The slice renders `/login` and nothing else. Every other page route that does not need a session —
// permission `none` or `public` — must keep answering `501 route.not_implemented`. This is asserted
// as a list built from the generated table, so a route that quietly starts answering 200 fails here
// rather than being noticed by a reader; and because the list comes from the table, a route added to
// `Routes.php` arrives in it without anyone editing this file.

import { SELF } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import { isApi } from "../../src/http/problem";
import { ROUTES } from "../../src/http/routes.generated";

/** The page routes that reach a handler without a session: GET (HEAD answers as GET), no permission. */
const unported = ROUTES.filter(
	(route) =>
		route.method === "GET" &&
		!route.catchAll &&
		!isApi(route.path) &&
		route.name !== "login" &&
		// `/healthz` is answered before the router (D14, §11 rule 7), as it has been since S0.3.
		route.name !== "healthz" &&
		(route.permission === "none" || route.permission === "public"),
);

/** A concrete path for a route pattern, as `router.spec.ts` does. */
const sample = (pattern: string): string =>
	`/${pattern
		.split("/")
		.map((segment) => (segment.startsWith("(") ? "x" : segment))
		.join("/")}`;

describe("the unported page routes", () => {
	it("are still a non-trivial list", () => {
		// Guards against the assertion below going vacuous if the filter above stops matching.
		expect(unported.length).toBeGreaterThan(5);
		expect(unported.map((route) => route.name)).toContain("register");
	});

	it("answer 501 route.not_implemented, not 200 and not an exception", async () => {
		for (const route of unported) {
			const response = await SELF.fetch(`http://localhost${sample(route.path)}`);
			const label = `${route.name} (${route.path})`;
			expect(response.status, label).toBe(501);
			const body = (await response.json()) as { code?: string; detail?: string };
			expect(body.code, label).toBe("route.not_implemented");
			expect(body.detail, label).toContain(route.handler);
		}
	});

	it("do not 501 for /login, which renders", async () => {
		const response = await SELF.fetch("http://localhost/login");
		expect(response.status).toBe(200);
		expect(response.headers.get("content-type")).toBe("text/html; charset=UTF-8");
	});
});
