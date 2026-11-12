// Tests for the deploy tooling that need no network and no Cloudflare account (node --test, Node 22):
//  - the getapotheke-app Worker (edge/app/src/index.ts, loaded with Node's type stripping) with a fake ASSETS
//    binding: headers on its own 500, 405 and 301, caching of 200 and 304 responses (N-1435);
//  - scripts/app-worker-check.mjs fails when dist has no _headers (unless STUB_DIST=1);
//  - scripts/cf-worker-guard.mjs: the allowlist, and no other wrangler config next to or above it (L-1431);
//  - scripts/cf-deploy.sh: guard first, then `wrangler deploy --config wrangler.jsonc` (a fake npx records it).
//  - each deploy job's tag step, run from the workflow with a fake `gh`: main, or vX.Y.Z with a published full
//    release (L-1460).
//  - the apotheke-api Durable Object (edge/src/index.ts, with a stub for cloudflare:workers and a fake container):
//    the container gets every `vars` value of edge/wrangler.jsonc except SLEEP_AFTER_MS.
//  - edge/tests/r2.test.mjs mocks the public R2 binding; there are no real credentials or resources.
//  - scripts/edge-storage-config.mjs: merges edge/storage.jsonc into a copy of edge/wrangler.jsonc that the guard
//    still accepts; a missing or malformed APOTHEKE_D1_DATABASE_ID changes nothing. deploy-edge.yml's check job
//    dry-runs that merge with a dummy D1 id and no secret.
// Run: node --test --experimental-strip-types scripts/deploy-scripts.test.mjs edge/tests/r2.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { register } from "node:module";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const { default: worker } = await import(join(root, "edge/app/src/index.ts"));
const env = (fetchImpl) => ({ API_BASE: "https://api.example.test/api/v1", ASSETS: { fetch: fetchImpl } });
const call = (path, init = {}, assets = async () => new Response("x")) =>
	worker.fetch(new Request(`https://getapotheke.com${path}`, init), env(assets));
const SECURITY = ["strict-transport-security", "x-content-type-options", "referrer-policy", "x-frame-options", "permissions-policy"];
function secured(res, csp) {
	for (const h of SECURITY) assert.ok(res.headers.get(h), `${h} present`);
	assert.equal(res.headers.get("strict-transport-security"), "max-age=31536000");
	assert.match(res.headers.get("content-security-policy"), csp);
}
const APP_CSP = /^default-src 'none'; script-src 'self';.*connect-src 'self' https:\/\/api\.services\.getapotheke\.com https:\/\/analytics\.services\.webapp\.gr;/;
const DATA_CSP = /^default-src 'none'; frame-ancestors 'none'/;

test("a failing asset fetch is a 500 with every security header, never cached", async () => {
	for (const [path, csp] of [["/app/items/5", APP_CSP], ["/fonts/a.woff2", DATA_CSP]]) {
		const res = await call(path, {}, async () => {
			throw new Error("boom");
		});
		assert.equal(res.status, 500);
		secured(res, csp);
		assert.equal(res.headers.get("cache-control"), "no-store");
	}
});

test("the Worker's own 405 and 301 carry the headers", async () => {
	let res = await call("/app/", { method: "POST" });
	assert.equal(res.status, 405);
	assert.equal(res.headers.get("allow"), "GET, HEAD");
	secured(res, APP_CSP);
	res = await call("/app?x=1");
	assert.equal(res.status, 301);
	assert.equal(res.headers.get("location"), "/app/?x=1");
	secured(res, APP_CSP);
	assert.equal(res.headers.get("cache-control"), "no-cache");
});

test("config.json comes from API_BASE, no-cache", async () => {
	const res = await call("/config.json");
	assert.deepEqual(await res.json(), { apiBase: "https://api.example.test/api/v1" });
	assert.equal(res.headers.get("cache-control"), "no-cache");
	secured(res, DATA_CSP);
});

test("caching: fonts a day on 200 and 304; everything else under /app/ no-cache, nothing immutable (N-1435)", async () => {
	const status = (s) => async () => new Response(s === 304 ? null : "x", { status: s });
	assert.equal((await call("/fonts/a.woff2", {}, status(200))).headers.get("cache-control"), "public, max-age=86400");
	assert.equal((await call("/fonts/a.woff2", {}, status(304))).headers.get("cache-control"), "public, max-age=86400");
	assert.equal((await call("/fonts/a.woff2", {}, status(404))).headers.get("cache-control"), "no-cache");
	for (const s of [200, 304]) {
		for (const p of ["/app/", "/app/assets/x.js"]) {
			assert.equal((await call(p, {}, status(s))).headers.get("cache-control"), "no-cache", `${s} ${p}`);
		}
	}
});

test("SPA fallback only for paths without an extension under /app/", async () => {
	const seen = [];
	const assets = async (req) => {
		const p = new URL(req.url).pathname;
		seen.push(p);
		return p === "/app/" ? new Response("index") : new Response("nf", { status: 404 });
	};
	assert.equal((await call("/app/items/5", {}, assets)).status, 200);
	assert.equal((await call("/app/assets/x.js", {}, assets)).status, 404);
	assert.equal((await call("/app/x%2Ejs", {}, assets)).status, 200);
	assert.equal((await call("/app/%E0", {}, assets)).status, 200);
	assert.deepEqual(seen, ["/app/items/5", "/app/", "/app/assets/x.js", "/app/x%2Ejs", "/app/", "/app/%E0", "/app/"]);
});

const run = (cmd, args, opts = {}) => spawnSync(cmd, args, { encoding: "utf8", ...opts });

