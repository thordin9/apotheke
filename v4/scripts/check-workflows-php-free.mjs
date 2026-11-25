#!/usr/bin/env node
// A workflow that can gate experimental-v4 may not install PHP (plan §5 S1.6, D36; acceptance item 3).
//
// This exists because "the v4 gate is PHP-free" is a fact about seven YAML files today and a promise
// about tomorrow otherwise. It fails the build — it is a step of v4.yml's unit job — if any workflow
// that can run for this branch gains actions/setup-php, a composer command, phpunit or a php binary.
//
// "Can gate this branch" is read from each file's own `on:` block, not from a hand-kept list:
//   * push, with no branch filter (every branch) or one that names the branch — path filters do not
//     matter, because a change under such a path is a change to this branch;
//   * pull_request, with no branch filter (every PR, into any base branch) or one that names it, and
//     without a path filter: a path-filtered PR workflow fires only when that path changes, so it
//     cannot gate the port. Those are named in the output rather than dropped quietly — deploy-edge,
//     deploy-frontend, frontend and ops-console build the v3/frontend apps, which this line does not
//     build, and stripping PHP from them would change what main's deployment does.
// Today the gated set is v4.yml (push + pull_request on the branch) and ci.yml (pull_request,
// unfiltered). ci.yml is in the set on purpose: a pull request into this branch runs it, so a PHP job
// there is a PHP step in this branch's gate even though pushes to the branch never start it.
//
// Every workflow file is loaded as YAML too, gating or not, before any of the above is read: a file
// Actions cannot parse never runs, and it fails with zero jobs rather than with a failing step (D91).
// `45699752450` at `d7fe8363` was created and failed in the same second because a step name carried
// ": " inside a plain scalar, and a plain scalar may not, because the ": " starts a mapping. The six
// local gates read workflow text and never loaded a file as a file, so nothing local could see it.
//
//     node scripts/check-workflows-php-free.mjs             # the real tree
//     node scripts/check-workflows-php-free.mjs --self-test  # prove it fails on a planted PHP step
//
// The self-test runs first on every invocation: a check that has never failed is a promise with a
// filename. Its cases assert the words this script prints, so a parser change that silently stops
// matching a trigger shows up as a failed case rather than a green run.

import { readFileSync, readdirSync } from "node:fs";
import * as path from "node:path";
import { parse } from "yaml";

const BRANCH = "experimental-v4";

/** The `on:` block of a workflow, as lines, or null when the file has none. */
export function onBlock(text) {
	const lines = text.split("\n");
	const at = lines.findIndex((l) => /^on:/.test(l));
	if (at === -1) return null;
	if (/^on:\s*\S/.test(lines[at])) return [lines[at].slice(lines[at].indexOf(":") + 1).trim()];
	const out = [];
	for (let i = at + 1; i < lines.length; i++) {
		const l = lines[i];
		if (l.trim() !== "" && !/^\s/.test(l)) break;
		out.push(l);
	}
	return out;
}

