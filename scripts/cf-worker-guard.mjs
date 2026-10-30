#!/usr/bin/env node
// cf-worker-guard.mjs: refuse a Cloudflare deploy unless it can only reach the one Worker it is meant for.
// The deploy tokens are account-wide (they can edit any Worker in the account), so every deploy job runs this first.
//   node scripts/cf-worker-guard.mjs <expected-name> <wrangler-config> [-- <wrangler deploy args...>]
// Fails (exit 1) unless:
//  - <expected-name> is on the allowlist below (hard-coded; a PR changing it is a reviewed change to this file);
//  - the config's top-level "name" is exactly <expected-name>, and the config has no "env" sections, no routes,
//    no custom domains and no workers.dev subdomain;
//  - no other wrangler config could be picked up instead (L-1431): no wrangler.json or wrangler.toml next to it, and
//    no .wrangler/deploy/config.json (wrangler's redirect) in its directory or any directory above. cf-deploy.sh
//    also passes --config <wrangler-config> explicitly;
//  - the deploy args have no --name, --env, -e, --route, --routes, --domain or --config (cf-deploy.sh adds --config
//    itself), and no CLOUDFLARE_ENV / WRANGLER_ENV selects an environment.
import { existsSync, readFileSync } from "node:fs";
import { basename, dirname, join, resolve } from "node:path";

const ALLOWLIST = new Set(["getapotheke-app", "apotheke-api"]);
// Never deployed from this repository; named so a mistake reads clearly in the log.
const DENYLIST = new Set(["apotheke-autofill", "flaremo"]);

function fail(msg) {
	console.log(`::error title=Worker guard::${msg}`);
	process.exit(1);
}

// JSON with comments and trailing commas (wrangler.jsonc), parsed without dependencies. Strings are kept intact,
// so "https://…" inside a value is not a comment.
function parseJsonc(text) {
	let out = "";
	for (let i = 0; i < text.length; i++) {
		const c = text[i];
		if (c === '"') {
			let j = i + 1;
			while (j < text.length && text[j] !== '"') j += text[j] === "\\" ? 2 : 1;
			out += text.slice(i, j + 1);
			i = j;
		} else if (c === "/" && text[i + 1] === "/") {
			while (i < text.length && text[i] !== "\n") i++;
			out += "\n";
		} else if (c === "/" && text[i + 1] === "*") {
			i = text.indexOf("*/", i + 2);
			if (i < 0) fail("unterminated comment in the wrangler config");
			i++;
		} else out += c;
	}
	return JSON.parse(out.replace(/,(\s*[}\]])/g, "$1"));
}

const [expected, configPath, sep, ...args] = process.argv.slice(2);
if (!expected || !configPath || (sep !== undefined && sep !== "--")) {
	fail("usage: cf-worker-guard.mjs <expected-name> <wrangler-config> [-- <deploy args>]");
}
if (DENYLIST.has(expected) || !ALLOWLIST.has(expected)) {
	fail(`"${expected}" is not a Worker this repository deploys (allowed: ${[...ALLOWLIST].join(", ")})`);
}
if (basename(configPath) !== "wrangler.jsonc") fail(`${configPath}: the config must be a wrangler.jsonc`);
const configDir = resolve(dirname(configPath));
for (const other of ["wrangler.json", "wrangler.toml"]) {
	if (existsSync(join(configDir, other))) {
		fail(`${join(dirname(configPath), other)} exists next to ${configPath}; wrangler could read it instead, so remove it`);
	}
}
for (let dir = configDir; ; dir = dirname(dir)) {
	const redirect = join(dir, ".wrangler", "deploy", "config.json");
	if (existsSync(redirect)) fail(`${redirect} exists; wrangler would follow this redirect to another config, so remove it`);
	if (dirname(dir) === dir) break;
}
let config;
try {
	config = parseJsonc(readFileSync(configPath, "utf8"));
} catch (e) {
	fail(`cannot read ${configPath}: ${e.message}`);
}
const name = config.name;
if (typeof name !== "string" || DENYLIST.has(name) || !ALLOWLIST.has(name)) {
	fail(`${configPath} names the Worker "${name}", which is not on the allowlist (${[...ALLOWLIST].join(", ")})`);
}
if (name !== expected) fail(`${configPath} names the Worker "${name}", but this job deploys only "${expected}"`);
if ("env" in config) fail(`${configPath} has "env" sections; an environment could rename the Worker`);
for (const key of ["route", "routes"]) {
	if (key in config) fail(`${configPath} has "${key}"; routes and custom domains are attached outside CI`);
}
if (config.workers_dev !== false) fail(`${configPath} must set "workers_dev": false`);
if (config.preview_urls !== false) fail(`${configPath} must set "preview_urls": false`);
const banned = /^(--name|--env|-e|--route|--routes|--domain|--domains|--config|-c|--script-name)(=|$)/;
for (const a of args) {
	if (banned.test(a)) fail(`deploy argument ${a.split("=")[0]} is not allowed; the config alone decides the Worker`);
}
for (const v of ["CLOUDFLARE_ENV", "WRANGLER_ENV"]) {
	if (process.env[v]) fail(`${v} is set; it would select a wrangler environment`);
}
console.log(`Worker guard: deploying "${name}" from ${configPath} (allowlist: ${[...ALLOWLIST].join(", ")})`);