test("the drift check fails when dist has no _headers, unless STUB_DIST=1", () => {
	const dist = mkdtempSync(join(tmpdir(), "dist-"));
	try {
		mkdirSync(join(dist, "app"));
		writeFileSync(join(dist, "app/index.html"), "<!doctype html>");
		const check = join(root, "scripts/app-worker-check.mjs");
		const r = run("node", [check, "http://127.0.0.1:9", dist], { env: { ...process.env, STUB_DIST: "" } });
		assert.equal(r.status, 1);
		assert.match(r.stdout, /_headers is missing/);
		// With STUB_DIST=1 it gets past that and tries the server (nothing listens on port 9).
		const s = run("node", [check, "http://127.0.0.1:9", dist], { env: { ...process.env, STUB_DIST: "1" } });
		assert.match(s.stdout, /no _headers in the stub dist/);
	} finally {
		rmSync(dist, { recursive: true, force: true });
	}
});

function workerDir(name = "apotheke-api", extra = "") {
	const top = mkdtempSync(join(tmpdir(), "guard-"));
	const dir = join(top, "edge");
	mkdirSync(dir);
	writeFileSync(
		join(dir, "wrangler.jsonc"),
		`// comment\n{ "name": "${name}", "main": "x.ts", "workers_dev": false, "preview_urls": false, ${extra} }\n`,
	);
	return { top, dir };
}
const guard = (...args) => run("node", [join(root, "scripts/cf-worker-guard.mjs"), ...args], { env: { ...process.env, CLOUDFLARE_ENV: "", WRANGLER_ENV: "" } });

test("guard: allowlisted name in a lone wrangler.jsonc passes", () => {
	const { top, dir } = workerDir();
	try {
		const r = guard("apotheke-api", join(dir, "wrangler.jsonc"));
		assert.equal(r.status, 0, r.stdout);
		assert.match(r.stdout, /deploying "apotheke-api"/);
	} finally {
		rmSync(top, { recursive: true, force: true });
	}
});

test("guard: refuses other names, routes, --config and other wrangler configs (L-1431)", () => {
	const cases = [
		[workerDir("apotheke-autofill"), ["apotheke-autofill"], /not a Worker this repository deploys/],
		[workerDir("flaremo"), ["apotheke-api"], /not on the allowlist/],
		[workerDir("getapotheke-app"), ["apotheke-api"], /this job deploys only "apotheke-api"/],
		[workerDir("apotheke-api", '"routes": [],'), ["apotheke-api"], /has "routes"/],
		[workerDir("apotheke-api", '"env": {},'), ["apotheke-api"], /"env" sections/],
		[workerDir(), ["apotheke-api", "--", "--config=other.jsonc"], /--config is not allowed/],
		[workerDir(), ["apotheke-api", "--", "--name", "x"], /--name is not allowed/],
	];
	for (const [{ top, dir }, [name, ...rest], msg] of cases) {
		try {
			const r = guard(name, join(dir, "wrangler.jsonc"), ...rest);
			assert.equal(r.status, 1, `${name} ${rest.join(" ")}`);
			assert.match(r.stdout, msg);
		} finally {
			rmSync(top, { recursive: true, force: true });
		}
	}
	for (const other of ["wrangler.json", "wrangler.toml", ".wrangler/deploy/config.json", "../.wrangler/deploy/config.json"]) {
		const { top, dir } = workerDir();
		try {
			mkdirSync(dirname(join(dir, other)), { recursive: true });
			writeFileSync(join(dir, other), "{}");
			const r = guard("apotheke-api", join(dir, "wrangler.jsonc"));
			assert.equal(r.status, 1, other);
			assert.match(r.stdout, /wrangler could read it instead|wrangler would follow this redirect/, other);
		} finally {
			rmSync(top, { recursive: true, force: true });
		}
	}
	const { top, dir } = workerDir();
	try {
		writeFileSync(join(dir, "other.jsonc"), readFileSync(join(dir, "wrangler.jsonc")));
		assert.equal(guard("apotheke-api", join(dir, "other.jsonc")).status, 1);
	} finally {
		rmSync(top, { recursive: true, force: true });
	}
});

function deploy(dir, npxScript, worker = "apotheke-api") {
	const bin = mkdtempSync(join(tmpdir(), "bin-"));
	const log = join(bin, "npx.log");
	writeFileSync(join(bin, "npx"), `#!/usr/bin/env bash\necho "$PWD|$*" >> "${log}"\n${npxScript}\n`);
	chmodSync(join(bin, "npx"), 0o755);
	const r = run("bash", [join(root, "scripts/cf-deploy.sh"), worker, dir], {
		env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, CLOUDFLARE_API_TOKEN: "placeholder-token", CLOUDFLARE_ACCOUNT_ID: "placeholder-account" },
	});
	const calls = existsSync(log) ? readFileSync(log, "utf8") : "";
	rmSync(bin, { recursive: true, force: true });
	return { ...r, calls };
}

test("cf-deploy.sh: guard, then wrangler deploy --config wrangler.jsonc in DIR", () => {
	const { top, dir } = workerDir();
	try {
		const r = deploy(dir, "exit 0");
		assert.equal(r.status, 0, r.stdout + r.stderr);
		assert.match(r.stdout, /Worker guard: deploying "apotheke-api"/);
		assert.equal(r.calls, `${dir}|--no-install wrangler deploy --config wrangler.jsonc\n`);
		assert.doesNotMatch(r.stdout + r.stderr, /placeholder-token|placeholder-account/);
	} finally {
		rmSync(top, { recursive: true, force: true });
	}
});

test("cf-deploy.sh: a refused guard never calls wrangler", () => {
	const { top, dir } = workerDir();
	try {
		writeFileSync(join(dir, "wrangler.json"), "{}");
		const r = deploy(dir, "exit 0");
		assert.equal(r.status, 1);
		assert.equal(r.calls, "");
		assert.equal(deploy(dir, "exit 0", "apotheke-autofill").calls, "");
	} finally {
		rmSync(top, { recursive: true, force: true });
	}
});

