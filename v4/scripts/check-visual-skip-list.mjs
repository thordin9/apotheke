#!/usr/bin/env node
// The CI4_UNPORTED map may not name a test that no longer exists (plan §5 G1; D43).
//
// tests/visual/fixtures.ts carries CI4_UNPORTED: "<spec file>: <describe> › <test title>" -> why the
// page is missing, and an auto fixture skips those tests. Until D36 retired the v3 suite,
// tests/Unit/Ci4E2eSkipListTest.php asserted every key named a test that exists. G1 owes the
// replacement, and this is it. The map itself lives in tests/visual/ci4-unported.json, which
// fixtures.ts reads, so there is one copy of the keys and not two.
//
// Why a check and not a Playwright guard: snapshots.yml ("Update visual snapshots") runs on
// workflow_dispatch or the update-snapshots label and renders baselines, and ci.yml on this branch
// carries only the commit-message and secret-scan jobs. Neither runs on a push here, so a guard
// living in a Playwright spec would never fail one. This runs in v4.yml's unit job, beside the five
// checks already there, on push and pull_request.
//
// What it asserts:
//   * every key in the map names a spec file under tests/visual/ that exists, and a title path that
//     file declares - "<describe> › <test>", or just the title when the test sits at the top level;
//   * a spec file the parser reads no title from is a finding rather than a quiet zero, so a parser
//     that stopped reading cannot pass an empty map;
//   * every key's page is named in docs/HANDOFF.md, and that line does not still say the list is
//     empty while the map has keys - the retired test's second assertion
//     (assertStringContainsString(end($parts), $handoff)), which had no successor until now;
//   * a title the parser cannot resolve statically (a template literal with a substitution, an
//     identifier) is a finding unless it is recorded in UNRESOLVABLE below with its own source text
//     and a reason. Such a title has no key it could match, so skipping it silently is how this
//     guard would rot. The record is checked in both directions: an entry that matches nothing is
//     stale, and fails exactly as a stale key does.
//   * a key whose title the spec builds at run time is accepted only when DYNAMIC_KEYS below records
//     the site and the exact title it can produce - the loop in manage.spec.ts is named rather than
//     ignored. That record is checked in both directions too: a key it does not cover is still a
//     finding, and a recorded title no key uses is stale.
//
//     node scripts/check-visual-skip-list.mjs              # the real tree
//     node scripts/check-visual-skip-list.mjs --self-test  # only the cases
//     node scripts/check-visual-skip-list.mjs --write-handoff  # regenerate the list in docs/HANDOFF.md (D80)
//
// The self-test runs first on every invocation: a check that has never failed is a promise with a
// filename. Its cases assert the words this script prints, so a parser change that stops reading a
// title shows up as a failed case rather than a green run.

import { existsSync, readFileSync, readdirSync, writeFileSync } from "node:fs";
import * as path from "node:path";

const SEP = " › ";

/**
 * Title sites no static parser can resolve, keyed by the source text that builds the title rather
 * than by a line number: a line moves when a file is edited above it, a quote does not (D34, D40).
 * Each entry is a finding if the tree no longer contains it.
 */
const UNRESOLVABLE = [
	{
		file: "manage.spec.ts",
		at: "test.describe(`manage ${kind}`",
		why: "one describe per managed kind, built in a loop over items and locations",
	},
	{
		file: "manage.spec.ts",
		at: "test(`add, rename, default, delete-with-move${kind === 'locations' ? ' @smoke' : ''}`",
		why: "the same loop, with a tag that depends on the kind",
	},
	{
		file: "autofill-suggest.spec.ts",
		at: "test(`fails soft: ${c.name}`",
		why: "one test per suggest case, named from the case table",
	},
];

/**
 * Keys a spec builds at run time, which no static parser can check against source. Each entry names
 * the unresolved site that builds the title and the exact titles it can produce; a key is accepted
 * only when one of these covers it, so this is a record and not a hole. Checked in both directions,
 * like UNRESOLVABLE: a key these do not cover is still a finding, and a recorded title no key uses
 * is stale.
 */
