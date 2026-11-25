#!/usr/bin/env node
// D37: every PHP path this line has ever had is accounted for, and every retired v3 test has a
// destination. Revision 43 widened the job: 231 retired suite paths + 14 `.php` that survive under
// `tests/` (each now with a destination, because the ruling was "no php in the v4 branch") + the 6
// capture-side generators under `v4/`, which are the deliberate exception and are named, not implied.
//
// The ledger is a claim about evidence, so this checks the claim mechanically and refuses to pass on
// a shape it did not read: a self-test runs first, on synthetic ledgers, and every validator has to
// catch the mutation it exists for. Only then is the committed ledger read.
//
// Rules:
//   - 245 rows: 231 `retired` (deleted by 5d65939e) + 14 `surviving` (present under tests/).
//     Retired totals are pinned at 1,435 methods / 379 data providers; there is no such total for
//     surviving files, which are tools, not test classes.
//   - state is exactly one of ported / scheduled / dropped; `kind` is retired or surviving.
//   - ported names a file that exists and carries tests (a .spec.ts) or a committed check (.mjs).
//   - scheduled names a slice or gate the plan defines (S0.x, S1.x, P2..P8, G0..G7).
//   - dropped carries a reason.
//   - retired rows reconcile against `git show --name-status 5d65939e` in both directions when
//     history is present; a surviving row's file must exist and must not be in that commit.
//   - the 6 `v4/**.php` exceptions exist and each says why it is allowed to be PHP.
//
// Exit 1 printing every error it found.

