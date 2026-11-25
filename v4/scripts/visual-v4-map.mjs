#!/usr/bin/env node
// Harvest the CI4_UNPORTED map from a v4 run (plan D49): the map's keys come from a run, never from
// a hand-read list of pages.
//
// The mechanism, in the order it happens:
//   1. `E2E_APP=v4 npx playwright test` runs the v3 suite against v4 on workerd. v4 answers 501 for
//      every page no slice has ported, and the harness's `sql`/`exec`/`seed`/`image` fixtures refuse
//      with a named message, because no `php` process exists to answer them.
//   2. tests/visual/fixtures.ts records those two facts on the test itself: a 501 becomes a
//      `v4-unported` annotation naming the path, a refusing fixture puts its own words in the error.
//   3. this script reads the JSON report and turns them into map entries - one key per test, in the
//      exact form fixtures.ts looks the key up with ("<spec file>: <describe> › <title>"), which is
//      also the form v4/scripts/check-visual-skip-list.mjs validates against the spec sources.
//
// What it will not do: guess. A test that fails for a reason it cannot attribute to a 501 or to a
// named fixture refusal is printed as `unexplained` and left out of the map - a red the map absorbed
// without a reason is how a skip list stops meaning anything.
//
//     node visual-v4-map.mjs <report.json>                 # print the harvest
//     node visual-v4-map.mjs <report.json> --write <path>  # and write the proposed map
//     node visual-v4-map.mjs --self-test                   # only the cases
//
// The self-test runs first on every invocation: a classifier that has never failed is a promise with
// a filename.

import { readFileSync, writeFileSync } from "node:fs";

const SEP = " › ";

/** The key fixtures.ts computes: `${basename(info.file)}: ${info.titlePath.slice(1).join(' › ')}`. */
export function keyOf(file, titles) {
	return `${file}: ${titles.join(SEP)}`;
}

/** Classify one spec's one test. */
export function classify(test) {
	const annotations = [
		...(test.annotations ?? []),
		...(test.results ?? []).flatMap((r) => r.annotations ?? []),
	];
	const pages = [
		...new Set(annotations.filter((a) => a?.type === "v4-unported").map((a) => a.description)),
	].sort();
	if (test.status === "skipped") return { kind: "skipped", why: "the run skipped it" };
	if (test.status === "expected") {
		return pages.length === 0
			? { kind: "served", why: "passed" }
			: { kind: "unexplained", why: `passed, yet v4 answered 501 for ${pages.join(", ")}` };
	}
	const results = test.results ?? [];
	const message = (results[results.length - 1]?.errors ?? [])
		.map((e) => e.message ?? e.value ?? "")
		.join("\n");
	if (pages.length > 0) return { kind: "unported", why: `v4 answers 501 for ${pages.join(", ")}` };
	const refusal = /`([a-z-]+)` fixture runs/.exec(message);
	if (refusal) {
		return { kind: "unported", why: `the \`${refusal[1]}\` fixture has no v4 equivalent` };
	}
	if (/shells `php` for GD/.test(message)) {
		return { kind: "unported", why: "image() shells `php` for GD; no v4 equivalent" };
	}
	const first = message.split("\n").find((l) => l.trim() !== "") ?? "(no error text)";
	return { kind: "unexplained", why: first.trim() };
}

/** Every test in a Playwright JSON report, as {key, project, status, kind, why}. */
export function harvest(report) {
	const out = [];
	const walk = (suite, titles) => {
		const here = titles === null ? [] : suite.title === undefined ? [...titles] : [...titles, suite.title];
		const file = base(suite.file ?? "");
		for (const spec of suite.specs ?? []) {
			for (const test of spec.tests ?? []) {
				out.push({
					key: keyOf(file === "" ? base(spec.file ?? "") : file, [...here, spec.title]),
					project: test.projectName ?? "",
					status: test.status ?? "",
					...classify(test),
				});
			}
		}
		for (const child of suite.suites ?? []) walk(child, here);
	};
	for (const suite of report.suites ?? []) walk(suite, null);
	return out;
}