const DYNAMIC_KEYS = [
	{
		file: "manage.spec.ts",
		at: "test(`add, rename, default, delete-with-move${",
		titles: [
			"manage categories \u203a add, rename, default, delete-with-move",
			"manage locations \u203a add, rename, default, delete-with-move @smoke",
		],
		why: "one describe per managed kind, and only the locations case carries @smoke",
	},
];

/** Blank a span in place, keeping newlines so offsets and line numbers still line up. */
function blank(masked, from, to) {
	for (let k = from; k < to && k < masked.length; k++) if (masked[k] !== "\n") masked[k] = " ";
}

/**
 * Walk TypeScript source and return it with comments and string bodies blanked out (`masked`), plus
 * every string literal found. Calls are matched against the masked text, so a `test(` inside a
 * comment or a string can never be read as a test.
 */
export function scan(text) {
	const masked = text.split("");
	const literals = [];
	const stack = [{ mode: "code" }];
	let i = 0;
	while (i < text.length) {
		const top = stack[stack.length - 1];
		const c = text[i];
		const next = text[i + 1];
		if (top.mode === "code") {
			if (c === "/" && next === "/") {
				const nl = text.indexOf("\n", i);
				const end = nl === -1 ? text.length : nl;
				blank(masked, i, end);
				i = end;
				continue;
			}
			if (c === "/" && next === "*") {
				const close = text.indexOf("*/", i + 2);
				const end = close === -1 ? text.length : close + 2;
				blank(masked, i, end);
				i = end;
				continue;
			}
			if (c === "'" || c === '"' || c === "`") {
				stack.push({ mode: c, at: i, hasSub: false });
				i++;
				continue;
			}
			i++;
			continue;
		}
		if (top.mode === "'" || top.mode === '"') {
			if (c === "\\") {
				blank(masked, i, i + 2);
				i += 2;
				continue;
			}
			if (c === top.mode) {
				literals.push({ start: top.at, end: i + 1, value: text.slice(top.at + 1, i), kind: "string" });
				// Blank the body but keep the quotes: the argument's start stays findable in `masked`.
				blank(masked, top.at + 1, i);
				stack.pop();
				i++;
				continue;
			}
			if (c === "\n") {
				// An unterminated quote: give up on it rather than swallow the rest of the file.
				stack.pop();
				continue;
			}
			i++;
			continue;
		}
		if (top.mode === "`") {
			if (c === "\\") {
				i += 2;
				continue;
			}
			if (c === "`") {
				literals.push({
					start: top.at,
					end: i + 1,
					value: text.slice(top.at + 1, i),
					kind: "template",
					hasSub: top.hasSub,
				});
				blank(masked, top.at + 1, i);
				stack.pop();
				i++;
				continue;
			}
			if (c === "$" && next === "{") {
				top.hasSub = true;
				stack.push({ mode: "subst", depth: 0 });
				i += 2;
				continue;
			}
			i++;
			continue;
		}
		// Inside a template substitution: track its braces, and let nested strings open normally.
		if (c === "{") {
			top.depth++;
			i++;
			continue;
		}
		if (c === "}") {
			if (top.depth === 0) {
				stack.pop();
				i++;
				continue;
			}
			top.depth--;
			i++;
			continue;
		}
		if (c === "'" || c === '"' || c === "`") {
			stack.push({ mode: c, at: i, hasSub: false });
			i++;
			continue;
		}
		i++;
	}
	return { masked: masked.join(""), literals };
}

/** Brace depth before each index of already-masked source. */
function depthsOf(masked) {
	const depth = new Int32Array(masked.length + 1);
	let cur = 0;
	for (let i = 0; i < masked.length; i++) {
		depth[i] = cur;
		if (masked[i] === "{") cur++;
		else if (masked[i] === "}") cur--;
	}
	depth[masked.length] = cur;
	return depth;
}

