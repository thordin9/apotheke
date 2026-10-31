#!/usr/bin/env node
// edge-storage-config.mjs <wrangler.jsonc> <storage.jsonc>: merges the P4 storage bindings (D1, R2, the Installation
// Durable Object and its migration, and drops COOKIE_DOMAIN) into the Worker's wrangler.jsonc, in place. Run only by the deploy job of
// deploy-edge.yml (behind EDGE_STORAGE_READY), on the runner's checkout, right before cf-deploy.sh, whose Worker guard
// then checks the merged file as usual. The D1 database_id comes from APOTHEKE_D1_DATABASE_ID (a repository variable).
import { readFileSync, writeFileSync } from "node:fs";

function fail(msg) {
	console.log(`::error title=Edge storage config::${msg}`);
	process.exit(1);
}

// JSON with whole-line comments and trailing commas (the two edge configs have no other comments).
const read = (p) => JSON.parse(readFileSync(p, "utf8").replace(/^\s*\/\/.*$/gm, "").replace(/,(\s*[}\]])/g, "$1"));

const [configPath, storagePath] = process.argv.slice(2);
if (!configPath || !storagePath) fail("usage: edge-storage-config.mjs <wrangler.jsonc> <storage.jsonc>");
const id = process.env.APOTHEKE_D1_DATABASE_ID ?? "";
if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(id)) {
	fail("the repository variable APOTHEKE_D1_DATABASE_ID is missing or not a UUID (wrangler d1 info apotheke-api); nothing was deployed.");
}
const config = read(configPath);
const storage = read(storagePath);
for (const key of ["d1_databases", "r2_buckets"]) {
	if (key in config) fail(`${configPath} already has "${key}"; the storage bindings come only from ${storagePath}`);
	config[key] = storage[key];
}
config.d1_databases = config.d1_databases.map((d) => (d.database_id === "<APOTHEKE_D1_DATABASE_ID>" ? { ...d, database_id: id } : d));
config.durable_objects = { ...config.durable_objects, bindings: [...(config.durable_objects?.bindings ?? []), ...storage.durable_objects.bindings] };
const tags = new Set((config.migrations ?? []).map((m) => m.tag));
for (const m of storage.migrations) if (tags.has(m.tag)) fail(`${configPath} already has migration tag "${m.tag}"`);
config.migrations = [...(config.migrations ?? []), ...storage.migrations];
// Hosted mode: no COOKIE_DOMAIN for the container (ADR-002, decision 5); every cookie stays host-only.
const vars = { ...config.vars };
for (const v of storage.drop_vars ?? []) delete vars[v];
config.vars = vars;
writeFileSync(configPath, `${JSON.stringify(config, null, "\t")}\n`);
console.log(`Edge storage config: merged D1 (DB), R2 (STORAGE, eu) and the Installation Durable Object, without COOKIE_DOMAIN, into ${configPath}`);
