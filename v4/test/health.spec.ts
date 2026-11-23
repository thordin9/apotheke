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

	it("404s anything else as json", async () => {
		const res = await SELF.fetch("http://apotheke-v4.test/nope");
		expect(res.status).toBe(404);
		expect(res.headers.get("content-type")).toContain("application/json");
	});
});