import { readFileSync, existsSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { dirname, join, resolve } from "node:path";

const HERE = dirname(fileURLToPath(import.meta.url));
const V4 = resolve(HERE, "..");
const REPO = resolve(V4, "..");
const LEDGER = join(V4, "test", "php-ledger.json");
const PLAN = join(REPO, "docs", "v4-migration.md");

const RETIRED = 231;
const SURVIVING = 14;
const ROWS = RETIRED + SURVIVING;
const METHODS = 1435;
const DATA_PROVIDERS = 379;
const EXCEPTIONS = 6;
const DELETING_COMMIT = "5d65939e";

/** The slice and gate ids the plan itself defines, read from the plan rather than typed here. */
export function planSlices(text) {
	const ids = new Set();
	for (const m of text.matchAll(/^- \*{0,2}(S0\.\d+|S1\.\d+)\b/gm)) ids.add(m[1]);
	for (const m of text.matchAll(/^### Phase (\d) /gm)) ids.add("P" + m[1]);
	for (const m of text.matchAll(/\*\*Gate (G\d):/g)) ids.add(m[1]);
	return ids;
}

/**
 * Every rule, as one function returning a list of human sentences. `io` is injected so the self-test
 * can drive it with notices instead of the repository.
 */
export function validate(ledger, io) {
	const errors = [];
	const slices = io.planSlices;
	const expected = io.expected ?? { retired: RETIRED, surviving: SURVIVING, methods: METHODS, dataProviders: DATA_PROVIDERS, exceptions: EXCEPTIONS };

	if (ledger.schema !== 2) errors.push(`schema is ${ledger.schema}, expected 2`);
	if (!Array.isArray(ledger.rows)) return [...errors, "rows is not an array"];
	if (!Array.isArray(ledger.exceptions)) return [...errors, "exceptions is not an array"];

	const seen = new Set();
	let retired = 0;
	let surviving = 0;
	let methods = 0;
	let providers = 0;
	for (const row of ledger.rows) {
		const where = row.path ?? "(no path)";
		if (typeof row.path !== "string" || !row.path.startsWith("tests/") || !row.path.endsWith(".php")) {
			errors.push(`${where}: not a tests/**.php path`);
			continue;
		}
		if (seen.has(row.path)) errors.push(`${where}: duplicate row`);
		seen.add(row.path);

		if (row.kind === "retired") {
			retired += 1;
			methods += row.methods ?? 0;
			providers += row.dataProviders ?? 0;
		} else if (row.kind === "surviving") {
			surviving += 1;
			if (io.alive) {
				const problem = io.alive(row.path);
				if (problem) errors.push(`${where}: surviving row whose file ${problem}`);
			}
		} else {
			errors.push(`${where}: kind ${JSON.stringify(row.kind)} is not retired|surviving`);
		}

		if (!["ported", "scheduled", "dropped"].includes(row.state)) {
			errors.push(`${where}: state ${JSON.stringify(row.state)} is not ported|scheduled|dropped`);
			continue;
		}
		if (row.state === "ported") {
			if (!row.target) {
				errors.push(`${where}: ported without a target`);
			} else {
				const problem = io.targetProblem(row.target);
				if (problem) errors.push(`${where}: ported target ${row.target} ${problem}`);
			}
		}
		if (row.state === "scheduled" && !slices.has(row.target)) {
			errors.push(`${where}: scheduled to ${JSON.stringify(row.target)}, which the plan does not define`);
		}
		if (row.state === "dropped") {
			if (row.target) errors.push(`${where}: dropped but names a target ${row.target}`);
			if (typeof row.note !== "string" || row.note.trim().length < 15) {
				errors.push(`${where}: dropped without a reason`);
			}
		}
	}
	if (retired !== expected.retired) errors.push(`${retired} retired rows, expected ${expected.retired}`);
	if (surviving !== expected.surviving) errors.push(`${surviving} surviving rows, expected ${expected.surviving}`);
	if (methods !== expected.methods) errors.push(`${methods} retired test methods recorded, expected ${expected.methods}`);
	if (providers !== expected.dataProviders) errors.push(`${providers} retired data providers recorded, expected ${expected.dataProviders}`);

	const exSeen = new Set();
	for (const ex of ledger.exceptions) {
		if (typeof ex.path !== "string" || !ex.path.startsWith("v4/") || !ex.path.endsWith(".php")) {
			errors.push(`${ex.path ?? "(no path)"}: exception is not a v4/**.php path`);
			continue;
		}
		if (exSeen.has(ex.path)) errors.push(`${ex.path}: duplicate exception`);
		exSeen.add(ex.path);
		if (typeof ex.reason !== "string" || ex.reason.trim().length < 15) errors.push(`${ex.path}: exception without a reason`);
		if (io.alive) {
			const problem = io.alive(ex.path);
			if (problem) errors.push(`${ex.path}: exception whose file ${problem}`);
		}
	}
	if (exSeen.size !== expected.exceptions) errors.push(`${exSeen.size} exceptions, expected ${expected.exceptions}`);

	if (io.createdBy !== undefined) {
		const { createdBy, deleted } = io;
		for (const p of deleted) if (!seen.has(p)) errors.push(`${p}: deleted by ${createdBy} but has no row`);
		for (const row of ledger.rows) {
			if (row.kind !== "retired") continue;
			if (!deleted.has(row.path)) errors.push(`${row.path}: retired row for a path ${createdBy} did not delete`);
		}
	}
	return errors;
}

/** The paths 5d65939e deleted, or null when this checkout has no such commit (depth 1). */
function deletedBy(commit) {
	try {
		execFileSync("git", ["cat-file", "-e", commit + "^{commit}"], { cwd: REPO, stdio: "ignore" });
	} catch {
		return null;
	}
	const out = execFileSync("git", ["show", "--name-status", "--format=", commit], {
		cwd: REPO,
		encoding: "utf8",
	});
	return new Set(
		out
			.split("\n")
			.filter((l) => l.startsWith("D") && l.endsWith(".php"))
			.map((l) => l.split("\t")[1])
			.filter(Boolean),
	);
}

function targetProblem(target) {
	if (typeof target !== "string" || !target.startsWith("v4/")) return "is not a v4/ path";
	const abs = join(REPO, target);
	if (!existsSync(abs)) return "does not exist";
	if (target.endsWith(".spec.ts")) {
		const text = readFileSync(abs, "utf8");
		if (!/\b(?:it|test)\s*\(/.test(text)) return "carries no tests";
		return null;
	}
	if (target.endsWith(".mjs")) return null;
	return "is neither a spec nor a check script";
}

function alive(path) {
	return existsSync(join(REPO, path)) ? null : "does not exist in the tree";
}

function selfTest() {
	const slices = new Set(["S0.1", "S1.7", "P2", "P5", "G1"]);
	const io = {
		expected: { retired: 2, surviving: 1, methods: 1435, dataProviders: 379, exceptions: 1 },
		planSlices: slices,
		targetProblem: (t) =>
			t === "v4/test/ok.spec.ts" ? null : t === "v4/test/empty.spec.ts" ? "carries no tests" : "does not exist",
		alive: (p) => (p === "tests/gone.php" ? "does not exist in the tree" : null),
	};
	const good = () => ({
		schema: 2,
		rows: [
			{ path: "tests/a.php", kind: "retired", methods: 1434, dataProviders: 379, state: "ported", target: "v4/test/ok.spec.ts", note: "" },
			{ path: "tests/b.php", kind: "retired", methods: 1, dataProviders: 0, state: "scheduled", target: "S1.7", note: "" },
			{ path: "tests/live.php", kind: "surviving", methods: 0, dataProviders: 0, state: "scheduled", target: "G1", note: "" },
		],
		exceptions: [{ path: "v4/scripts/gen-x.php", reason: "capture side, never in CI" }],
	});
	const cases = [
		["a clean ledger", good(), (e) => e.length === 0],
		["a missing row", (() => { const l = good(); l.rows.pop(); return l; })(), (e) => e.some((x) => x.includes("0 surviving rows, expected 1"))],
		["a duplicate row", (() => { const l = good(); l.rows[1] = { ...l.rows[0] }; return l; })(), (e) => e.some((x) => x.includes("duplicate"))],
		["a dangling ported target", (() => { const l = good(); l.rows[0].target = "v4/test/gone.spec.ts"; return l; })(), (e) => e.some((x) => x.includes("does not exist"))],
		["a ported target with no tests", (() => { const l = good(); l.rows[0].target = "v4/test/empty.spec.ts"; return l; })(), (e) => e.some((x) => x.includes("carries no tests"))],
		["a slice the plan does not define", (() => { const l = good(); l.rows[1].target = "P9"; return l; })(), (e) => e.some((x) => x.includes("the plan does not define"))],
		["an unknown state", (() => { const l = good(); l.rows[1].state = "maybe"; return l; })(), (e) => e.some((x) => x.includes("is not ported|scheduled|dropped"))],
		["an unknown kind", (() => { const l = good(); l.rows[1].kind = "maybe"; return l; })(), (e) => e.some((x) => x.includes("is not retired|surviving"))],
		["a dropped row with no reason", (() => { const l = good(); l.rows[1] = { ...l.rows[1], state: "dropped", target: "", note: "x" }; return l; })(), (e) => e.some((x) => x.includes("without a reason"))],
		["methods that do not add up", (() => { const l = good(); l.rows[0].methods = 1433; return l; })(), (e) => e.some((x) => x.includes("test methods recorded"))],
		["a path outside tests/", (() => { const l = good(); l.rows[1].path = "src/a.php"; return l; })(), (e) => e.some((x) => x.includes("not a tests/**.php path"))],
		["a surviving row whose file is gone", (() => { const l = good(); l.rows[2].path = "tests/gone.php"; return l; })(), (e) => e.some((x) => x.includes("does not exist in the tree"))],
		["an exception without a reason", (() => { const l = good(); l.exceptions[0].reason = "x"; return l; })(), (e) => e.some((x) => x.includes("exception without a reason"))],
		["a retired row the commit did not delete", good(), (e) => e.some((x) => x.includes("did not delete"))],
	];
	let failed = 0;
	for (const [name, input, ok] of cases) {
		const errors = validate(input, {
			...io,
			createdBy: name.includes("did not delete") ? "deadbeef" : undefined,
			deleted: name.includes("did not delete") ? new Set(["tests/a.php"]) : undefined,
		});
		if (!ok(errors)) {
			failed += 1;
			console.log(`self-test FAILED: ${name} -> ${JSON.stringify(errors)}`);
		}
	}
	if (failed) {
		console.log(`self-test: ${failed} case(s) wrong, refusing to judge the committed ledger`);
		process.exit(1);
	}
	console.log(`self-test: ${cases.length} cases, all as intended`);
}

selfTest();

const plan = readFileSync(PLAN, "utf8");
const slices = planSlices(plan);
const deleted = deletedBy(DELETING_COMMIT);
const ledger = JSON.parse(readFileSync(LEDGER, "utf8"));
const errors = validate(ledger, {
	planSlices: slices,
	targetProblem,
	alive,
	createdBy: deleted ? DELETING_COMMIT : undefined,
	deleted,
});

const rows = ledger.rows;
const count = (state) => rows.filter((r) => r.state === state);
if (errors.length > 0) {
	console.log(`ledger: ${errors.length} problem(s)`);
	for (const e of errors) console.log("  - " + e);
	process.exit(1);
}
const bySlice = new Map();
for (const r of count("scheduled")) bySlice.set(r.target, (bySlice.get(r.target) ?? 0) + 1);
console.log(
	`ledger: ${rows.length} rows (${rows.filter((r) => r.kind === "retired").length} retired, ${rows.filter((r) => r.kind === "surviving").length} surviving), ` +
		`${count("ported").length} ported (${count("ported").reduce((n, r) => n + r.methods, 0)} methods), ` +
		`${count("scheduled").length} scheduled, ${count("dropped").length} dropped; ` +
		`${rows.reduce((n, r) => n + r.methods, 0)} methods, ${rows.reduce((n, r) => n + r.dataProviders, 0)} data providers; ` +
		`${ledger.exceptions.length} named exceptions (capture side)`,
);
console.log("  scheduled: " + [...bySlice].sort((a, b) => a[0].localeCompare(b[0])).map(([s, n]) => `${s} ${n}`).join(", "));
console.log(
	deleted
		? `  reconciled against ${DELETING_COMMIT} (${deleted.size} deleted .php paths)`
		: `  no history for ${DELETING_COMMIT} in this checkout: verified against the recorded totals only`,
);