test("cf-deploy.sh: an authentication error names the missing permission", () => {
	const { top, dir } = workerDir();
	try {
		const r = deploy(dir, 'echo "Authentication error [code: 10000]"; exit 1');
		assert.equal(r.status, 1);
		assert.match(r.stdout, /::error title=Cloudflare refused the token::.*Containers > Edit/);
	} finally {
		rmSync(top, { recursive: true, force: true });
	}
});

// The deploy job's first step (L-1460, N-1438), taken from each workflow and run the way the runner runs a step
// with `shell: bash` (bash --noprofile --norc -eo pipefail; the step must say so, N-1465), with a fake `gh` that
// answers the release-by-tag API from FAKE_RELEASE (empty = a 404, which is also the answer for a draft: that
// endpoint returns only published releases) and applies the step's own --jq with jq.
function tagStep(file) {
	const yml = readFileSync(join(root, ".github/workflows", file), "utf8");
	const m = yml.match(/\n      - name: Tag is vX\.Y\.Z[^\n]*\n((?:        (?!run:)[^\n]*\n)*)        run: \|\n((?:(?:          [^\n]*)?\n)+)/);
	assert.ok(m, `${file}: the tag step has a run block`);
	assert.match(m[1], /^        shell: bash\n/m, `${file}: the tag step runs with shell: bash`);
	return m[2].replace(/^ {10}/gm, "");
}
function runTagStep(script, ref, release) {
	const bin = mkdtempSync(join(tmpdir(), "fake-gh-"));
	const calls = join(bin, "calls");
	writeFileSync(calls, "");
	writeFileSync(
		join(bin, "gh"),
		`#!/usr/bin/env bash
printf '%s\\n' "$*" >> "${calls}"
[ -n "$GH_TOKEN" ] || { echo "gh: no token" >&2; exit 4; }
[ -n "$FAKE_RELEASE" ] || { echo "gh: Not Found (HTTP 404)" >&2; exit 1; }
jq=""; while [ $# -gt 0 ]; do [ "$1" = --jq ] && jq="$2"; shift; done
printf '%s' "$FAKE_RELEASE" | jq -r "$jq"
`,
	);
	chmodSync(join(bin, "gh"), 0o755);
	const r = run("bash", ["--noprofile", "--norc", "-eo", "pipefail", "-c", script], {
		env: {
			PATH: `${bin}:${process.env.PATH}`,
			REF: ref,
			GH_TOKEN: "placeholder-read-only",
			GITHUB_REPOSITORY: "owner/repo",
			FAKE_RELEASE: release === undefined ? "" : JSON.stringify(release),
		},
	});
	const out = { ...r, calls: readFileSync(calls, "utf8") };
	rmSync(bin, { recursive: true, force: true });
	return out;
}
const FULL = { tag_name: "v1.2.3", draft: false, prerelease: false };

for (const file of ["deploy-frontend.yml", "deploy-edge.yml"]) {
	test(`${file}: the tag step allows main and published full releases only (L-1460)`, () => {
		const script = tagStep(file);
		let r = runTagStep(script, "refs/heads/main", undefined);
		assert.equal(r.status, 0, r.stdout + r.stderr);
		assert.equal(r.calls, "", "main needs no release");

		for (const ref of ["refs/heads/feature", "refs/pull/1/merge", "refs/tags/v1.2.3-rc.1", "refs/tags/v1.2", "refs/tags/x1.2.3"]) {
			r = runTagStep(script, ref, FULL);
			assert.equal(r.status, 1, ref);
			assert.equal(r.calls, "", `${ref}: refused before the API`);
		}

		r = runTagStep(script, "refs/tags/v1.2.3", FULL);
		assert.equal(r.status, 0, r.stdout + r.stderr);
		assert.match(r.calls, /^api repos\/owner\/repo\/releases\/tags\/v1\.2\.3 --jq /);
		assert.equal(r.calls.split("\n").filter(Boolean).length, 1, "one API call");

		r = runTagStep(script, "refs/tags/v1.2.3", undefined);
		assert.equal(r.status, 1);
		assert.match(r.stdout, /::error title=No published release::No published GitHub Release for v1\.2\.3/);

		for (const bad of [{ ...FULL, prerelease: true }, { ...FULL, draft: true }, { ...FULL, draft: true, prerelease: true }, { tag_name: "v1.2.3" }]) {
			r = runTagStep(script, "refs/tags/v1.2.3", bad);
			assert.equal(r.status, 1, JSON.stringify(bad));
			assert.match(r.stdout, /::error title=Not a full release::The release for v1\.2\.3 is a draft or a pre-release/);
		}
	});
}

// edge/src/index.ts imports cloudflare:workers, which Node lacks: resolve it to stub DurableObject / WorkerEntrypoint.
const STUB =
	"export class DurableObject { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }" +
	"export class WorkerEntrypoint { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }";
register(
	"data:text/javascript," +
		encodeURIComponent(
			`import { pathToFileURL } from "node:url";
import { existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
export async function resolve(s, c, next) {
  if (s === "cloudflare:workers") return { url: "data:text/javascript,${encodeURIComponent(STUB)}", shortCircuit: true };
  if (s.startsWith("./") && !s.endsWith(".ts") && c.parentURL) {
    const base = fileURLToPath(new URL(".", c.parentURL));
    const candidate = base + s.slice(2) + ".ts";
    if (existsSync(candidate)) return { url: pathToFileURL(candidate).href, shortCircuit: true };
  }
  return next(s, c);
}`,
		),
);

