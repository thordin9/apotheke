#!/usr/bin/env node
// Checks a running getapotheke-app (`wrangler dev` in edge/app) against frontend/dist:
//  - responses the Worker makes (/app/..., /config.json, /fonts/...) carry the same `/*` headers as
//    frontend/dist/_headers and, under /app/, exactly its `/app/*` Content-Security-Policy;
//  - routing: /app -> 301 /app/, SPA fallback only under /app/, 404 outside it, config.json, caching.
// Usage: node scripts/app-worker-check.mjs http://127.0.0.1:8788 frontend/dist
import { readFileSync, existsSync, readdirSync } from "node:fs";

const [base, dist] = process.argv.slice(2);
if (!base || !dist) {
	console.error("usage: app-worker-check.mjs BASE_URL DIST_DIR");
	process.exit(2);
}
let failures = 0;
const fail = (m) => {
	failures++;
	console.log(`FAIL ${m}`);
};
const ok = (m) => console.log(`ok   ${m}`);
const expect = (cond, m) => (cond ? ok(m) : fail(m));

// _headers: rules of "path" then indented "Name: value" or "! Name" lines.
function parseHeaders(text) {
	const rules = new Map();
	let cur = null;
	for (const line of text.split("\n")) {
		if (!line.trim() || line.trimStart().startsWith("#")) continue;
		if (!/^\s/.test(line)) {
			cur = new Map();
			rules.set(line.trim(), cur);
		} else if (cur && !line.trim().startsWith("!")) {
			const i = line.indexOf(":");
			cur.set(line.slice(0, i).trim().toLowerCase(), line.slice(i + 1).trim());
		}
	}
	return rules;
}

const hfile = `${dist}/_headers`;
const rules = existsSync(hfile) ? parseHeaders(readFileSync(hfile, "utf8")) : null;
const all = rules?.get("/*") ?? new Map();
const appCsp = rules?.get("/app/*")?.get("content-security-policy");
if (!rules) console.log("note: no _headers in dist (stub build); header mirroring not compared");

const stripSub = (v) =>
	v
		.split(";")
		.map((d) => d.trim())
		.filter((d) => d && d.toLowerCase() !== "includesubdomains")
		.join("; ");

async function get(path, method = "GET") {
	return fetch(base + path, { method, redirect: "manual" });
}

function mirrors(res, path, csp) {
	// Present on every Worker response, with or without a _headers to compare against (N-1414).
	for (const k of ["strict-transport-security", "x-content-type-options", "x-frame-options", "content-security-policy"]) {
		expect(Boolean(res.headers.get(k)), `${path} has ${k}`);
	}
	// HSTS: the Worker never sends includeSubDomains (PM decision: HSTS for getapotheke.com only), so it is
	// compared with includeSubDomains removed from _headers, and any includeSubDomains from the Worker fails.
	const hsts = res.headers.get("strict-transport-security") ?? "";
	expect(!/includesubdomains/i.test(hsts), `${path} HSTS has no includeSubDomains`);
	for (const [k, v] of all) {
		if (k === "content-security-policy" || k === "cache-control") continue;
		const want = k === "strict-transport-security" ? stripSub(v) : v;
		expect(res.headers.get(k) === want, `${path} ${k} matches _headers /*`);
	}
	if (csp) expect(res.headers.get("content-security-policy") === csp, `${path} CSP is exactly _headers /app/*`);
}

let r = await get("/app");
expect(r.status === 301 && r.headers.get("location") === "/app/", "/app -> 301 /app/");
mirrors(r, "/app", appCsp);

const spaIndex = readFileSync(`${dist}/app/index.html`, "utf8");
for (const p of ["/app/", "/app/items/5", "/app/settings/backup"]) {
	r = await get(p);
	const body = await r.text();
	expect(r.status === 200 && body === spaIndex, `${p} -> 200 /app/index.html`);
	expect(r.headers.get("cache-control") === "no-cache", `${p} no-cache`);
	expect(r.headers.get("strict-transport-security")?.startsWith("max-age="), `${p} HSTS`);
	mirrors(r, p, appCsp);
	if (!appCsp) expect(/script-src 'self'/.test(r.headers.get("content-security-policy") ?? ""), `${p} SPA CSP`);
}
r = await get("/app/assets/does-not-exist.js");
expect(r.status === 404, "/app/assets/does-not-exist.js -> 404 (no SPA fallback for files)");
mirrors(r, "404 /app/assets/does-not-exist.js", appCsp);
r = await get("/app/items/5", "HEAD");
expect(r.status === 200, "HEAD /app/items/5 -> 200");
mirrors(r, "HEAD /app/items/5", appCsp);
r = await get("/fonts/does-not-exist.woff2");
expect(r.status === 404 && r.headers.get("cache-control") === "no-cache", "/fonts/does-not-exist.woff2 -> 404, not cached");
mirrors(r, "404 /fonts/does-not-exist.woff2");

r = await get("/config.json");
const cfg = await r.json();
expect(r.status === 200 && cfg.apiBase === "https://api.services.getapotheke.com/api/v1", "/config.json apiBase");
expect(r.headers.get("cache-control") === "no-cache", "/config.json no-cache");
mirrors(r, "/config.json");

for (const p of ["/app/", "/config.json", "/fonts/x.woff2"]) {
	r = await get(p, "POST");
	expect(r.status === 405, `POST ${p} -> 405`);
	mirrors(r, `405 ${p}`, p.startsWith("/app/") ? appCsp : undefined);
}

if (existsSync(`${dist}/fonts`)) {
	const font = readdirSync(`${dist}/fonts`).find((f) => f.endsWith(".woff2"));
	if (font) {
		r = await get(`/fonts/${font}`);
		expect(r.status === 200 && r.headers.get("cache-control") === "public, max-age=86400", `/fonts/${font} one day`);
		mirrors(r, `/fonts/${font}`);
	}
}

// Served by the assets with _headers (not the Worker): routing only, and the landing CSP is not the SPA's.
r = await get("/");
expect(r.status === 200, "/ -> 200");
if (appCsp) expect(r.headers.get("content-security-policy") !== appCsp, "/ does not get the SPA CSP");
if (existsSync(`${dist}/privacy/index.html`)) {
	r = await get("/privacy/");
	expect(r.status === 200, "/privacy/ -> 200");
}
r = await get("/no-such-page/");
expect(r.status === 404, "/no-such-page/ -> 404 (no SPA fallback outside /app/)");
r = await get("/imprint/");
expect(r.status === 404, "/imprint/ -> 404");

console.log(failures ? `${failures} failure(s)` : "all checks passed");
process.exit(failures ? 1 : 0);
