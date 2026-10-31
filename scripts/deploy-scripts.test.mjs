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
//  - scripts/edge-storage-config.mjs: merges edge/storage.jsonc into a copy of edge/wrangler.jsonc that the guard
//    still accepts; a missing or malformed APOTHEKE_D1_DATABASE_ID changes nothing.
// Run: node --test --experimental-strip-types scripts/deploy-scripts.test.mjs
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
const APP_CSP = /^default-src 'none'; script-src 'self';.*connect-src 'self' https:\/\/api\.services\.getapotheke\.com;/;
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

// edge/src/index.ts imports cloudflare:workers, which Node lacks: resolve it to a stub DurableObject base class.
const STUB = "export class DurableObject { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }";
register(
	"data:text/javascript," +
		encodeURIComponent(
			`export async function resolve(s, c, next) { return s === "cloudflare:workers" ? { url: "data:text/javascript,${encodeURIComponent(STUB)}", shortCircuit: true } : next(s, c); }`,
		),
);

test("apotheke-api: the container gets every var of edge/wrangler.jsonc but SLEEP_AFTER_MS", async () => {
	const { ApothekeContainer } = await import(join(root, "edge/src/index.ts"));
	// edge/wrangler.jsonc has only whole-line comments.
	const text = readFileSync(join(root, "edge/wrangler.jsonc"), "utf8").replace(/^\s*\/\/.*$/gm, "");
	const { vars } = JSON.parse(text.replace(/,(\s*[}\]])/g, "$1"));
	assert.equal(vars.CORS_ALLOWED_ORIGINS, "https://getapotheke.com");
	let started;
	const container = {
		running: false,
		start(opts) {
			started = opts;
			this.running = true;
		},
		setInactivityTimeout: async () => {},
		monitor: () => new Promise(() => {}),
		getTcpPort: () => ({ fetch: async () => new Response("ok") }),
	};
	const ctx = { container, waitUntil() {}, blockConcurrencyWhile: (f) => f() };
	const obj = new ApothekeContainer(ctx, { ...vars, APP_PASSWORD: "placeholder", APP_KEY: "placeholder" });
	assert.equal((await obj.fetch(new Request("https://api.example.test/healthz"))).status, 200);
	const { SLEEP_AFTER_MS, ...forwarded } = vars;
	for (const [k, v] of Object.entries(forwarded)) assert.equal(started.env[k], v, k);
	// N-1538: exactly these keys, no more and no fewer
	assert.deepEqual(Object.keys(started.env).sort(), [
		"ALLOWED_HOSTS",
		"APOTHEKE_JOBS_WORKER",
		"APP_KEY",
		"APP_PASSWORD",
		"COOKIE_DOMAIN",
		"CORS_ALLOWED_ORIGINS",
		"PORT",
		"TZ",
	]);
	assert.equal(started.enableInternet, false);
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
		assert.deepEqual(c.d1_databases, [{ binding: "DB", database_name: "apotheke-api", database_id: id }]);
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