test("apotheke-api: the container gets every var of edge/wrangler.jsonc but SLEEP_AFTER_MS", async () => {
	const { ApothekeContainer } = await import(join(root, "edge/src/index.ts"));
	// edge/wrangler.jsonc has only whole-line comments.
	const text = readFileSync(join(root, "edge/wrangler.jsonc"), "utf8").replace(/^\s*\/\/.*$/gm, "");
	const { vars } = JSON.parse(text.replace(/,(\s*[}\]])/g, "$1"));
	assert.equal(vars.CORS_ALLOWED_ORIGINS, "https://getapotheke.com");
	let started;
	const intercepted = [];
	const waitUntil = [];
	const timeouts = [];
	let monitorCalls = 0;
	const container = {
		running: false,
		start(opts) {
			started = opts;
			this.running = true;
		},
		setInactivityTimeout: async (ms) => {
			timeouts.push(ms);
		},
		monitor: () => {
			monitorCalls += 1;
			return new Promise(() => {});
		},
		getTcpPort: () => ({ fetch: async () => new Response("ok") }),
		interceptOutboundHttp: async (host, fetcher) => {
			intercepted.push([host, fetcher]);
		},
	};
	const bridge = { fetch: async () => new Response("bridge") };
	const r2bridge = { fetch: async () => new Response("r2-unbound", { status: 503 }) };
	const ctx = {
		container,
		waitUntil(p) {
			waitUntil.push(p);
		},
		blockConcurrencyWhile: (f) => f(),
		exports: { D1Outbound: () => bridge, R2Outbound: () => r2bridge },
	};
	const obj = new ApothekeContainer(ctx, { ...vars, APP_PASSWORD: "placeholder", APP_KEY: "placeholder" });
	assert.equal((await obj.fetch(new Request("https://api.example.test/healthz"))).status, 200);
	const { SLEEP_AFTER_MS, ...forwarded } = vars;
	for (const [k, v] of Object.entries(forwarded)) assert.equal(started.env[k], v, k);
	// Wrangler vars (except SLEEP_AFTER_MS) plus TRUST_PROXY and APOTHEKE_EDGE_CLIENT_IP, set in code.
	assert.deepEqual(Object.keys(started.env).sort(), [
		"ALLOWED_HOSTS",
		"APOTHEKE_EDGE_CLIENT_IP",
		"APOTHEKE_JOBS_WORKER",
		"APOTHEKE_PHP_WORKER",
		"APP_KEY",
		"APP_PASSWORD",
		"COOKIE_DOMAIN",
		"CORS_ALLOWED_ORIGINS",
		"PORT",
		"TRUST_PROXY",
		"TZ",
	]);
	assert.equal(started.env.TRUST_PROXY, "1");
	assert.equal(started.env.APOTHEKE_EDGE_CLIENT_IP, "1");
	assert.equal(started.enableInternet, false);
	assert.deepEqual(
		intercepted.map(([h]) => h),
		["d1.internal", "r2.internal"],
		"d1.internal and r2.internal are wired before the container answers",
	);
	assert.equal(intercepted[0][1], bridge);
	assert.equal(intercepted[1][1], r2bridge);
	assert.equal((await r2bridge.fetch()).status, 503);
	assert.deepEqual(timeouts, [Number(SLEEP_AFTER_MS)]);
	assert.equal(monitorCalls, 0);
	assert.equal(waitUntil.length, 0);
});

// d1.internal handler (edge/src/d1.ts): same JSON shape as D1HttpExecutor / tools/d1-harness.
const { handleD1Request, d1UnboundResponse } = await import(join(root, "edge/src/d1.ts"));
function mockDb({ batch, all, exec } = {}) {
	const stmt = {
		bind: (..._p) => stmt,
		all: all ?? (async () => ({ results: [], meta: { changes: 0, last_row_id: 0 } })),
	};
	return {
		prepare: () => stmt,
		batch: batch ?? (async (stmts) => stmts.map(() => ({ results: [], meta: { changes: 1, last_row_id: 1 } }))),
		exec: exec ?? (async () => ({ count: 2 })),
	};
}
const d1Post = (path, body, db) =>
	handleD1Request(
		new Request(`http://d1.internal${path}`, {
			method: "POST",
			headers: { "content-type": "application/json" },
			body: JSON.stringify(body),
		}),
		db,
	);

test("d1.internal: query returns rows with ok true", async () => {
	const db = mockDb({
		all: async () => ({ results: [{ id: 1, name: "lamp" }], meta: { changes: 0, last_row_id: 0 } }),
	});
	const res = await d1Post("/query", { sql: "SELECT 1", params: [] }, db);
	assert.equal(res.status, 200);
	assert.deepEqual(await res.json(), {
		ok: true,
		rows: [{ id: 1, name: "lamp" }],
		changes: 0,
		last_row_id: 0,
	});
});

test("d1.internal: batch returns one result per statement", async () => {
	const db = mockDb({
		batch: async (stmts) => {
			assert.equal(stmts.length, 2);
			return [
				{ results: [{ n: 1 }], meta: { changes: 0, last_row_id: 0 } },
				{ results: [], meta: { changes: 1, last_row_id: 9 } },
			];
		},
	});
	const res = await d1Post(
		"/batch",
		[
			{ sql: "SELECT 1", params: [] },
			{ sql: "INSERT INTO t DEFAULT VALUES", params: [] },
		],
		db,
	);
	assert.equal(res.status, 200);
	assert.deepEqual(await res.json(), {
		ok: true,
		results: [
			{ rows: [{ n: 1 }], changes: 0, last_row_id: 0 },
			{ rows: [], changes: 1, last_row_id: 9 },
		],
	});
});

