// Tests for the deploy tooling that need no network and no Cloudflare account (node --test, Node 22):
//  - the getapotheke-app Worker (edge/app/src/index.ts, loaded with Node's type stripping) with a fake ASSETS
//    binding: headers on its own 500, 405 and 301, caching of 200 and 304 responses (N-1435);
//  - scripts/app-worker-check.mjs fails when dist has no _headers (unless STUB_DIST=1);
//  - scripts/cf-worker-guard.mjs: the allowlist, and no other wrangler config next to or above it (L-1431);
//  - scripts/cf-deploy.sh: guard first, then `wrangler deploy --config wrangler.jsonc` (a fake npx records it).
//  - each deploy job's tag step, run from the workflow with a fake `gh`: main, or vX.Y.Z with a published full
//    release (L-1460).
// Run: node --test --experimental-strip-types scripts/deploy-scripts.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
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
