import { SELF } from "cloudflare:test";
import { describe, expect, it } from "vitest";

describe("/healthz", () => {
	it("answers ok without a database backend", async () => {
		const res = await SELF.fetch("http://apotheke-v4.test/healthz");
		expect(res.status).toBe(200);
		expect(await res.json()).toMatchObject({ ok: true });
	});

	it("reports the configured driver", async () => {
		const res = await SELF.fetch("http://apotheke-v4.test/healthz");
		// wrangler.jsonc sets DB_DRIVER=sqlite for local dev and CI; S0.7/S0.8 keep the var name.
		expect(await res.json()).toMatchObject({ driver: "sqlite" });
	});

	it("404s anything else as problem+json (the router's 404, S0.5)", async () => {
		// `localhost` on purpose: `hostcheck` accepts local names and IP literals, and the harness
		// host `apotheke-v4.test` is not PUBLIC_URL's, so it would get 421 (correctly) first.
		const res = await SELF.fetch("http://localhost/nope");
		expect(res.status).toBe(404);
		expect(res.headers.get("content-type")).toBe("application/problem+json");
		expect(await res.json()).toMatchObject({ type: "about:blank", status: 404, code: "not_found" });
	});
});