test("d1.internal: exec returns count; a guard failure returns only the allow-listed marker", async () => {
	const ok = await d1Post("/exec", { sql: "CREATE TABLE t(id)" }, mockDb({ exec: async () => ({ count: 3 }) }));
	assert.deepEqual(await ok.json(), { ok: true, count: 3 });
	const db = mockDb({
		all: async () => {
			throw Object.assign(new Error("D1_ERROR: NOT NULL constraint failed: uow_guard.ok: SQLITE_CONSTRAINT"), {
				cause: { message: "secret-cause-detail" },
				stack: "Error: ...\n at secretFile (/app/secret/path.js:1:1)",
			});
		},
	});
	const fail = await d1Post("/query", { sql: "SELECT 1", params: [] }, db);
	assert.equal(fail.status, 500);
	const body = await fail.json();
	assert.equal(body.ok, false);
	assert.equal(body.code, "guard_failed");
	assert.equal(body.error, "NOT NULL constraint failed: uow_guard.ok");
	// No raw cause, message or stack text in the body.
	const raw = JSON.stringify(body);
	assert.ok(!raw.includes("secret-cause-detail"), "no cause text");
	assert.ok(!raw.includes("SQLITE_CONSTRAINT"), "no driver suffix");
	assert.ok(!raw.includes("D1_ERROR"), "no driver prefix");
	assert.ok(!("cause" in body), "no cause field");
	assert.ok(!("stack" in body), "no stack field");
	assert.ok(!raw.includes("secret/path.js"), "no stack path");
});

test("d1.internal: method, path and unbound responses", async () => {
	const get = await handleD1Request(new Request("http://d1.internal/query"), mockDb());
	assert.equal(get.status, 405);
	assert.equal((await get.json()).ok, false);
	const missing = await d1Post("/nope", { sql: "SELECT 1" }, mockDb());
	assert.equal(missing.status, 404);
	const unbound = d1UnboundResponse();
	assert.equal(unbound.status, 503);
	assert.deepEqual(await unbound.json(), { ok: false, code: "unbound", error: "D1 is not bound" });
});

test("d1.internal: a non-guard failure returns a generic body with no message, cause or stack", async () => {
	const db = mockDb({
		all: async () => {
			throw Object.assign(new Error("SQL logic error: no such table: hidden_table at offset 12"), {
				cause: { message: "inner-detail" },
				stack: "Error: no such table\n at prepare (/app/internal/db.js:9:9)",
			});
		},
	});
	const res = await d1Post("/query", { sql: "SELECT * FROM hidden_table", params: [] }, db);
	assert.equal(res.status, 500);
	const body = await res.json();
	assert.equal(body.ok, false);
	assert.equal(body.code, "write_failed");
	assert.equal(body.error, "the batch");
	const raw = JSON.stringify(body);
	for (const needle of ["hidden_table", "SQL logic error", "inner-detail", "db.js", "no such table"]) {
		assert.ok(!raw.includes(needle), `body must not contain ${needle}`);
	}
	assert.ok(!("cause" in body) && !("stack" in body) && !("message" in body));
});

test("d1.internal: guard detection through a nested cause chain returns only the marker", async () => {
	const db = mockDb({
		batch: async () => {
			throw Object.assign(new Error("D1 batch failed"), {
				cause: { cause: { message: "NOT NULL constraint failed: uow_guard.ok at offset 7" } },
			});
		},
	});
	const res = await d1Post("/batch", [{ sql: "INSERT INTO t VALUES (NULL)", params: [] }], db);
	assert.equal(res.status, 500);
	const body = await res.json();
	assert.equal(body.code, "guard_failed");
	assert.equal(body.error, "NOT NULL constraint failed: uow_guard.ok");
	assert.ok(!JSON.stringify(body).includes("D1 batch failed"));
	assert.ok(!JSON.stringify(body).includes("offset 7"));
});

// N-1793: the harness launcher must run wrangler in local mode only (never --remote).
test("tools/d1-harness: the launcher forces --local and never passes --remote", () => {
	const sh = readFileSync(join(root, "tools/d1-harness/run.sh"), "utf8");
	assert.ok(/wrangler\.js dev --local\b/.test(sh), "wrangler dev runs with --local");
	assert.ok(!sh.includes("--remote"), "the launcher never selects remote mode");
	assert.ok(/127\.0\.0\.1/.test(sh), "the launcher binds to loopback");
	const cfg = readFileSync(join(root, "tools/d1-harness/wrangler.jsonc"), "utf8");
	assert.ok(cfg.includes("00000000-0000-0000-0000-000000000000"), "the database id is the zero placeholder");
});

// Runs the merge on a copy of edge/wrangler.jsonc (or of `config`, an object) with edge/storage.jsonc (or `storage`).
const readJsonc = (p) => JSON.parse(readFileSync(p, "utf8").replace(/^\s*\/\/.*$/gm, "").replace(/,(\s*[}\]])/g, "$1"));
function storageMerge(id, { config, storage } = {}) {
	const top = mkdtempSync(join(tmpdir(), "storage-"));
	const dir = join(top, "edge");
	mkdirSync(dir);
	const cfg = join(dir, "wrangler.jsonc");
	writeFileSync(cfg, config ? JSON.stringify(config) : readFileSync(join(root, "edge/wrangler.jsonc")));
	let frag = join(root, "edge/storage.jsonc");
	if (storage) {
		frag = join(dir, "storage.jsonc");
		writeFileSync(frag, JSON.stringify(storage));
	}
	const env = { ...process.env, APOTHEKE_D1_DATABASE_ID: id };
	if (id === undefined) delete env.APOTHEKE_D1_DATABASE_ID;
	const r = run("node", [join(root, "scripts/edge-storage-config.mjs"), cfg, frag], { env });
	return { r, top, cfg };
}
const UUID = "00000000-0000-4000-8000-000000000000";
const DEFAULT_CONFIG = () => readJsonc(join(root, "edge/wrangler.jsonc"));
const STORAGE = () => readJsonc(join(root, "edge/storage.jsonc"));
function refused(id, opts, msg) {
	const { r, top, cfg } = storageMerge(id, opts);
	try {
		const before = opts.config ? JSON.stringify(opts.config) : readFileSync(join(root, "edge/wrangler.jsonc"), "utf8");
		assert.equal(r.status, 1, r.stdout + r.stderr);
		assert.match(r.stdout, msg);
		assert.equal(readFileSync(cfg, "utf8"), before, "the config is left as it was");
	} finally {
		rmSync(top, { recursive: true, force: true });
	}
}

