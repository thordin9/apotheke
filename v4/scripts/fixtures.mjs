#!/usr/bin/env node
// The golden HTML fixture's two halves: writing it and checking that it is still the oracle.
//
//   node scripts/fixtures.mjs write <capture-dir> <out.json> <repo-root> <php-version> <sources…>
//   node scripts/fixtures.mjs check <fixture.json> <repo-root>
//
// `write` is called by `gen-html-fixtures.sh`, which has already booted the PHP app and captured the
// answers into `<capture-dir>` (`login.html`, `login.headers`, …). It stores the bytes as they came,
// plus the SHA-256 of every source file the output depends on — nothing is normalised here, so the
// file is evidence rather than a claim.
//
// `check` needs no PHP: it recomputes the hashes of the sources the fixture lists and fails when one
// moved. That is the CI staleness check (the way `routes:check` works), and it is what makes
// "committed and regenerable" a property rather than an intention.

import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const [, , mode, ...args] = process.argv;
const sha256 = (bytes) => createHash("sha256").update(bytes).digest("hex");

/** The headers the fixture pins, in the fixture's own spelling. */
const PICKED = ["content-type", "cache-control", "allow"];

const readHeaders = (path) => {
	const text = readFileSync(path, "utf8");
	const wanted = {};
	for (const line of text.split(/\r?\n/)) {
		const at = line.indexOf(":");
		if (at === -1) {
			continue;
		}
		const name = line.slice(0, at).trim().toLowerCase();
		if (PICKED.includes(name)) {
			wanted[name] = line.slice(at + 1).trim();
		}
	}
	return {
		contentType: wanted["content-type"] ?? "",
		cacheControl: wanted["cache-control"] ?? "",
		...(wanted.allow === undefined ? {} : { allow: wanted.allow }),
	};
};

const write = ([dir, out, root, php, ...sources]) => {
	const page = (name) => {
		const headers = readHeaders(join(dir, `${name}.headers`));
		const body = readFileSync(join(dir, `${name}.html`), "utf8");
		return { ...headers, body };
	};
	const fixture = {
		generatedBy: "v4/scripts/gen-html-fixtures.sh",
		php,
		// What the fixture is: the PHP app's own answers, captured with an empty data directory, no
		// cookies, and every asset's mtime pinned to 0 (see the script for why that is what makes the
		// `?v=` cache keys reproducible).
		note: "Raw output of the v3 app: GET /login (200), GET /nope (404), POST /settings (405). Nothing normalised here; the golden test normalises the CSRF token and session id.",
		sources: Object.fromEntries(sources.map((file) => [file, sha256(readFileSync(join(root, file)))])),
		pages: {
			login: page("login"),
			notFound: page("notFound"),
			methodNotAllowed: page("methodNotAllowed"),
		},
	};
	writeFileSync(out, `${JSON.stringify(fixture, null, "\t")}\n`);
	console.log(`wrote ${out}: ${Object.keys(fixture.sources).length} sources, PHP ${php}`);
};

const check = ([fixturePath, root]) => {
	const fixture = JSON.parse(readFileSync(fixturePath, "utf8"));
	const changed = [];
	for (const [file, expected] of Object.entries(fixture.sources)) {
		let actual;
		try {
			actual = sha256(readFileSync(join(root, file)));
		} catch {
			changed.push(`${file}: missing`);
			continue;
		}
		if (actual !== expected) {
			changed.push(`${file}: changed`);
		}
	}
	if (changed.length > 0) {
		console.error("The golden HTML fixture is stale — regenerate it on a box with PHP 8.4:");
		console.error("    v4/scripts/gen-html-fixtures.sh");
		for (const line of changed) {
			console.error(`  ${line}`);
		}
		process.exit(1);
	}
	console.log(`Golden HTML fixture is current: ${Object.keys(fixture.sources).length} sources unchanged`);
};

if (mode === "write") {
	write(args);
} else if (mode === "check") {
	check(args);
} else {
	console.error("usage: fixtures.mjs write <dir> <out> <root> <php> <sources…> | check <fixture> <root>");
	process.exit(2);
}