function base(p) {
	return p.split("/").pop() ?? p;
}

/** The proposed map: one entry per key attributed to an unported page, sorted, first reason wins. */
export function propose(rows) {
	const map = {};
	for (const r of [...rows].sort((a, b) => a.key.localeCompare(b.key))) {
		if (r.kind === "unported" && map[r.key] === undefined) map[r.key] = r.why;
	}
	return map;
}

function summarise(rows) {
	const count = (kind) => rows.filter((r) => r.kind === kind).length;
	const projects = [...new Set(rows.map((r) => r.project))];
	console.log(
		`map-v4: ${rows.length} test(s) in ${projects.length} project(s): ${count("unported")} unported, ` +
			`${count("served")} served, ${count("skipped")} skipped, ${count("unexplained")} unexplained`,
	);
	for (const r of rows.filter((r) => r.kind === "unexplained")) {
		console.log(`map-v4: unexplained ${r.key} [${r.project}]: ${r.why}`);
	}
}

function selfTest() {
	const cases = [
		[
			"a passing test is served, not unported",
			classify({ status: "expected", results: [{ status: "passed", errors: [] }] }).kind,
			"served",
		],
		[
			"a 501 annotation names the page",
			classify({
				status: "unexpected",
				annotations: [{ type: "v4-unported", description: "/items" }],
				results: [{ status: "failed", errors: [{ message: "expect(locator).toBeVisible: nope" }] }],
			}).why,
			"v4 answers 501 for /items",
		],
		[
			"a refusing fixture is unported and named",
			classify({
				status: "unexpected",
				results: [
					{
						status: "failed",
						errors: [
							{
								message:
									"Error: the visual harness's `sql` fixture runs tests/visual/ci4-fixtures.php inside PHP, and v4 has no equivalent yet.",
							},
						],
					},
				],
			}).why,
			"the `sql` fixture has no v4 equivalent",
		],
		[
			"an unattributable failure is not absorbed",
			classify({
				status: "unexpected",
				results: [{ status: "failed", errors: [{ message: "Error: expect(received).toBe(2)" }] }],
			}).kind,
			"unexplained",
		],
		["a skip is a skip", classify({ status: "skipped", results: [{ status: "skipped" }] }).kind, "skipped"],
		["the key matches fixtures.ts", keyOf("visual.spec.ts", ["Items", "list renders"]), "visual.spec.ts: Items › list renders"],
	];
	let failed = 0;
	for (const [what, got, want] of cases) {
		if (got !== want) {
			failed++;
			console.error(`self-test FAIL: ${what}: expected ${want}, got ${got}`);
		}
	}
	if (failed === 0) console.log(`self-test: ${cases.length} cases, all as intended`);
	else console.error(`self-test: ${failed} of ${cases.length} cases failed`);
	return failed === 0;
}

function main(argv) {
	if (!selfTest()) {
		console.error("map-v4: the self-test failed, so this harvest cannot be trusted");
		process.exit(1);
	}
	if (argv.includes("--self-test")) return;
	const writeAt = argv.indexOf("--write");
	const positional = argv.filter((a, i) => !a.startsWith("--") && (writeAt === -1 || i !== writeAt + 1));
	const report = positional[0];
	if (report === undefined) {
		console.error("map-v4: usage: visual-v4-map.mjs <playwright-json-report> [--write <path>]");
		process.exit(1);
	}
	const rows = harvest(JSON.parse(readFileSync(report, "utf8")));
	summarise(rows);
	if (writeAt !== -1 && argv[writeAt + 1] !== undefined) {
		const map = propose(rows);
		writeFileSync(argv[writeAt + 1], `${JSON.stringify(map, null, "\t")}\n`);
		console.log(`map-v4: wrote ${Object.keys(map).length} key(s) to ${argv[writeAt + 1]}`);
	}
	// Never a gate (D49): the expected output of a v4 run is ~94% skips, so a non-zero exit here
	// would be a statement about the port, not about this script.
}

if (process.argv[1] !== undefined && new URL(import.meta.url).pathname === process.argv[1]) {
	main(process.argv.slice(2));
}