// N-1540: each re-merge guard on its own (a config that trips only that guard).
test("edge-storage-config: a config that already has d1_databases (but no v2 migration) is refused", () => {
	const config = DEFAULT_CONFIG();
	config.d1_databases = [{ binding: "OTHER_DB", database_name: "other", database_id: UUID }];
	refused(UUID, { config }, /already has "d1_databases"/);
});

test("edge-storage-config: a config that already has migration v2 (but no storage keys) is refused", () => {
	const config = DEFAULT_CONFIG();
	config.migrations = [...config.migrations, { tag: "v2", new_sqlite_classes: ["Other"] }];
	refused(UUID, { config }, /already has migration tag "v2"/);
});

test("edge-storage-config: D1 DB, R2 STORAGE (eu) and the Installation Durable Object, and the guard still passes", () => {
	const id = "00000000-0000-4000-8000-000000000000";
	const { r, top, cfg } = storageMerge(id);
	try {
		assert.equal(r.status, 0, r.stdout + r.stderr);
		const c = JSON.parse(readFileSync(cfg, "utf8"));
		assert.deepEqual(c.d1_databases, [{ binding: "DB", database_name: "apotheke-api", database_id: id, migrations_dir: "migrations/control" }]);
		assert.deepEqual(c.r2_buckets, [{ binding: "STORAGE", bucket_name: "apotheke-api-storage", jurisdiction: "eu" }]);
		assert.deepEqual(c.durable_objects.bindings, [
			{ name: "APOTHEKE", class_name: "ApothekeContainer" },
			{ name: "INSTALLATION", class_name: "Installation" },
		]);
		assert.deepEqual(c.migrations, [
			{ tag: "v1", new_sqlite_classes: ["ApothekeContainer"] },
			{ tag: "v2", new_sqlite_classes: ["Installation"] },
		]);
		assert.equal(c.name, "apotheke-api");
		assert.equal(c.containers.length, 1);
		// hosted mode: no COOKIE_DOMAIN for the container (ADR-002, decision 5, #203); the other vars stay
		assert.equal("COOKIE_DOMAIN" in c.vars, false);
		// N-1543: the SPA is served from getapotheke.com today, so the hosted config keeps its CORS origin
		assert.equal(c.vars.CORS_ALLOWED_ORIGINS, "https://getapotheke.com");
		assert.equal(c.vars.ALLOWED_HOSTS, "api.services.getapotheke.com");
		assert.equal(c.vars.APOTHEKE_JOBS_WORKER, "0");
		assert.equal(c.vars.APOTHEKE_PHP_WORKER, "0");
		assert.equal(guard("apotheke-api", cfg).status, 0);
		// a second merge (a re-run on the same checkout) is refused rather than doubling the bindings
		const again = run("node", [join(root, "scripts/edge-storage-config.mjs"), cfg, join(root, "edge/storage.jsonc")], {
			env: { ...process.env, APOTHEKE_D1_DATABASE_ID: id },
		});
		assert.equal(again.status, 1);
	} finally {
		rmSync(top, { recursive: true, force: true });
	}
});

test("edge-storage-config: no or a bad APOTHEKE_D1_DATABASE_ID fails and leaves the config as it is", () => {
	const before = readFileSync(join(root, "edge/wrangler.jsonc"), "utf8");
	for (const id of [undefined, "", "<APOTHEKE_D1_DATABASE_ID>", "not-a-uuid"]) {
		const { r, top, cfg } = storageMerge(id);
		try {
			assert.equal(r.status, 1, String(id));
			assert.match(r.stdout, /::error title=Edge storage config::.*APOTHEKE_D1_DATABASE_ID/);
			assert.equal(readFileSync(cfg, "utf8"), before);
		} finally {
			rmSync(top, { recursive: true, force: true });
		}
	}
});

test("the default edge/wrangler.jsonc has no storage bindings (they apply only behind EDGE_STORAGE_READY)", () => {
	const text = readFileSync(join(root, "edge/wrangler.jsonc"), "utf8").replace(/^\s*\/\/.*$/gm, "");
	const c = JSON.parse(text.replace(/,(\s*[}\]])/g, "$1"));
	assert.equal(c.d1_databases, undefined);
	assert.equal(c.r2_buckets, undefined);
	assert.deepEqual(c.durable_objects.bindings.map((b) => b.class_name), ["ApothekeContainer"]);
	assert.deepEqual(c.migrations.map((m) => m.tag), ["v1"]);
	assert.equal(c.vars.COOKIE_DOMAIN, "getapotheke.com", "the default (stateless) config keeps it");
});

