#!/usr/bin/env node
// A comment that cites a deleted path must name the commit that still holds it (D168, D170, D172).
//
// D36a+b deleted the PHP app's test tree, and later the app itself: at the head `modules/`, `tests/Modules`
// and `app/Config` name nothing in this repository. A comment that cites one is still useful — it is where
// a behaviour came from — but only if it says which commit still holds that file, because
// `git show 5d65939e^:modules/Auth/Accounts.php` resolves and `git show HEAD:modules/Auth/Accounts.php`
// does not. The convention was kept by hand and rotted where nobody was looking: at D172, 41 of the 46
// files that cite such a path named no commit, and D170 found three ledger notes in the same state. A
// convention kept by a sentence in a message is the failure D160 named, so this is a gate.
//
// What it asserts: every file under `v4/src` and `v4/test` that cites a retired path also names
// `5d65939e`, the commit that deleted the PHP app and its tests. Two classes are excluded by rule, not by
// convenience:
//   * `v4/test/fixtures/**` — a generated provenance manifest (`scripts/gen-api-fixture.mjs:131` writes
//     `sources: { file: sha256(...) }` and `:152` verifies it), so a hand-added marker is erased at the
//     next capture, and a marker there would misstate what the file is;
//   * `*.generated.ts` — `scripts/gen-routes.mjs`'s output, for the same reason.
// `test/php-ledger.json` is inside the class: its notes cite those paths and D168 requires them to name
// the commit, which they do. A JSON file cannot carry a comment, so for a generated one the exclusion has
// to be by path; a hand-written one is held to the same rule as a .ts file.
//
//     node scripts/check-retired-citations.mjs              # the real tree
//     node scripts/check-retired-citations.mjs --self-test  # only the cases
//
// The self-test runs first on every invocation: a check that has never failed is a promise with a
// filename. Its cases assert the words this script prints, so a pattern that stops matching a form shows
// up as a failed case rather than a green run.

import { readFileSync, readdirSync, statSync } from "node:fs";
import * as path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, "..");

/** The commit that deleted the PHP app and its tests; a citation of a retired path names it. */
export const RETIRE_COMMIT = "5d65939e";

/** Source extensions this reads. `.json` is here for the ledger, the one hand-written JSON in the tree. */
const EXTENSIONS = new Set([".ts", ".tsx", ".mts", ".cts", ".js", ".jsx", ".mjs", ".cjs", ".json"]);

/**
 * True when `text` cites a retired path. The lookbehind is the false-positive guard that matters:
 * `node_modules/` is not `modules/`, and a scanner that reports noise is one someone turns off.
 */
export function citesRetiredPath(text) {
	return /(?<![A-Za-z0-9_])modules\/|tests\/Modules|app\/Config/.test(text);
}

/** True when `text` names the commit a retired citation is read at. */
export function namesRetireCommit(text) {
	return text.includes(RETIRE_COMMIT);
}

/** True for the two generated classes D172 excludes by rule. */
export function isGenerated(rel) {
	return rel.startsWith("test/fixtures/") || rel.endsWith(".generated.ts");
}

/** Every scannable file under `src` and `test`, relative to `root`: `{ rel, text }`. */
export function corpus(root) {
	const out = [];
	const walk = (dir) => {
		for (const name of readdirSync(dir).sort()) {
			const full = path.join(dir, name);
			const rel = path.relative(root, full).split(path.sep).join("/");
			if (isGenerated(rel)) continue;
			const st = statSync(full);
			if (st.isDirectory()) {
				walk(full);
				continue;
			}
			if (!EXTENSIONS.has(path.extname(name))) continue;
			out.push({ rel, text: readFileSync(full, "utf8") });
		}
	};
	walk(path.join(root, "src"));
	walk(path.join(root, "test"));
	return out;
}

/** Files that cite a retired path, and the ones that cite one without naming the commit. */
export function verdict(files) {
	const citing = files.filter((f) => citesRetiredPath(f.text)).map((f) => f.rel);
	const offenders = files
		.filter((f) => citesRetiredPath(f.text) && !namesRetireCommit(f.text))
		.map((f) => f.rel);
	return { scanned: files.length, citing, offenders };
}

/** The line this check prints: green names the count and the commit, red names every file that fails. */
export function report({ scanned, citing, offenders }) {
	if (offenders.length === 0) {
		return `citations:check: ${citing.length} file(s) cite a retired path, all name ${RETIRE_COMMIT} (${scanned} scanned)`;
	}
	const lines = offenders.map((f) => `  ${f}`);
	return [
		`citations:check: ${offenders.length} of ${citing.length} file(s) that cite a retired path name no commit:`,
		...lines,
		`${RETIRE_COMMIT} is the commit that still holds the PHP app; cite it as \`...\` at \`${RETIRE_COMMIT}^\` beside the path`,
	].join("\n");
}

function selfTest() {
	const cases = [
		[
			"an unmarked citation is a finding, and it names the file",
			report(verdict([{ rel: "src/a.ts", text: "// ported from `modules/Auth/X.php`" }])).includes("  src/a.ts") &&
				report(verdict([{ rel: "src/a.ts", text: "// ported from `modules/Auth/X.php`" }])).includes(
					"1 of 1 file(s) that cite a retired path name no commit",
				),
			true,
		],
		[
			"a citation that names the commit passes, and the green line states the count",
			report(verdict([{ rel: "src/a.ts", text: "// `modules/Auth/X.php`, read at 5d65939e^" }])),
			`citations:check: 1 file(s) cite a retired path, all name 5d65939e (1 scanned)`,
		],
		[
			"`tests/Modules` alone is a citation",
			citesRetiredPath("// v3: `tests/Modules/Core/XTest.php`"),
			true,
		],
		["`app/Config` alone is a citation", citesRetiredPath("// the order `app/Config/Filters.php` runs"), true],
		[
			"`node_modules/` is not one — a scanner that reports noise is turned off",
			citesRetiredPath("// resolved from node_modules/@cloudflare/vitest-pool-workers"),
			false,
		],
		["a path with no slash after `modules` is not one", citesRetiredPath("// the modules table"), false],
		["the generated fixture manifest is excluded by rule", isGenerated("test/fixtures/php-api.json"), true],
		["a generated route table is excluded by rule", isGenerated("src/http/routes.generated.ts"), true],
		["a hand-written test file is not excluded", isGenerated("test/http/chain.spec.ts"), false],
		[
			"a `.md` file is outside the class: this reads source, not docs",
			EXTENSIONS.has(".md"),
			false,
		],
	];
	let failed = 0;
	for (const [what, got, want] of cases) {
		if (got !== want) {
			failed++;
			console.error(`self-test FAIL: ${what}: expected ${JSON.stringify(want)}, got ${JSON.stringify(got)}`);
		}
	}
	if (failed === 0) console.log(`self-test: ${cases.length} cases, all as intended`);
	else console.error(`self-test: ${failed} of ${cases.length} cases failed`);
	return failed === 0;
}

function main(argv) {
	if (!selfTest()) {
		console.error("citations:check: the self-test failed, so this check cannot be trusted");
		process.exit(1);
	}
	if (argv.includes("--self-test")) return;

	const result = verdict(corpus(ROOT));
	console.log(report(result));
	if (result.citing.length === 0) {
		// A green zero would be a green nothing: the class is 46 files at D172, so none means the scan stopped.
		console.error("citations:check: no file cites a retired path at all, so this scan is not reading the tree");
		process.exit(1);
	}
	if (result.offenders.length !== 0) process.exit(1);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	main(process.argv.slice(2));
}
