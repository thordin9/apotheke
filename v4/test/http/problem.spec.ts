// The `/api` envelope: JSON in UTF-8, RFC 9457 problem+json, and the API header set (plan §5 S1.7;
// v3 `ApiResponseTest`, 6 methods).
//
// v3's `ApiResponse::json()` / `::problem()` / `::isApi()` (`modules/Core/Http/ApiResponse.php`) and
// `ApiSecurityPolicy::headers()` (`ApiSecurityPolicy.php`) are v4's `json()` / `problem()` /
// `isApi()` / `apiHeaders()` (`src/http/problem.ts`), and the assertions are v3's: the shapes
// themselves, not an answer routed through a filter. What the chain makes of these answers is
// `router.spec.ts`'s and `refusal-cache.spec.ts`'s subject, asserted end to end.
//
// Three differences from v3, none of them a shape change:
//
//   * `isApi()` here takes a path with or without its leading slash, where v3 compared `'/api'` and
//     the `'/api/'` prefix. Every routed path starts with the slash, so the widening decides nothing
//     the chain sees — and the nine paths v3 tested answer as they did.
//   * `json()` carries the whole `/api` header set, where v3 set only `Content-Type` and
//     `Cache-Control` and left the rest to `ApiSecurityPolicy` in an after filter. Same response.
//   * `Retry-After` is a header on `problem()`'s `extra` record, not v3's sixth positional integer
//     parameter; the value sent is the same string.

import { describe, expect, it } from "vitest";
import { apiHeaders, httpsOf, isApi, json, problem } from "../../src/http/problem";

describe("the /api envelope", () => {
	it("answers UTF-8 JSON, never cached, leaving slashes and non-ASCII alone", async () => {
		const response = json({ path: "/a/ß" }, 201);
		expect(response.status).toBe(201);
		expect(response.headers.get("content-type")).toBe("application/json; charset=utf-8");
		expect(response.headers.get("cache-control")).toBe("no-store");
		// Byte for byte, PHP's `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
		expect(await response.text()).toBe('{"path":"/a/ß"}');
	});

	it("carries type, title, status, detail and code, in PHP's order", async () => {
		const response = problem("/api/v1/session", false, 403, "csrf.invalid", "The CSRF check failed.");
		expect(response.status).toBe(403);
		expect(response.headers.get("content-type")).toBe("application/problem+json");
		expect(response.headers.get("cache-control")).toBe("no-store");
		expect(await response.text()).toBe(
			'{"type":"about:blank","title":"Forbidden","status":403,' +
				'"detail":"The CSRF check failed.","code":"csrf.invalid"}',
		);
	});

	it("lists the fields a 422 is about, and no other answer carries `errors`", async () => {
		const response = problem("/api/v1/users", false, 422, "validation.failed", "Check the fields.", {
			errors: { username: "Required." },
		});
		expect(response.status).toBe(422);
		const body = (await response.json()) as { title: string; errors: Record<string, string> };
		expect(body.title).toBe("Unprocessable Content");
		expect(body.errors).toEqual({ username: "Required." });
		// v3's `if ($errors !== [])`: an empty map is no field, not an empty object.
		const noFields = problem("/api/v1/users", false, 422, "validation.failed", "Check the fields.", {
			errors: {},
		});
		expect(await noFields.json()).not.toHaveProperty("errors");
	});

	it("sends Retry-After for a rate limit", () => {
		const response = problem("/api/v1/session", false, 429, "rate_limited", "Too many attempts.", {
			extra: { "Retry-After": "60" },
		});
		expect(response.headers.get("retry-after")).toBe("60");
	});

	it("counts /api and everything under it as the API, and nothing else", () => {
		for (const path of ["/api", "/api/", "/api/v1/session", "/api/x"]) {
			expect(isApi(path), path).toBe(true);
		}
		for (const path of ["/", "/apiary", "/item/1", "/api-docs", ""]) {
			expect(isApi(path), path).toBe(false);
		}
	});

	// v3 `PlainHandlerTest::testApiErrorHstsFollowsTheConfiguredPublicUrl`: the HSTS input is the
	// request's scheme, or a `PUBLIC_URL` that says https in any case — and an unset one counts as no.
	it("counts a PUBLIC_URL that says https, in any case, and nothing else", () => {
		const https = (url: string, publicUrl?: string): boolean =>
			publicUrl === undefined ? httpsOf(new URL(url)) : httpsOf(new URL(url), publicUrl);
		expect(https("https://apotheke.test/")).toBe(true);
		expect(https("http://apotheke.test/", "https://inv.example.test")).toBe(true);
		expect(https("http://apotheke.test/", "HTTPS://inv.example.test")).toBe(true);
		expect(https("http://apotheke.test/", "http://inv.example.test")).toBe(false);
		expect(https("http://apotheke.test/", "")).toBe(false);
		expect(https("http://apotheke.test/")).toBe(false);
	});

	it("locks the /api response down, and adds HSTS only over https", () => {
		expect(apiHeaders(false)).toEqual({
			"Content-Security-Policy": "default-src 'none'; frame-ancestors 'none'; sandbox",
			"X-Content-Type-Options": "nosniff",
			"Referrer-Policy": "no-referrer",
			"Cross-Origin-Resource-Policy": "same-site",
			"Cross-Origin-Opener-Policy": "same-origin",
			"Cache-Control": "no-store",
		});
		expect(apiHeaders(true)["Strict-Transport-Security"]).toBe("max-age=31536000");
	});
});
