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
// N-1541: the fragment may carry only what this merge knows how to apply; anything else (kv_namespaces, routes,
// vars, …) would be dropped silently or widen the Worker, so it is refused.
const KNOWN = new Set(["d1_databases", "r2_buckets", "durable_objects", "migrations", "drop_vars"]);
for (const key of Object.keys(storage)) if (!KNOWN.has(key)) fail(`${storagePath}: unknown key "${key}" (allowed: ${[...KNOWN].join(", ")})`);
for (const key of Object.keys(storage.durable_objects ?? {})) {
	if (key !== "bindings") fail(`${storagePath}: unknown key "durable_objects.${key}" (allowed: bindings)`);
}
// Every name the Worker already uses on env: vars, secrets and the bindings of every kind.
const taken = new Set([...Object.keys(config.vars ?? {}), ...(config.secrets?.required ?? [])]);
for (const value of Object.values(config)) {
	if (Array.isArray(value)) for (const b of value) if (b && typeof b.binding === "string") taken.add(b.binding);
}
for (const b of config.durable_objects?.bindings ?? []) taken.add(b.name);
const added = [...(storage.d1_databases ?? []), ...(storage.r2_buckets ?? [])].map((b) => b.binding);
added.push(...(storage.durable_objects?.bindings ?? []).map((b) => b.name));
for (const name of added) {
	if (taken.has(name)) fail(`${storagePath}: the binding name "${name}" is already used by ${configPath} or the fragment`);
	taken.add(name);
}
// Durable Object classes: one binding per class, and no class that an earlier migration already created.
const classes = new Set((config.durable_objects?.bindings ?? []).map((b) => b.class_name));
for (const b of storage.durable_objects?.bindings ?? []) {
	if (classes.has(b.class_name)) fail(`${storagePath}: the Durable Object class "${b.class_name}" already has a binding`);
	classes.add(b.class_name);
}
// Fragment migrations may only create SQLite-backed classes: each entry has exactly `tag` and `new_sqlite_classes`.
for (const m of storage.migrations ?? []) {
	for (const key of Object.keys(m)) {
		if (key !== "tag" && key !== "new_sqlite_classes") {
			fail(`${storagePath}: migration "${m.tag}" has "${key}"; fragment migrations allow only tag and new_sqlite_classes`);
		}
	}
	if (typeof m.tag !== "string" || !Array.isArray(m.new_sqlite_classes)) {
		fail(`${storagePath}: each fragment migration needs a string tag and a new_sqlite_classes list`);
	}
}
// drop_vars: only allowlisted names, each present in the base vars; never the Host check (ALLOWED_HOSTS).
const DROPPABLE = new Set(["COOKIE_DOMAIN"]);
if (storage.drop_vars !== undefined && !Array.isArray(storage.drop_vars)) fail(`${storagePath}: drop_vars must be a list`);
for (const v of storage.drop_vars ?? []) {
	if (v === "ALLOWED_HOSTS") fail(`${storagePath}: drop_vars may never drop ALLOWED_HOSTS (the app's Host check)`);
	if (!DROPPABLE.has(v)) fail(`${storagePath}: drop_vars may only list ${[...DROPPABLE].join(", ")}, not "${v}"`);
	if (!Object.hasOwn(config.vars ?? {}, v)) fail(`${storagePath}: drop_vars lists "${v}", which ${configPath} does not set`);
}
const migrated = new Set((config.migrations ?? []).flatMap((m) => [...(m.new_sqlite_classes ?? []), ...(m.new_classes ?? [])]));
for (const c of (storage.migrations ?? []).flatMap((m) => m.new_sqlite_classes ?? [])) {
	if (migrated.has(c)) fail(`${storagePath}: the Durable Object class "${c}" is already created by a migration`);
	migrated.add(c);
}
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