/** A function from source offset to 1-based line number. */
function lineIndex(text) {
	const starts = [0];
	for (let i = 0; i < text.length; i++) if (text[i] === "\n") starts.push(i + 1);
	return (at) => {
		let lo = 0;
		let hi = starts.length - 1;
		while (lo < hi) {
			const mid = (lo + hi + 1) >> 1;
			if (starts[mid] <= at) lo = mid;
			else hi = mid - 1;
		}
		return lo + 1;
	};
}

const TITLE_MODIFIERS = new Set(["only", "skip", "fixme", "fail"]);

/**
 * Is this call head one that takes a title as its first argument? `test.use`, `test.beforeEach` and
 * `test.step` do not, and `test.skip('reason')` inside a body takes one without being a test - that
 * one is caught by requiring a comma after the literal.
 */
function kindOf(head) {
	if (head === "describe" || head.startsWith("test.describe")) return "describe";
	if (head === "test") return "test";
	const dot = head.indexOf(".");
	return dot > 0 && TITLE_MODIFIERS.has(head.slice(dot + 1)) ? "modifier" : null;
}

function unescape(value) {
	return value.replace(/\\(.)/g, (_, ch) => (ch === "n" ? "\n" : ch === "t" ? "\t" : ch));
}

/** The title path each spec file declares, and the title sites this parser cannot resolve. */
export function titlesIn(text) {
	const { masked, literals } = scan(text);
	const depth = depthsOf(masked);
	const lineOf = lineIndex(text);
	const byStart = new Map(literals.map((l) => [l.start, l]));
	const frames = [];
	const titles = new Map();
	const unresolved = [];

	const skipSpace = (k) => {
		let at = k;
		while (at < masked.length && /\s/.test(masked[at])) at++;
		return at;
	};

	for (const m of masked.matchAll(/(?<![\w$.])(test(?:\.[A-Za-z]+)*|describe)\s*\(/g)) {
		const kind = kindOf(m[1]);
		if (kind === null) continue;
		const at = m.index;
		const argAt = skipSpace(at + m[0].length);
		const literal = byStart.get(argAt);
		const d = depth[at];

		let title = null;
		let why = "";
		if (literal === undefined) {
			why = "an identifier or a concatenation, not a literal";
		} else if (literal.kind === "template" && literal.hasSub) {
			why = "a template literal with a substitution";
		} else {
			if (masked[skipSpace(literal.end)] !== ",") continue; // test.skip('reason')
			title = literal.kind === "template" ? literal.value : unescape(literal.value);
		}
		// test.skip(condition, 'why') inside a body annotates a test; it is not one. A modifier call
		// only takes a title when a string literal is followed by a comma, and anything else is that
		// annotation form rather than an unresolvable title.
		if (kind === "modifier" && title === null) continue;

		while (frames.length > 0 && frames[frames.length - 1].depth >= d) frames.pop();

		if (title === null) {
			const end = Math.min(literal === undefined ? at + 140 : literal.end, at + 140);
			unresolved.push({ line: lineOf(at), snippet: text.slice(at, end).replace(/\s+/g, " ").trim(), why });
			if (kind === "describe") frames.push({ title: null, depth: d });
			continue;
		}

		if (kind === "describe") {
			frames.push({ title, depth: d });
			continue;
		}

		if (frames.some((f) => f.title === null)) {
			unresolved.push({
				line: lineOf(at),
				snippet: text.slice(at, Math.min(at + 140, text.length)).replace(/\s+/g, " ").trim(),
				why: "it sits inside a describe whose own title cannot be resolved",
			});
			continue;
		}
		titles.set([...frames.map((f) => f.title), title].join(SEP), lineOf(at));
	}
	return { titles, unresolved };
}

/** The closest declared title, for a message that says what the key probably meant. */
function closest(paths, want) {
	let best = "";
	let score = 0;
	for (const p of paths) {
		let n = 0;
		while (n < p.length && n < want.length && p[n] === want[n]) n++;
		if (n > score) {
			score = n;
			best = p;
		}
	}
	return score >= 8 ? best : "";
}

/**
 * Judge a map against a set of spec files. `corpus` is [{name, text}], names relative to
 * tests/visual/; `map` is the CI4_UNPORTED object. Returns the findings as printable lines.
 */
export function verdict(map, corpus, dynamic = DYNAMIC_KEYS) {
	const errors = [];
	const index = new Map();
	let tests = 0;
	for (const { name, text } of corpus) {
		const { titles, unresolved } = titlesIn(text);
		index.set(name, titles);
		tests += titles.size;
		if (titles.size === 0) {
			errors.push(`${name}: no test title parsed, so any key naming this file would look stale`);
		}
		for (const u of unresolved) {
			const recorded = UNRESOLVABLE.some((e) => e.file === name && u.snippet.startsWith(e.at));
			if (!recorded) {
				errors.push(
					`${name}:${u.line}: a title the parser cannot resolve (${u.why}), not recorded in UNRESOLVABLE: ${u.snippet}`,
				);
			}
		}
		for (const e of UNRESOLVABLE) {
			if (e.file !== name) continue;
			if (!unresolved.some((u) => u.snippet.startsWith(e.at))) {
				errors.push(
					`${name}: UNRESOLVABLE records "${e.at}" (${e.why}), which no longer builds a title - the record is stale`,
				);
			}
		}
	}
	const usedDynamic = new Set();
	for (const [key, why] of Object.entries(map)) {
		const at = key.indexOf(": ");
		if (at === -1) {
			errors.push(`"${key}": a key is "<spec file>: <describe> › <test title>"`);
			continue;
		}
		const file = key.slice(0, at);
		const titlePath = key.slice(at + 2);
		const titles = index.get(file);
		if (titles === undefined) {
			errors.push(`"${key}": ${file} is not a spec file under tests/visual/`);
			continue;
		}
		if (!titles.has(titlePath)) {
			const covered = dynamic.some((e) => e.file === file && e.titles.includes(titlePath));
			if (!covered) {
				const near = closest([...titles.keys()], titlePath);
				errors.push(
					`"${key}": ${file} declares no test titled that (${titles.size} title(s)${near ? `; closest: "${near}"` : ""})`,
				);
				continue;
			}
			usedDynamic.add(`${file}\u0000${titlePath}`);
		}
		if (typeof why !== "string" || why.trim() === "") {
			errors.push(`"${key}": says why the page is missing, and says nothing`);
		}
	}
	for (const e of dynamic) {
		if (!corpus.some((f) => f.name === e.file)) {
			errors.push(`DYNAMIC_KEYS records \"${e.at}\" in ${e.file}, which is not a spec file under tests/visual/`);
			continue;
		}
		for (const t of e.titles) {
			if (!usedDynamic.has(`${e.file}\u0000${t}`)) {
				errors.push(
					`DYNAMIC_KEYS records \"${t}\" in ${e.file} (${e.why}), which no key uses - the record is stale`,
				);
			}
		}
	}
	return { errors, tests, keys: Object.keys(map).length };
}

/**
 * The retired test's other assertion: docs/HANDOFF.md names every skipped page, and its CI4
 * Playwright line stops claiming emptiness when the map gains a key. Without this the first key
 * added makes docs/HANDOFF.md:56 false and nothing fails.
 */
export function handoffVerdict(map, handoff) {
	const errors = [];
	const keys = Object.keys(map);
	// The bullet wraps over several lines, so read it as a paragraph: the line and every indented
	// continuation under it. Reading only the first line would miss "is empty" on the second, which
	// is the silent-pass failure this rule exists to prevent.
	const lines = handoff.split("\n");
	const start = lines.findIndex((l) => l.startsWith("- CI4 Playwright:"));
	if (start === -1) {
		errors.push('docs/HANDOFF.md: no "- CI4 Playwright:" line, so the skip list has no home there');
		return errors;
	}
	let end = start + 1;
	while (end < lines.length && /^[ \t]+\S/.test(lines[end])) end++;
	const bullet = lines.slice(start, end).join("\n");
	if (keys.length > 0 && /\bis empty\b/.test(bullet)) {
		errors.push(
			`docs/HANDOFF.md: the CI4 Playwright line still says the skip list is empty, but the map has ${keys.length} key(s)`,
		);
	}
	for (const key of keys) {
		// The title is the last segment of the key's path, after the "<file>: " prefix.
		const at = key.indexOf(": ");
		const parts = (at === -1 ? key : key.slice(at + 2)).split(SEP);
		const title = parts[parts.length - 1];
		if (!handoff.includes(title)) {
			errors.push(`docs/HANDOFF.md: does not list the skipped "${title}" (${key})`);
		}
	}
	return errors;
}

/**
 * The "## CI4 Playwright skips" section, rendered from the map (D80). One carrier: the section is
 * generated, so the count and every title follow the map instead of being retyped beside it.
 */
export function renderSkips(map) {
	const keys = Object.keys(map).sort();
	const byFile = new Map();
	for (const key of keys) {
		const at = key.indexOf(": ");
		const file = at === -1 ? key : key.slice(0, at);
		const title = at === -1 ? "" : key.slice(at + 2);
		if (!byFile.has(file)) byFile.set(file, []);
		byFile.get(file).push(title);
	}
	const lines = [
		"## CI4 Playwright skips",
		"",
		`Under \`E2E_APP=v4\` these ${keys.length} tests skip: each names a page the v4 app answers 501 for, or`,
		"needs a fixture that runs PHP (`sql`, `settings`). This section is generated from",
		"`tests/visual/ci4-unported.json` by `node scripts/check-visual-skip-list.mjs --write-handoff`;",
		"`skip-list:check` fails while the two disagree, so a hand edit does not survive a check (plan D80).",
		"A key is evaluated inside the test's fixtures: a spec that shells `php` itself skips in the spec,",
		"and a test whose v4 boot died is a defect, never a key.",
		"",
	];
	for (const [file, titles] of [...byFile].sort((a, b) => a[0].localeCompare(b[0]))) {
		lines.push(`- \`${file}\``);
		for (const title of titles.sort()) lines.push(`  - ${title}`);
	}
	return `${lines.join("\n")}\n`;
}

/** The section as docs/HANDOFF.md carries it, or null when the heading is absent. */
export function readSkips(handoff) {
	const lines = handoff.split("\n");
	const start = lines.findIndex((l) => l === "## CI4 Playwright skips");
	if (start === -1) return null;
	let end = start + 1;
	while (end < lines.length && !lines[end].startsWith("## ")) end++;
	return lines.slice(start, end).join("\n").replace(/\s+$/, "");
}

/**
 * The section and the map must agree (D80): the map is generated from runs and the section from the
 * map, so a hand edit to either is drift the check has to catch rather than a difference nobody reads.
 */
export function sectionVerdict(map, handoff) {
	const carried = readSkips(handoff);
	if (carried === null) {
		return ['docs/HANDOFF.md: no "## CI4 Playwright skips" section, so the generated list has nowhere to live'];
	}
	if (carried !== renderSkips(map).replace(/\s+$/, "")) {
		return [
			`docs/HANDOFF.md: the "## CI4 Playwright skips" section does not match the map's ${Object.keys(map).length} key(s) - run \`node scripts/check-visual-skip-list.mjs --write-handoff\` (D80)`,
		];
	}
	return [];
}

/** `--write-handoff`: replace the section with the map's rendering, and return the new file text. */
export function writeHandoff(map, handoff) {
	const lines = handoff.split("\n");
	const start = lines.findIndex((l) => l === "## CI4 Playwright skips");
	const rendered = renderSkips(map);
	if (start === -1) {
		return `${handoff.replace(/\s+$/, "")}\n\n${rendered}`;
	}
	let end = start + 1;
	while (end < lines.length && !lines[end].startsWith("## ")) end++;
	const before = lines.slice(0, start).join("\n").replace(/\s+$/, "");
	const after = lines.slice(end).join("\n");
	return after.trim() === "" ? `${before}\n\n${rendered}` : `${before}\n\n${rendered}\n${after}`;
}

function selfTest() {
	const corpus = [
		{
			name: "a.spec.ts",
			text: [
				"import { test } from './fixtures';",
				"test.describe('outer', () => {",
				"  test('one', async () => {});",
				"  test.describe('inner', () => {",
				"    test('two', async () => {});",
				"  });",
				"});",
				"test.describe('other', () => {",
				"  test('three', async () => {});",
				"});",
				"test('top level', async () => {});",
			].join("\n"),
		},
		{
			name: "b.spec.ts",
			text: [
				"test('names a page', async ({ app }) => {",
				"  // test('in a comment', async () => {});",
				'  const s = "test(\'in a string\', async () => {})";',
				"  test.skip(process.env.CI === '1', 'only on one driver');",
				"  await use();",
				"});",
			].join("\n"),
		},
	];
	const paths = [...titlesIn(corpus[0].text).titles.keys()];
	const onlyOne = [...titlesIn(corpus[1].text).titles.keys()];
	// The real tree supplies DYNAMIC_KEYS; the self-test cases pass their own or none, so a record
	// about a file this corpus does not hold cannot colour them.
	const findings = (map, files) => verdict(map, files ?? corpus, []).errors;
	const handoffFindings = (map, doc) => handoffVerdict(map, doc);
	const cases = [
		["a top-level test is its own title path", paths.includes("top level"), true],
		["a nested describe is part of the path", paths.includes(`outer${SEP}inner${SEP}two`), true],
		["a sibling describe does not leak into the path", paths.includes(`other${SEP}three`), true],
		["the first describe does not swallow the ones after it", paths.includes(`outer${SEP}one`), true],
		["the whole file is read", paths.length === 4, true],
		["a title inside a comment or a string is not a test", onlyOne.length === 1 && onlyOne[0] === "names a page", true],
		[
			"a map key that names no test is a finding",
			findings({ [`a.spec.ts: outer${SEP}nope`]: "the item page" }).some((e) => e.includes("no test titled that")),
			true,
		],
		[
			"a map key naming a file that is not there is a finding",
			findings({ [`z.spec.ts: a${SEP}b`]: "x" }).some((e) => e.includes("not a spec file")),
			true,
		],
		[
			"a map key with no reason is a finding",
			findings({ [`a.spec.ts: other${SEP}three`]: "" }).some((e) => e.includes("says nothing")),
			true,
		],
		[
			"a key that names a title that exists is not a finding",
			findings({ [`a.spec.ts: outer${SEP}one`]: "the item page" }).length === 0,
			true,
		],
		[
			"test.skip(title, body) at file level is a title",
			titlesIn("test.skip('later', async () => {});").titles.has("later"),
			true,
		],
		[
			"test.skip(condition, 'why') inside a body is not a title and not a finding",
			titlesIn("test('x', async () => { test.skip(true, 'why'); });").unresolved.length === 0,
			true,
		],
		[
			"a template literal with a substitution is not silently skipped",
			titlesIn("test(`fails for ${c.name}`, async () => {});").unresolved.length === 1,
			true,
		],
		[
			"a template literal without a substitution is a title",
			titlesIn("test(`plain`, async () => {});").titles.has("plain"),
			true,
		],
		[
			"an unrecorded unresolvable title is a finding",
			findings({}, [{ name: "c.spec.ts", text: "test(`with ${x}`, async () => {});" }]).some((e) =>
				e.includes("not recorded in UNRESOLVABLE"),
			),
			true,
		],
		[
			"tests inside an unresolvable describe are unresolved, not misfiled",
			findings({}, [{ name: "d.spec.ts", text: "test.describe(`d ${k}`, () => { test('static', () => {}); });" }]).filter(
				(e) => e.includes("not recorded"),
			).length >= 2,
			true,
		],
		[
			"a file the parser reads nothing from is a finding",
			findings({}, [{ name: "e.spec.ts", text: "export const nothing = 1;" }]).some((e) =>
				e.includes("no test title parsed"),
			),
			true,
		],
		[
			"an empty map and a hand-off that says so is not a finding",
			handoffFindings({}, "- CI4 Playwright: the `CI4_UNPORTED` skip list is empty.\n").length === 0,
			true,
		],
		[
			"a key while the hand-off still says the list is empty is a finding",
			handoffFindings({ "b.spec.ts: names a page": "the page answers 501" }, "- CI4 Playwright: the skip list is empty.\n").some(
				(e) => e.includes("still says the skip list is empty"),
			),
			true,
		],
		[
			"a key the hand-off names is not a finding",
			handoffFindings(
				{ "b.spec.ts: names a page": "the page answers 501" },
				'- CI4 Playwright: the skip list:\n  - `b.spec.ts` "names a page": the page answers 501.\n',
			).some((e) => e.includes("does not list")),
			false,
		],
		[
			"a key the hand-off does not name is a finding",
			handoffFindings(
				{ "b.spec.ts: names a page": "the page answers 501" },
				"- CI4 Playwright: the skip list:\n  - something else.\n",
			).some((e) => e.includes('does not list the skipped "names a page"')),
			true,
		],
		[
			"a wrapped bullet that says empty on its second line is still read",
			handoffFindings(
				{ "b.spec.ts: names a page": "the page answers 501" },
				"- CI4 Playwright: the skip list (`tests/visual/ci4-unported.json`, read by\n  `fixtures.ts`) is empty.\n",
			).some((e) => e.includes("still says the skip list is empty")),
			true,
		],
		[
			"a hand-off with no CI4 Playwright line is a finding",
			handoffFindings({}, "no bullet here\n").some((e) => e.includes("no \"- CI4 Playwright:\" line")),
			true,
		],
		[
			"a key a recorded dynamic title covers is not a finding",
			verdict(
				{ "a.spec.ts: outer \u203a later thing": "the page answers 501" },
				[{ name: "a.spec.ts", text: "test('one', async () => {});" }],
				[{ file: "a.spec.ts", at: "test(`later ${k}`", titles: ["outer \u203a later thing"], why: "built in a loop" }],
			).errors.length === 0,
			true,
		],
		[
			"a dynamic record does not cover a neighbouring title",
			verdict(
				{ "a.spec.ts: outer \u203a later thing @smoke": "the page answers 501" },
				corpus,
				[{ file: "a.spec.ts", at: "test(`later ${k}`", titles: ["outer \u203a later thing"], why: "built in a loop" }],
			).errors.some((e) => e.includes("no test titled that")),
			true,
		],
		[
			"the generated section is exactly what the check reads back (D80)",
			readSkips(`intro\n\n${renderSkips({ "a.spec.ts": "v4 answers 501 for /x" })}\n## Next\n`) ===
				renderSkips({ "a.spec.ts": "v4 answers 501 for /x" }).replace(/\s+$/, ""),
			true,
		],
		[
			"a section whose key count drifted from the map is a finding",
			sectionVerdict(
				{ "a.spec.ts: one": "v4 answers 501 for /x", "a.spec.ts: two": "v4 answers 501 for /y" },
				renderSkips({ "a.spec.ts: one": "v4 answers 501 for /x" }),
			).some((e) => e.includes("does not match the map")),
			true,
		],
		[
			"a hand-edited title cannot pass, even with the right count",
			sectionVerdict(
				{ "a.spec.ts": "v4 answers 501 for /x" },
				renderSkips({ "b.spec.ts": "v4 answers 501 for /x" }),
			).some((e) => e.includes("does not match the map")),
			true,
		],
		[
			"a hand-off with no section is a finding, not a silent pass",
			sectionVerdict({}, "no section here\n").some((e) => e.includes('no "## CI4 Playwright skips" section')),
			true,
		],
		[
			"writing regenerates the section and leaves the rest of the file alone",
			writeHandoff({ "a.spec.ts": "v4 answers 501 for /x" }, "- CI4 Playwright: the list.\n\n## CI4 Playwright skips\n\n- `b.spec.ts`\n  - gone\n\n## Database\nkeep me\n")
				=== "- CI4 Playwright: the list.\n\n" + renderSkips({ "a.spec.ts": "v4 answers 501 for /x" }) + "\n## Database\nkeep me\n",
			true,
		],
		[
			"a recorded dynamic title no key uses is stale",
			verdict(
				{},
				corpus,
				[{ file: "a.spec.ts", at: "test(`later ${k}`", titles: ["outer \u203a later thing"], why: "built in a loop" }],
			).errors.some((e) => e.includes("which no key uses")),
			true,
		],
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
	const here = path.dirname(new URL(import.meta.url).pathname);
	const root = path.resolve(here, "../..");
	const dir = path.join(root, "tests/visual");
	const mapPath = path.join(dir, "ci4-unported.json");

	if (!selfTest()) {
		console.error("skip-list:check: the self-test failed, so this check cannot be trusted");
		process.exit(1);
	}
	if (argv.includes("--self-test")) return;

	if (!existsSync(mapPath)) {
		console.error(`skip-list:check: ${path.relative(root, mapPath)} is missing, and it is the only copy of the map`);
		process.exit(1);
	}
	const specs = readdirSync(dir)
		.filter((f) => f.endsWith(".spec.ts"))
		.sort();
	if (specs.length === 0) {
		console.error(`skip-list:check: no spec file under ${path.relative(root, dir)}, so there is nothing to check against`);
		process.exit(1);
	}
	const corpus = specs.map((name) => ({ name, text: readFileSync(path.join(dir, name), "utf8") }));
	const map = JSON.parse(readFileSync(mapPath, "utf8"));
	if (argv.includes("--write-handoff")) {
		// D80: the list in docs/HANDOFF.md is generated from the map, never retyped beside it.
		const doc = path.join(root, "docs/HANDOFF.md");
		writeFileSync(doc, writeHandoff(map, readFileSync(doc, "utf8")));
		console.log(
			`skip-list:check: wrote the "## CI4 Playwright skips" section for ${Object.keys(map).length} key(s)`,
		);
		return;
	}
	const { errors, tests, keys } = verdict(map, corpus);
	// The map is a v4 skip list. Under v3 the same tests own the rendered baselines, so an
	// ungated lookup would silence every listed test in every v3 run - and nothing runs
	// tests/visual in CI, so no workflow would report it. Pin the gate at its one site.
	const fixturePath = path.join(dir, "fixtures.ts");
	if (!existsSync(fixturePath)) {
		errors.push("tests/visual/fixtures.ts is missing, so the map is applied to no run");
	} else if (!/const why = APP === ['"]v4['"] \?/.test(readFileSync(fixturePath, "utf8"))) {
		errors.push(
			"tests/visual/fixtures.ts no longer gates the CI4_UNPORTED lookup on APP === 'v4': under v3 an ungated map skips the tests that own the baselines",
		);
	}
	const handoffPath = path.join(root, "docs/HANDOFF.md");
	if (!existsSync(handoffPath)) {
		errors.push("docs/HANDOFF.md is missing, so no skipped page can be listed there");
	} else {
		const handoffText = readFileSync(handoffPath, "utf8");
		errors.push(...handoffVerdict(map, handoffText));
		// D80: the section is generated from the map; a hand edit to either is drift, not a choice.
		errors.push(...sectionVerdict(map, handoffText));
	}
	for (const e of UNRESOLVABLE) {
		if (!corpus.some((f) => f.name === e.file)) {
			errors.push(`UNRESOLVABLE records "${e.at}" in ${e.file}, which is not a spec file under tests/visual/`);
		}
	}
	for (const e of errors) console.error(`skip-list:check: ${e}`);
	if (errors.length > 0) {
		console.error(
			`skip-list:check: ${errors.length} finding(s) - the CI4_UNPORTED map may not name a test that is not there (D43)`,
		);
		process.exit(1);
	}
	const unresolvable = corpus.reduce((n, f) => n + titlesIn(f.text).unresolved.length, 0);
	console.log(
		`skip-list:check: ${corpus.length} spec(s), ${tests} test title(s); CI4_UNPORTED has ${keys} key(s), each naming a title that exists and a page docs/HANDOFF.md lists; ${unresolvable} unresolvable title site(s) recorded.`,
	);
}

// Run only when invoked, so a test can import the pieces above without the exit.
if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname)) {
	main(process.argv.slice(2));
}