/** A block-list or inline-list value (`branches:`, `paths:`) under a trigger. */
function listOf(body, key) {
	const inline = body.find((l) => new RegExp(`^\\s+${key}:\\s*\\S`).test(l));
	if (inline) {
		const v = inline.slice(inline.indexOf(":") + 1).trim();
		return v.replace(/^\[|\]$/g, "").split(",").map((s) => s.trim().replace(/^['"]|['"]$/g, "")).filter(Boolean);
	}
	const at = body.findIndex((l) => new RegExp(`^\\s+${key}:\\s*$`).test(l));
	if (at === -1) return [];
	const out = [];
	for (let i = at + 1; i < body.length; i++) {
		const m = /^\s*-\s*(.+)$/.exec(body[i]);
		if (!m) break;
		out.push(m[1].trim().replace(/^['"]|['"]$/g, ""));
	}
	return out;
}

/**
 * What this workflow's `on:` says about the branch.
 * `triggers` are the event names that can fire for it; `pathFiltered` is a pull_request that can only
 * fire on paths, so it is not a gate.
 */
export function gateOf(text, branch = BRANCH) {
	const block = onBlock(text);
	if (block === null) return { triggers: [], pathFiltered: [] };
	if (block.length === 1 && block[0].startsWith("[")) {
		const events = block[0].replace(/^\[|\]$/g, "").split(",").map((s) => s.trim()).filter(Boolean);
		return { triggers: events.filter((e) => e === "push" || e === "pull_request"), pathFiltered: [] };
	}
	const triggers = [];
	const pathFiltered = [];
	let i = 0;
	while (i < block.length) {
		const m = /^ {2}([A-Za-z_]+):\s*(.*)$/.exec(block[i]);
		if (!m) {
			i++;
			continue;
		}
		const name = m[1];
		const body = [];
		let j = i + 1;
		for (; j < block.length; j++) {
			if (/^ {2}[A-Za-z_]+:/.test(block[j])) break;
			body.push(block[j]);
		}
		i = j;
		if (name !== "push" && name !== "pull_request") continue;
		const branches = listOf(body, "branches");
		const onBranch = branches.length === 0 || branches.includes(branch);
		if (!onBranch) continue;
		const filtered = listOf(body, "paths").length > 0 || listOf(body, "paths-ignore").length > 0;
		if (name === "pull_request" && filtered) pathFiltered.push(name);
		else triggers.push(name);
	}
	return { triggers: [...new Set(triggers)], pathFiltered };
}

/**
 * Whether a workflow loads as YAML at all: `null`, or the line and first message of the failure.
 * Actions rejects the whole file when this is not null - no job runs - so a check that only reads
 * text says nothing about whether the file it read is one Actions can load (D91).
 */
export function loadError(text) {
	try {
		parse(text);
		return null;
	} catch (e) {
		return { line: e.linePos?.[0]?.line ?? 0, message: String(e.message).split("\n")[0] };
	}
}

const RULES = [
	[/setup-php/, "installs PHP (actions/setup-php)"],
	[/\bcomposer\s+(?:install|update|require|audit|dump-autoload|config|validate)\b/, "runs composer"],
	[/\bphpunit\b/, "runs phpunit"],
	[/\bvendor\/bin\/php\b/, "runs the v3 php binary"],
	[/^\s*(?:-\s*)?run:\s*php\s+-/, "runs php"],
];

/** Every PHP step in a workflow body, as [line number, reason, line]. Comments are not steps. */
export function phpSteps(text) {
	const hits = [];
	text.split("\n").forEach((l, i) => {
		const bare = l.trim();
		if (bare.startsWith("#") || bare === "") return;
		for (const [re, reason] of RULES) {
			if (re.test(l)) {
				hits.push([i + 1, reason, bare]);
				return;
			}
		}
	});
	return hits;
}

/** What the check says about one file: does it gate the branch, and if so, what PHP steps does it carry. */
export function audit(relpath, text) {
	const { triggers, pathFiltered } = gateOf(text);
	return { relpath, triggers, pathFiltered, hits: triggers.length === 0 ? [] : phpSteps(text) };
}

function selfTest() {
	const wf = (...lines) => ["name: x", ...lines, "jobs:", "  t:", "    steps:"].join("\n");
	const setup = ["      - uses: shivammathur/setup-php@v2", "        with:", "          php-version: '8.4'"].join("\n");
	const cases = [
		["a gating workflow that installs PHP is a finding",
			audit("a.yml", wf("on:", "  push:", `    branches: [${BRANCH}]`) + "\n" + setup), 1, "installs PHP (actions/setup-php)"],
		["a workflow for another branch is ignored",
			audit("b.yml", wf("on:", "  push:", "    branches: [main]") + "\n" + setup), 0, ""],
		["composer in a gating step is a finding",
			audit("c.yml", wf("on:", "  pull_request:") + "\n      - run: composer install --prefer-dist"), 1, "runs composer"],
		["phpunit in a gating step is a finding",
			audit("d.yml", wf("on:", "  push:") + "\n      - run: vendor/bin/phpunit -c phpunit.xml"), 1, "runs phpunit"],
		["a node-only gating workflow is clean",
			audit("e.yml", wf("on:", "  push:", `    branches: [${BRANCH}]`, "  pull_request:") + "\n      - uses: actions/setup-node@v7"), 0, ""],
		["an inline `on:` list is read",
			audit("f.yml", ["name: x", "on: [push, pull_request]", "jobs:", "  t:", "    steps:", setup].join("\n")), 1, "installs PHP (actions/setup-php)"],
		["one gating trigger is enough",
			audit("g.yml", wf("on:", "  push:", "    branches: [main]", "  pull_request:", `    branches: [${BRANCH}]`) + "\n      - run: composer audit --no-interaction"), 1, "runs composer"],
		["a path-filtered pull_request is not a gate",
			audit("h.yml", wf("on:", "  pull_request:", "    paths:", "      - 'edge/**'") + "\n" + setup), 0, ""],
	];
	// D91: a workflow Actions cannot load runs no job at all, so the loader is checked against the
	// shape that cost `45699752450` a run - a step name with ": " inside a plain scalar - and against
	// the control that must load, a colon with no space after it.
	const plainScalar = [
		"name: x",
		"on: push",
		"jobs:",
		"  t:",
		"    steps:",
		"      - name: Harvest: the map",
		"        run: echo hi",
	].join("\n");
	let failed = 0;
	const loadCases = [
		["a plain scalar carrying ': ' does not load", plainScalar, /Nested mappings are not allowed in compact mappings/, 6],
		["a colon with no space after it is a scalar", plainScalar.replace("Harvest: the map", "Harvest:the map"), null, 0],
		["a real workflow loads", `${wf("on:", "  push:", `    branches: [${BRANCH}]`)}` + "\n" + setup, null, 0],
	];
	for (const [what, text, wantMessage, wantLine] of loadCases) {
		const got = loadError(text);
		const ok =
			wantMessage === null ? got === null : got !== null && wantMessage.test(got.message) && got.line === wantLine;
		if (!ok) {
			failed++;
			console.error(`self-test FAIL: ${what}: got ${JSON.stringify(got)}`);
		}
	}
	for (const [what, got, wantCount, wantReason] of cases) {
		const ok = got.hits.length === wantCount && (wantCount === 0 || got.hits[0][1] === wantReason);
		if (!ok) {
			failed++;
			console.error(`self-test FAIL: ${what}: expected ${wantCount} ${wantReason}, got ${JSON.stringify(got)}`);
		}
	}
	const g = gateOf(wf("on:", "  push:", `    branches: [${BRANCH}]`));
	if (g.triggers.join(",") !== "push") {
		failed++;
		console.error(`self-test FAIL: branch filter not read: ${JSON.stringify(g)}`);
	}
	console.log(`self-test: ${cases.length + loadCases.length + 1} cases, ${failed} failed`);
	return failed === 0;
}

function main(argv) {
	const here = path.dirname(new URL(import.meta.url).pathname);
	const root = path.resolve(here, "../..");
	const dir = argv.includes("--dir") ? argv[argv.indexOf("--dir") + 1] : path.join(root, ".github/workflows");

	if (!selfTest()) {
		console.error("workflows:check: the self-test failed, so this check cannot be trusted");
		process.exit(1);
	}

	const files = readdirSync(dir).filter((f) => /\.ya?ml$/.test(f)).sort();
	const gating = [];
	const notGating = [];
	let findings = 0;
	let unloadable = 0;
	for (const f of files) {
		const rel = path.join(path.relative(root, dir), f).replace(/^\.\//, "");
		const source = readFileSync(path.join(dir, f), "utf8");
		// D91: a file Actions cannot load runs no job, so it gates nothing - and its parse failure is a
		// finding of its own, not a reason to skip it quietly.
		const bad = loadError(source);
		if (bad !== null) {
			unloadable++;
			console.error(`${rel}:${bad.line}: does not load as YAML, so Actions runs no job from this file (D91): ${bad.message}`);
			continue;
		}
		const got = audit(rel, source);
		if (got.triggers.length === 0) {
			notGating.push(got.pathFiltered.length > 0 ? `${f} (path-filtered pull_request)` : f);
			continue;
		}
		gating.push(f);
		for (const [line, reason, text] of got.hits) {
			findings++;
			console.error(`${rel}:${line}: ${reason}: ${text}`);
		}
	}
	if (files.length === 0) {
		console.error(`workflows:check: no workflow file under ${dir}, so this check proves nothing`);
		process.exit(1);
	}
	if (!gating.includes("v4.yml")) {
		console.error(`workflows:check: v4.yml does not run for ${BRANCH}, so this check proves nothing about the gate`);
		process.exit(1);
	}
	if (unloadable > 0) {
		console.error(`workflows:check: ${unloadable} workflow file(s) do not load as YAML, so Actions runs no job from them (D91)`);
	}
	if (findings > 0) {
		console.error(`workflows:check: ${findings} PHP step(s) in workflows that can gate ${BRANCH} (D36: the gate is PHP-free)`);
	}
	if (unloadable + findings > 0) process.exit(1);
	console.log(`workflows:check: ${files.length} workflow file(s) load as YAML; ${gating.length} can gate ${BRANCH} (${gating.join(", ")}); no PHP step in any.`);
	console.log(`workflows:check: not gating (named so the exclusion is visible): ${notGating.join(", ")}`);
}

main(process.argv.slice(2));
