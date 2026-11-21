#!/usr/bin/env node
// ops-deploy-config.mjs <edge/ops/wrangler.jsonc>: adds what apotheke-ops needs to be deployed but must not be
// committed, in place, on the runner's checkout only (the deploy-ops job and the PR dry run of deploy-edge.yml):
//  - the control D1's database_id, from APOTHEKE_D1_DATABASE_ID (the deploy-edge environment secret apotheke-api's
//    deploy uses; a dummy UUID in the PR dry run);
//  - the Access vars, from OPS_ACCESS_TEAM_DOMAIN, OPS_ACCESS_AUD and OPS_ALLOWED_EMAILS (variables of the deploy-edge
//    environment; not secrets).
// Fails, so nothing is deployed, when any value is missing or malformed, when the committed config already carries a
// database_id or Access values, when OPS_PROVISIONING or LIMITS_ENABLED is not "0" (prod stays without provisioning
// or limits until a reviewed change says otherwise), or when OPS_WRITES_ENABLED is neither "0" nor "1". Prints no value.
import { readFileSync, writeFileSync } from "node:fs";

function fail(msg) {
	console.log(`::error title=Ops deploy config::${msg}; nothing was deployed.`);
	process.exit(1);
}

// JSON with whole-line comments and trailing commas (as edge-storage-config.mjs).
const read = (p) => JSON.parse(readFileSync(p, "utf8").replace(/^\s*\/\/.*$/gm, "").replace(/,(\s*[}\]])/g, "$1"));

const [configPath] = process.argv.slice(2);
if (!configPath) fail("usage: ops-deploy-config.mjs <edge/ops/wrangler.jsonc>");
const config = read(configPath);
if (config.name !== "apotheke-ops") fail(`${configPath} is not the apotheke-ops config`);

const id = process.env.APOTHEKE_D1_DATABASE_ID ?? "";
if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(id)) fail("APOTHEKE_D1_DATABASE_ID is missing or not a UUID");
const team = (process.env.OPS_ACCESS_TEAM_DOMAIN ?? "").trim().toLowerCase();
if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cloudflareaccess\.com$/.test(team)) {
	fail("OPS_ACCESS_TEAM_DOMAIN is missing or not <team>.cloudflareaccess.com");
}
const aud = (process.env.OPS_ACCESS_AUD ?? "").trim();
if (!/^[A-Za-z0-9]{16,128}$/.test(aud)) fail("OPS_ACCESS_AUD is missing or not an Access audience tag");
const emails = (process.env.OPS_ALLOWED_EMAILS ?? "").split(",").map((e) => e.trim().toLowerCase()).filter((e) => e !== "");
if (emails.length === 0) fail("OPS_ALLOWED_EMAILS is empty");
for (const e of emails) if (e.length > 254 || !/^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/.test(e)) fail("OPS_ALLOWED_EMAILS has an entry that is not an email");

const dbs = config.d1_databases ?? [];
if (dbs.length !== 1 || dbs[0].binding !== "DB" || dbs[0].database_name !== "apotheke-api") fail(`${configPath} must have exactly the DB binding to apotheke-api`);
if ("database_id" in dbs[0]) fail(`${configPath} already has a database_id; ids are never committed`);
const vars = config.vars ?? {};
for (const k of ["ACCESS_TEAM_DOMAIN", "ACCESS_AUD", "OPS_ALLOWED_EMAILS"]) {
	if (vars[k] !== "") fail(`${configPath} must ship ${k} empty; it is set only here`);
}
for (const k of ["OPS_PROVISIONING", "LIMITS_ENABLED"]) {
	if (vars[k] !== "0") fail(`${configPath} must keep ${k} "0" for this deploy`);
}
if (vars.OPS_WRITES_ENABLED !== "0" && vars.OPS_WRITES_ENABLED !== "1") fail(`${configPath} must set OPS_WRITES_ENABLED to "0" or "1"`);

config.d1_databases = [{ ...dbs[0], database_id: id }];
config.vars = { ...vars, ACCESS_TEAM_DOMAIN: team, ACCESS_AUD: aud, OPS_ALLOWED_EMAILS: emails.join(",") };
writeFileSync(configPath, `${JSON.stringify(config, null, "\t")}\n`);
console.log(`Ops deploy config: added the D1 id and the Access vars (${emails.length} allowed email(s)) to ${configPath}`);
