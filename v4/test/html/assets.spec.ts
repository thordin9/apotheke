// The served assets (plan §5 S1.1): byte-identical to `public/assets/`, with the headers the
// shipped web server sends.
//
// "Byte-identical" is asserted by hash, and the hash is not invented here: `bundle.generated.ts`
// records the SHA-256 of the file it embedded, computed from `public/assets/` when the bundle was
// built. `npm run assets:check` (CI) fails when regenerating the bundle would change it, so the two
// links in the chain are: bundle = file (checked by the generator) and answer = bundle (checked here).

import { SELF } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import { BUNDLE } from "../../src/assets/bundle.generated";

const hex = async (bytes: ArrayBuffer): Promise<string> =>
	[...new Uint8Array(await crypto.subtle.digest("SHA-256", bytes))]
		.map((byte) => byte.toString(16).padStart(2, "0"))
		.join("");

const get = (path: string, method = "GET") => SELF.fetch(`http://localhost${path}`, { method });

describe("/assets/…", () => {
	it("serves every bundled file with its own bytes", async () => {
		for (const entry of BUNDLE) {
			const response = await get(`/assets/${entry.name}`);
			const body = await response.arrayBuffer();
			expect(response.status, entry.name).toBe(200);
			expect(response.headers.get("content-type"), entry.name).toBe(entry.contentType);
			expect(response.headers.get("cache-control"), entry.name).toBe("public, max-age=31536000, immutable");
			expect(response.headers.get("x-content-type-options"), entry.name).toBe("nosniff");
			expect(body.byteLength, entry.name).toBe(entry.size);
			expect(await hex(body), entry.name).toBe(entry.sha256);
		}
	});

	it("carries the CSS and JS content types the docs promise", async () => {
		expect((await get("/assets/app.css")).headers.get("content-type")).toBe("text/css; charset=utf-8");
		expect((await get("/assets/app.js")).headers.get("content-type")).toBe("text/javascript; charset=utf-8");
	});

	it("answers HEAD with the headers and no body", async () => {
		const response = await get("/assets/app.css", "HEAD");
		expect(response.status).toBe(200);
		expect(response.headers.get("content-length")).toBe("80562");
		expect(await response.text()).toBe("");
	});

	it("answers a miss with an uncacheable 404 and nosniff", async () => {
		for (const path of ["/assets/nope.css", "/assets/sub/app.css"]) {
			const response = await get(path);
			expect(response.status, path).toBe(404);
			expect(response.headers.get("cache-control"), path).toBe("no-store");
			expect(response.headers.get("x-content-type-options"), path).toBe("nosniff");
			expect(response.headers.get("content-type"), path).toBe("text/plain; charset=utf-8");
		}
	});

	it("serves nothing outside the bundle, whatever the path spelling", async () => {
		// A URL parser collapses `..` before the Worker sees it, so this is the page 404 that v3's
		// web server would answer too: no repository file is reachable through /assets/.
		const response = await get("/assets/../package.json");
		expect(response.status).toBe(404);
		expect(response.headers.get("content-type")).toBe("text/html; charset=UTF-8");
	});

	it("answers before the readiness gate: static bytes need no database", async () => {
		// The suite's installation boots ready, so this is about ordering rather than a 503; the
		// assertion that matters is that nothing above goes through the filter chain — an
		// `/assets/…` request never gets a page header set or a 421 from `hostcheck`.
		const response = await SELF.fetch("http://apotheke-v4.test/assets/app.css");
		expect(response.status).toBe(200);
		expect(response.headers.get("x-frame-options")).toBeNull();
		expect(response.headers.get("content-security-policy")).toBeNull();
	});
});