// ADR-002: the pull request checks dry-run the merged config too, with a dummy D1 id and without any secret, after
// the default dry run (the merge rewrites edge/wrangler.jsonc on the runner's checkout).
test("deploy-edge.yml: the check job dry-runs the merged storage config with a dummy D1 id and no secret", () => {
	const yml = readFileSync(join(root, ".github/workflows/deploy-edge.yml"), "utf8");
	const check = yml.slice(yml.indexOf("\n  check:\n"), yml.indexOf("\n  deploy:\n"));
	assert.ok(check.length > 0, "the check job is found");
	assert.ok(!/secrets\./.test(check), "the check job reads no secret");
	assert.ok(!/vars\./.test(check), "the check job reads no repository variable");
	const merged = check.indexOf("- name: Merged storage config dry run");
	const plain = check.indexOf("- name: wrangler deploy --dry-run");
	assert.ok(plain > 0 && merged > plain, "the default dry run stays, and the merged one runs after it");
	const step = check.slice(merged);
	assert.match(step, /APOTHEKE_D1_DATABASE_ID: 00000000-0000-4000-8000-000000000000\n/);
	assert.match(step, /node scripts\/edge-storage-config\.mjs edge\/wrangler\.jsonc edge\/storage\.jsonc\n/);
	assert.match(step, /node scripts\/cf-worker-guard\.mjs apotheke-api edge\/wrangler\.jsonc -- --dry-run\n/);
	assert.match(step, /wrangler deploy --dry-run --config wrangler\.jsonc/);
	assert.match(step, /env\.CORS_ALLOWED_ORIGINS \("https:\/\/getapotheke\.com"\)/);
	assert.match(step, /env\.COOKIE_DOMAIN/);
	assert.equal(/- name: /.test(step.slice(1)), false, "it is the last step of the check job");
	// the dummy id is a UUID the merge accepts
	const { r, top } = storageMerge("00000000-0000-4000-8000-000000000000");
	rmSync(top, { recursive: true, force: true });
	assert.equal(r.status, 0, r.stdout + r.stderr);
});

// N-1541: the fragment allows only its known keys, and no duplicate or colliding names.
test("edge-storage-config: unknown keys in storage.jsonc are refused", () => {
	refused(UUID, { storage: { ...STORAGE(), kv_namespaces: [{ binding: "KV", id: "x" }] } }, /unknown key "kv_namespaces"/);
	refused(UUID, { storage: { ...STORAGE(), vars: { X: "1" } } }, /unknown key "vars"/);
	const storage = STORAGE();
	storage.durable_objects = { ...storage.durable_objects, migrations: [] };
	refused(UUID, { storage }, /unknown key "durable_objects\.migrations"/);
});

test("edge-storage-config: duplicate Durable Object binding or class names are refused", () => {
	let storage = STORAGE();
	storage.durable_objects.bindings.push({ name: "INSTALLATION", class_name: "Other" });
	refused(UUID, { storage }, /binding name "INSTALLATION" is already used/);
	storage = STORAGE();
	storage.durable_objects.bindings.push({ name: "INSTALLATION_2", class_name: "Installation" });
	refused(UUID, { storage }, /class "Installation" already has a binding/);
	storage = STORAGE();
	storage.durable_objects.bindings = [{ name: "SECOND", class_name: "ApothekeContainer" }];
	storage.migrations = [];
	refused(UUID, { storage }, /class "ApothekeContainer" already has a binding/);
	storage = STORAGE();
	storage.migrations = [{ tag: "v2", new_sqlite_classes: ["ApothekeContainer"] }];
	refused(UUID, { storage }, /class "ApothekeContainer" is already created by a migration/);
});

test("edge-storage-config: names that collide with the Worker's existing bindings, vars or secrets are refused", () => {
	for (const name of ["APOTHEKE", "ALLOWED_HOSTS", "APP_KEY"]) {
		const storage = STORAGE();
		storage.r2_buckets = [{ ...storage.r2_buckets[0], binding: name }];
		refused(UUID, { storage }, new RegExp(`binding name "${name}" is already used`));
	}
	const config = DEFAULT_CONFIG();
	config.kv_namespaces = [{ binding: "DB", id: "x" }];
	refused(UUID, { config }, /binding name "DB" is already used/);
	const storage = STORAGE();
	storage.r2_buckets = [{ ...storage.r2_buckets[0], binding: "DB" }];
	refused(UUID, { storage }, /binding name "DB" is already used/);
});

// N-1544: fragment migrations allow only tag and new_sqlite_classes.
test("edge-storage-config: fragment migrations with any field besides tag and new_sqlite_classes are refused", () => {
	const extra = {
		deleted_classes: ["ApothekeContainer"],
		new_classes: ["Other"],
		renamed_classes: [{ from: "ApothekeContainer", to: "Other" }],
		transferred_classes: [{ from: "A", from_script: "b", to: "C" }],
		unknown: true,
	};
	for (const [key, value] of Object.entries(extra)) {
		const storage = STORAGE();
		storage.migrations = [{ ...storage.migrations[0], [key]: value }];
		refused(UUID, { storage }, new RegExp(`migration "v2" has "${key}"; fragment migrations allow only tag and new_sqlite_classes`));
	}
	const storage = STORAGE();
	storage.migrations = [{ tag: "v2" }];
	refused(UUID, { storage }, /needs a string tag and a new_sqlite_classes list/);
});

// N-1547: fragment migrations create at least one class, and every fragment binding's class is created by one.
test("edge-storage-config: empty fragment migrations and binding classes no fragment migration creates are refused", () => {
	let storage = STORAGE();
	storage.migrations = [{ tag: "v2", new_sqlite_classes: [] }];
	refused(UUID, { storage }, /migration "v2" has an empty new_sqlite_classes list/);
	storage = STORAGE();
	storage.durable_objects = { bindings: [{ name: "INSTALLATION", class_name: "Other" }] };
	refused(UUID, { storage }, /class "Other" is not created by a fragment migration/);
	storage = STORAGE();
	storage.migrations = [];
	refused(UUID, { storage }, /class "Installation" is not created by a fragment migration/);
});

