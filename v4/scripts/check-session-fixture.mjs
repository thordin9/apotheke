#!/usr/bin/env node
// A fixture's staleness check, and nothing else: recompute the SHA-256 of every PHP file the fixture
// says it was derived from and fail when one moved. It serves both fixtures that carry hashes — the
// session one and the claim page's (S1.2b) — because the check is the same wherever the oracle is.
//
//     node scripts/check-session-fixture.mjs [fixture.json] [label] [regenerate command]
//     # with no arguments: the session fixture, which is what `npm run fixtures:check` runs
//
// It needs no PHP. That is deliberate: the fixture is generated on a box that has v3's toolchain
// (`gen-session-fixture.sh`), and the check that runs on every push only has to prove the oracle did
// not change under it.

import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "../..");
const [path, label = "session", regenerate = "v4/scripts/gen-session-fixture.sh"] = process.argv.slice(2);
const fixture = JSON.parse(readFileSync(path ?? join(root, "v4/test/fixtures/php-session.json"), "utf8"));

const stale = [];
for (const [file, want] of Object.entries(fixture.sources)) {
	const got = createHash("sha256").update(readFileSync(join(root, file))).digest("hex");
	if (got !== want) {
		stale.push(`${file}: ${want.slice(0, 12)}… → ${got.slice(0, 12)}…`);
	}
}

if (stale.length > 0) {
	console.error(`The ${label} fixture is stale; regenerate it with ${regenerate}:`);
	for (const line of stale) {
		console.error(`  ${line}`);
	}
	process.exit(1);
}

console.log(`The ${label} fixture is current: ${Object.keys(fixture.sources).length} sources unchanged.`);
