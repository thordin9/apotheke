// The two paths answered before the router, and nothing else (plan §5 S0.5 as ruled in revision 16,
// §11 rule 7).
//
// `/healthz` and `/readyz` are probes, not routes: a 421 from `hostcheck` on the container probe would
// restart-loop a healthy stack, which is the whole reason rule 7 exists. The ruling also requires the
// bypass to stay *exactly* two paths. That is asserted two ways, because they fail differently:
//
//   1. the list is a constant, and this file pins its contents — a third entry fails here;
//   2. every route in the table is requested with a Host that `hostcheck` rejects, and every one must
//      answer 421 — so no *route* can bypass the chain;
//   3. the entrypoint's own source is read, and every comparison against `pathname` must be one of the
//      two constants. That is the one that catches a third path hard-coded beside them, which the
//      route sweep cannot see because such a path is not in the table.

import { SELF } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import { PROBE_PATHS, READYZ_PATH } from "../../src/http/probe-paths";
import { ROUTES } from "../../src/http/routes.generated";
// The entrypoint's own text: the sweep below proves no *route* bypasses the chain, and this proves
// no third path was hard-coded beside the two (a path that is not in the table would escape the
// sweep silently, which is the hole the ruling asks to close).
// Scope, stated so nobody reads this as total: it covers `src/index.ts` only. A third path
// hard-coded in a module the entry calls before the chain is invisible to both arms — whoever
// moves a `pathname` comparison out of the entry carries this `?raw` target with it.
// @ts-expect-error Vite's `?raw` suffix has no type declaration for this import
import workerSource from "../../src/index.ts?raw";
import { samplePath } from "../helpers/paths";

/** Not `PUBLIC_URL`'s host and not a local name, so `hostcheck` refuses it. */
const REJECTED = "http://evil.example";

const UNCONSTRUCTABLE = new Set(["CONNECT", "TRACE"]);

describe("the probe bypass", () => {
	it("is exactly /healthz and /readyz", () => {
		expect(PROBE_PATHS).toEqual(["/healthz", "/readyz"]);
	});

	it("answers /healthz before the chain, with a Host the chain would refuse", async () => {
		const res = await SELF.fetch(`${REJECTED}/healthz`);
		expect(res.status).toBe(200);
		expect(await res.json()).toMatchObject({ ok: true });
	});

	it("answers /readyz before the chain, with a Host the chain would refuse", async () => {
		const res = await SELF.fetch(`${REJECTED}${READYZ_PATH}`);
		// Ready or not is the boot gate's answer; the point is that `hostcheck` never sees it.
		expect(res.status).not.toBe(421);
		expect([200, 503]).toContain(res.status);
	});

	it("leaves every route in the table to the chain, so nothing else bypasses", async () => {
		const bypassing: string[] = [];
		for (const route of ROUTES) {
			if (UNCONSTRUCTABLE.has(route.method)) {
				continue;
			}
			const path = `/${samplePath(route.path)}`;
			// `/healthz` is both a probe and a row in the table (v3 declares it); it is the one route
			// whose request legitimately never reaches the chain.
			if ((PROBE_PATHS as readonly string[]).includes(path)) {
				continue;
			}
			const res = await SELF.fetch(`${REJECTED}${path}`, { method: route.method });
			if (res.status !== 421) {
				bypassing.push(`${route.method} ${path} → ${res.status}`);
			}
		}
		expect(bypassing).toEqual([]);
	}, 60_000);

	it("compares the path against nothing but the two probe constants", () => {
		const comparisons = [
			...workerSource.matchAll(/pathname\s*(?:===|!==|==|!=)\s*([A-Za-z_$][\w$]*|"[^"]*"|'[^']*')/g),
		].map((m) => m[1]);
		expect(comparisons.sort()).toEqual(["HEALTHZ_PATH", "READYZ_PATH"]);

		const prefixTests = [
			...workerSource.matchAll(/pathname\s*\.\s*(startsWith|endsWith|includes|match|indexOf|lastIndexOf)/g),
		];
		expect(prefixTests.map((m) => m[1])).toEqual([]);
	});

	it("keeps the legacy /health alias in the chain", async () => {
		expect(PROBE_PATHS as readonly string[]).not.toContain("/health");
		const res = await SELF.fetch(`${REJECTED}/health`);
		expect(res.status).toBe(421);
	});
});