// N-1545: drop_vars is limited to COOKIE_DOMAIN, which must be in the base vars; ALLOWED_HOSTS is never dropped.
test("edge-storage-config: drop_vars allows only COOKIE_DOMAIN, present in the base vars, and never ALLOWED_HOSTS", () => {
	refused(UUID, { storage: { ...STORAGE(), drop_vars: ["COOKIE_DOMAIN", "ALLOWED_HOSTS"] } }, /may never drop ALLOWED_HOSTS/);
	refused(UUID, { storage: { ...STORAGE(), drop_vars: ["ALLOWED_HOSTS"] } }, /may never drop ALLOWED_HOSTS/);
	refused(UUID, { storage: { ...STORAGE(), drop_vars: ["CORS_ALLOWED_ORIGINS"] } }, /may only list COOKIE_DOMAIN, not "CORS_ALLOWED_ORIGINS"/);
	refused(UUID, { storage: { ...STORAGE(), drop_vars: "COOKIE_DOMAIN" } }, /drop_vars must be a list/);
	const config = DEFAULT_CONFIG();
	delete config.vars.COOKIE_DOMAIN;
	refused(UUID, { config }, /drop_vars lists "COOKIE_DOMAIN", which .* does not set/);
});

test("edge/storage.jsonc sets migrations_dir to migrations/control on the apotheke-api binding", () => {
	const storage = STORAGE();
	assert.equal(storage.d1_databases.length, 1);
	assert.equal(storage.d1_databases[0].database_name, "apotheke-api");
	assert.equal(storage.d1_databases[0].migrations_dir, "migrations/control");
});

// E1: control-plane migrations run in the deploy job only, after the storage merge (so wrangler.jsonc has
// migrations_dir) and before wrangler deploy. The check job never applies them (no token, dummy D1 id).
test("deploy-edge.yml: the deploy job applies control-plane D1 migrations before wrangler deploy", () => {
	const yml = readFileSync(join(root, ".github/workflows/deploy-edge.yml"), "utf8");
	const check = yml.slice(yml.indexOf("\n  check:\n"), yml.indexOf("\n  deploy:\n"));
	const deploy = yml.slice(yml.indexOf("\n  deploy:\n"));
	assert.ok(check.length > 0 && deploy.length > 0, "the check and deploy jobs are found");
	assert.ok(!/migrations apply/.test(check), "the check job never applies D1 migrations");
	const merge = deploy.indexOf("- name: Storage config");
	const migrate = deploy.indexOf("- name: Apply control-plane D1 migrations");
	const wranglerDeploy = deploy.indexOf("- name: Deploy (Worker guard");
	assert.ok(merge > 0 && migrate > merge && wranglerDeploy > migrate, "merge, then apply, then wrangler deploy");
	const step = deploy.slice(migrate, wranglerDeploy);
	assert.equal((step.match(/- name: /g) || []).length, 1, "it is a single step between the merge and the deploy");
	assert.match(step, /if: steps\.token\.outputs\.set == 'true'\n/);
	assert.match(step, /working-directory: edge\n/);
	assert.match(step, /CLOUDFLARE_API_TOKEN: \$\{\{ secrets\.CLOUDFLARE_API_TOKEN \}\}/);
	assert.match(step, /CLOUDFLARE_ACCOUNT_ID: \$\{\{ secrets\.CLOUDFLARE_ACCOUNT_ID \}\}/);
	assert.ok(!/secrets\.[A-Z0-9_]+/.test(step.replace("secrets.CLOUDFLARE_API_TOKEN", "").replace("secrets.CLOUDFLARE_ACCOUNT_ID", "")), "no new token secret");
	assert.match(step, /migrations_dir!=="migrations\/control"/);
	assert.match(step, /wrangler d1 migrations apply apotheke-api --remote --config wrangler\.jsonc/);
	assert.match(step, /Account > D1 > Edit/);
	assert.match(step, /code: 10000/);
	assert.match(step, /title=Cloudflare refused the token/);
	assert.match(step, /exit "\$code"/);
	assert.ok(!/continue-on-error/.test(step), "a failed apply fails the deploy");
	assert.ok(!/--preview/.test(step), "the apply targets the named database, not a Worker preview D1");
	assert.match(deploy, /edge\/migrations\/ \(the default next to wrangler\.jsonc\)/);
	const mask = deploy.indexOf("- name: Mask the account id");
	assert.ok(mask > 0 && mask < merge, "the mask step runs before the storage merge");
	const maskStep = deploy.slice(mask, merge);
	assert.match(maskStep, /CLOUDFLARE_ACCOUNT_ID: \$\{\{ secrets\.CLOUDFLARE_ACCOUNT_ID \}\}/);
	assert.match(maskStep, /APOTHEKE_D1_DATABASE_ID: \$\{\{ secrets\.APOTHEKE_D1_DATABASE_ID \}\}/);
	assert.match(maskStep, /add-mask::\$APOTHEKE_D1_DATABASE_ID/);
	// The PR check job keeps the dummy D1 id and does not use the deploy-edge environment, so it
	// does not need those secrets and does not weaken environment protection.
	assert.ok(!/\n    environment:/.test(check), "the check job does not use the deploy-edge environment");
	assert.match(check, /APOTHEKE_D1_DATABASE_ID: 00000000-0000-4000-8000-000000000000\n/);
	assert.ok(!/secrets\.CLOUDFLARE_ACCOUNT_ID|secrets\.APOTHEKE_D1_DATABASE_ID|vars\.CLOUDFLARE_ACCOUNT_ID|vars\.APOTHEKE_D1_DATABASE_ID/.test(check), "the check job does not read the real identifiers");
});
