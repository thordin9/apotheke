#!/usr/bin/env node
// G1's boot seam, exercised on every push (plan §6 Gate G1; D43's "a guard that never runs is not a
// guard", applied to a process rather than a list).
//
// tests/visual/fixtures.ts boots the app under test. Until G1 that was `php -S` (or the Docker
// image); G1 replaces it with v4 on workerd. The harness itself cannot be run in CI - no workflow
// runs Playwright on a push, and v4 still answers 501 for every page a slice has not ported - so the
// seam is proven here instead: this script imports the exact module the harness imports
// (tests/visual/v4-server.cjs), boots v4 through it, and asserts what the harness depends on.
//
// What it asserts, and why each one is here:
//   * the process it starts is wrangler and not php - the seam's whole point, asserted on the argv
//     the seam actually ran, with a negative case so the predicate cannot pass by never matching;
//   * `/healthz` 200 `.ok === true` with `.driver === "sqlite"` - workerd is up;
//   * `/readyz` 200 - the app's own migration and seed phase completed over HTTP, against a temp
//     `--persist-to` directory nobody else built, which is what makes a per-worker catalog possible;
//   * `/login` 200, HTML, a password field, and a `content-security-policy` header - the one page v4
//     serves in full, and the page whose v3 baseline the visual suite compares;
//   * `/` 501 with `application/problem+json` and code `route.not_implemented` - "501, never a
//     pretend 200", asserted rather than promised. The pages in that state are printed by name;
//   * `/account` 303 to `/login` - a ported page behind the chain, so the redirect a baseline depends
//     on is real;
//   * after `stop()`, the port refuses connections - `wrangler dev` runs workerd as a child, and a
//     leaked workerd holding the port is how the *next* worker's boot fails on a port the kernel
//     still owns while every probe says it is free.
//
//     node scripts/check-visual-boot.mjs              # boot and assert
//     node scripts/check-visual-boot.mjs --self-test  # only the argv and harness cases, no boot
//
// The self-test runs first on every invocation: a check that has never failed is a promise with a
// filename.

import { existsSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import * as os from "node:os";
import * as path from "node:path";
import { isoClock, startV4, v4Args, v4Vars } from "../../tests/visual/v4-server.cjs";

const HERE = path.dirname(new URL(import.meta.url).pathname);
const ROOT = path.resolve(HERE, "../..");
const HARNESS = path.join(ROOT, "tests/visual/fixtures.ts");

/** True when no word of a command line is `php`; the seam may start wrangler, workerd and nothing else. */
const noPhp = (argv) => !argv.some((word) => path.basename(String(word)) === "php");

const SAMPLE = { port: 8791, persist: "/tmp/x", env: { APP_PASSWORD: "", TZ: "Europe/Athens" } };
/** The install password this check signs in with; the seed hashes it into the "admin" account. */
const PASSWORD = "visual-boot-check";
/** The harness's own consent cookie (playwright.config.ts `storageState`), so no banner is rendered. */
const CONSENT = "apotheke_consent=v1";

const SELFTEST = [
	{
		name: "the seam's argv starts wrangler's dev server and names no php",
		ok: () => {
			const args = v4Args(SAMPLE);
			return args[0] === "dev" && noPhp(["/v4/node_modules/.bin/wrangler", ...args]);
		},
	},
	{
		name: "the no-php predicate is not vacuous: `php -S` is caught",
		ok: () => noPhp(["/usr/bin/php", "-S", "127.0.0.1:8787"]) === false,
	},
	{
		name: "every config var survives as --var KEY:VALUE, empty APP_PASSWORD included",
		ok: () => {
			const vars = v4Vars({ APP_PASSWORD: "" });
			const pairs = vars.filter((_, i) => i % 2 === 1);
			return (
				vars.filter((v) => v === "--var").length === 6 &&
				pairs.includes("DB_DRIVER:sqlite") &&
				pairs.includes("APP_PASSWORD:") &&
				pairs.includes("SEED_DEFAULTS:0")
			);
		},
	},
	{
		name: "the fixture's PHP-form clock becomes the ISO instant v4 reads",
		ok: () =>
			isoClock("2026-06-15 09:00:00") === "2026-06-15T09:00:00Z" &&
			isoClock("2026-06-15T09:00:00Z") === "2026-06-15T09:00:00Z" &&
			isoClock(undefined) === "2026-06-15T09:00:00Z",
	},
	{
		name: "the harness imports this seam and gates it on E2E_APP=v4",
		ok: () => {
			if (!existsSync(HARNESS)) return false;
			const text = readFileSync(HARNESS, "utf8");
			return text.includes("from './v4-server.cjs'") && text.includes("process.env.E2E_APP === 'v4'");
		},
	},
	{
		name: "the seam honours an explicit port, so a restart keeps the browser's origin",
		ok: () => v4Args({ port: 8791, persist: "/tmp/x", env: {} }).join(" ").includes("--port 8791"),
	},
	{
		name: "the harness reseeds v4 through the seam (reset calls restartV4 before the php seed)",
		ok: () => {
			if (!existsSync(HARNESS)) return false;
			const text = readFileSync(HARNESS, "utf8");
			const at = text.indexOf("reset: [");
			if (at === -1) return false;
			const body = text.slice(at, at + 400);
			const restart = body.indexOf("server.restartV4");
			const phpSeed = body.indexOf("fixture(server, 'seed'");
			return restart !== -1 && phpSeed !== -1 && restart < phpSeed;
		},
	},
];

const selfTest = () => {
	let failed = 0;
	for (const c of SELFTEST) {
		let ok = false;
		try {
			ok = c.ok();
		} catch {
			ok = false;
		}
		if (!ok) {
			failed++;
			console.error(`  FAIL ${c.name}`);
		}
	}
	if (failed === 0) console.log(`self-test: ${SELFTEST.length} cases, all as intended`);
	else console.error(`self-test: ${failed} of ${SELFTEST.length} cases failed`);
	return failed === 0;
};

/**
 * Fetch without following a redirect, with a cookie jar: the 303 and the Cookie headers are the
 * assertions. The consent cookie is the harness's own (playwright.config.ts `storageState`), so the
 * sign-in this check walks is the one the visual suite walks.
 */
const get = async (base, p, cookie = CONSENT) => {
	const response = await fetch(base + p, { redirect: "manual", headers: { cookie } });
	return {
		status: response.status,
		type: response.headers.get("content-type") ?? "",
		location: response.headers.get("location") ?? "",
		cookies: response.headers.getSetCookie?.() ?? [],
		text: await response.text(),
		csp: response.headers.get("content-security-policy") ?? "",
	};
};

/** `a=1; b=2`, later values winning: a tiny jar, because the app rotates the session id on sign-in. */
const jar = (cookies) => {
	const pairs = new Map();
	for (const cookie of cookies) {
		const [pair] = cookie.split(";");
		const at = pair.indexOf("=");
		pairs.set(pair.slice(0, at), pair.slice(at + 1));
	}
	return [...pairs].map(([k, v]) => `${k}=${v}`).join("; ");
};

async function main(argv) {
	if (!selfTest()) {
		console.error("visual-boot:check: the self-test failed, so this check cannot be trusted");
		process.exit(1);
	}
	if (argv.includes("--self-test")) return;

	const dataDir = mkdtempSync(path.join(os.tmpdir(), "apotheke-visual-boot-"));
	const errors = [];
	let server;
	const started = Date.now();
	try {
		server = await startV4({ dataDir, env: { APP_PASSWORD: PASSWORD, TZ: "Europe/Athens" } });
		const ready = Date.now() - started;

		const health = await get(server.base, "/healthz");
		const healthJson = health.status === 200 ? JSON.parse(health.text) : {};
		if (healthJson.ok !== true) errors.push(`/healthz answered ${health.status} ${health.text}`);
		if (healthJson.driver !== "sqlite") errors.push(`/healthz reports driver ${healthJson.driver}, not sqlite`);

		const readyz = await get(server.base, "/readyz");
		if (readyz.status !== 200) errors.push(`/readyz answered ${readyz.status} after startV4 resolved: ${readyz.text}`);

		// The page the visual suite's login baselines compare, and the session it needs to continue.
		const login = await get(server.base, "/login");
		if (login.status !== 200) errors.push(`/login answered ${login.status}, not 200`);
		if (!login.type.startsWith("text/html")) errors.push(`/login is ${login.type || "untyped"}, not HTML`);
		if (!login.text.includes('name="password"')) errors.push("/login has no password field");
		if (login.csp === "") errors.push("/login carries no content-security-policy header");
		const csrf = /name="csrf" value="([0-9a-f]*)"/.exec(login.text)?.[1] ?? "";
		if (!/^[0-9a-f]{64}$/.test(csrf)) errors.push(`/login minted no csrf token (got ${JSON.stringify(csrf)})`);
		if (login.cookies.length === 0) errors.push("/login set no session cookie, so no sign-in could follow");

		// Signing in: the route, the csrf filter and the session rotation, over HTTP.
		const form = new URLSearchParams({ username: "admin", password: PASSWORD, csrf });
		const post = await fetch(`${server.base}/login`, {
			method: "POST",
			body: form,
			redirect: "manual",
			headers: { cookie: jar([CONSENT, ...login.cookies]) },
		});
		if (post.status !== 303 || post.headers.get("location") !== "/") {
			errors.push(`POST /login answered ${post.status} ${post.headers.get("location") ?? "-"}, not 303 to /`);
		}
		const signedIn = jar([CONSENT, ...login.cookies, ...(post.headers.getSetCookie?.() ?? [])]);

		// "501, never a pretend 200": a page no slice has ported, reached as a signed-in admin, and the
		// answer names the controller that is missing.
		const home = await get(server.base, "/", signedIn);
		if (home.status !== 501) errors.push(`/ answered ${home.status} as a signed-in admin, not the honest 501`);
		if (!home.type.startsWith("application/problem+json")) errors.push(`/ is ${home.type || "untyped"}, not problem+json`);
		if (!home.text.includes("route.not_implemented")) errors.push("/ 501 does not carry the code route.not_implemented");
		const missing = /Controllers\\\\([A-Za-z]+Controller)/.exec(home.text)?.[1] ?? "";
		if (missing === "") errors.push(`/ 501 does not name the controller that is missing: ${home.text.slice(0, 120)}`);

		// A ported page behind the chain: if this stops answering 200, the session or the chain broke.
		const account = await get(server.base, "/account", signedIn);
		if (account.status !== 200) errors.push(`/account answered ${account.status} as a signed-in admin, not 200`);

		if (!noPhp([server.command, ...server.args])) errors.push(`the seam ran a php process: ${server.command}`);

		for (const e of errors) console.error(`visual-boot:check: ${e}`);
		if (errors.length > 0) {
			console.error(`visual-boot:check: ${errors.length} finding(s) - the harness's v4 boot seam is not what it says it is`);
			console.error("--- wrangler log tail ---");
			console.error(server.tail());
			process.exitCode = 1;
			return;
		}
		console.log(
			`visual-boot:check: v4 ready in ${ready} ms; /healthz 200 (driver sqlite); /readyz 200; /login 200 (${login.text.length} bytes, csrf, CSP); POST /login 303 to /; /account 200; / 501 route.not_implemented (${missing}); no php process started.`,
		);
		console.log(`visual-boot:check: the page this probes as unported is named by the 501 itself: / -> ${missing}`);
	} finally {
		server?.stop();
		// A stop that leaves workerd on the port is the failure this waits for.
		if (server) {
			await new Promise((resolve) => setTimeout(resolve, 1500));
			try {
				await fetch(`${server.base}/healthz`);
				console.error("visual-boot:check: v4 answered after stop(); workerd outlived wrangler");
				process.exitCode = 1;
			} catch {
				/* refused: the process group went away together */
			}
		}
		rmSync(dataDir, { recursive: true, force: true });
	}
}

// Run only when invoked, so a test can import the pieces above without the exit.
if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname)) {
	await main(process.argv.slice(2));
}
