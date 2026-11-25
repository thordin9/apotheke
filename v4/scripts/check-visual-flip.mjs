#!/usr/bin/env node
// G1's flip gate (plan §5 G1): the harness's default app is v4, and this check is what says so.
//
// The flip is one line in tests/visual/fixtures.ts, so the interesting part is the evidence. Three
// things have to be true of a run on the default harness, and none of them is a count:
//
//   1. The run's shape, by name. A v4 default run executes exactly three tests - the two whose pages
//      v4 serves and the one that only reads /login - and every other test either skips itself or
//      carries a CI4_UNPORTED key. So a fourth test executing, a keyed test passing, or a test dying
//      without a boot is a finding here, and the names are in the message. A count would go stale the
//      first time a page lands; a name says which fact changed.
//   2. The comparator is reachable. "Zero diff on the pages v4 implements" is a threshold, not byte
//      equality (D66, D67): a file has changed when more than 5,000 pixels differ. A comparator that
//      cannot go red certifies nothing, so this plants pixels into a baseline and requires the run to
//      fail naming that baseline. It plants 6,000, not one: under a 5,000-pixel budget a one-pixel
//      plant is green by construction, and that is the rule's whole point - 663 px of measured noise
//      must not fail a correct build.
//   3. The unplanted run is green. That is the other side of the budget: whatever v4 renders today is
//      within 5,000 px of the committed baseline, which is what "zero diff on the pages v4 implements"
//      means at this threshold.
//
// What this does not prove, and the commit that flipped the default says so: which of the 512
// baselines v4 reproduces. Only two are compared on desktop-chromium (login.png, not-found.png) -
// every other baseline-owning test owns a page that still answers 501 and is a map key. The flip is
// scoped to that, not to a re-render of the frozen half.
//
//     node check-visual-flip.mjs --report <playwright-json-report>  # the run's shape
//     node check-visual-flip.mjs --control                          # the comparator, planted and not
//     node check-visual-flip.mjs --self-test                        # only the cases
//
// The self-test runs first on every invocation: a gate that has never failed is a promise with a file.

import { readFileSync, writeFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
import * as path from "node:path";
import { fileURLToPath } from "node:url";
import { harvest } from "./visual-v4-map.mjs";
import { decodePng, encodePng, locateDiff } from "./lib/png.mjs";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, "../..");
const SHOTS = path.join(ROOT, "tests/visual/__screenshots__/desktop-chromium");
const BUDGET = 5_000;

/**
 * The three tests a v4 default run executes, as `<spec file>|<test title>`. Named, not counted: this
 * list changes only when a page starts working (which un-skips a test) or stops (which adds a key),
 * and both are facts someone should read before a commit lands.
 */
const EXECUTED = [
	"auth.spec.ts|login screen shows the logo",
	"visual-login.spec.ts|login page",
	"visual.spec.ts|error page",
];

/** The two baselines that list above compares: the only screenshot assertions v4 can reach today. */
const COMPARED = [
	{ file: path.join(SHOTS, "visual-login.spec.ts/login.png"), spec: "visual-login.spec.ts", grep: "login page" },
	{ file: path.join(SHOTS, "visual.spec.ts/not-found.png"), spec: "visual.spec.ts", grep: "error page" },
];

/** `auth.spec.ts|login screen shows the logo` for a row `harvest()` produced. */
export function pairOf(row) {
	const at = row.key.indexOf(": ");
	const file = at === -1 ? row.key : row.key.slice(0, at);
	const title = at === -1 ? "" : row.key.slice(at + 2).split(" › ").pop();
	return `${file}|${title}`;
}

/**
 * The run's shape as a verdict. Skipped is the map doing its job; served is a page v4 renders; dead
 * is a defect that no key may absorb (D77); unported or unexplained on a *default* run means the map
 * no longer names a test that still needs a page - either a new page regressed or a key was dropped.
 */
export function flipVerdict(rows) {
	const errors = [];
	const executed = [];
	let skipped = 0;
	let booted = 0;
	for (const row of rows) {
		if (row.status === "skipped") {
			skipped++;
			if (row.booted) booted++;
			continue;
		}
		executed.push(row);
		const pair = pairOf(row);
		if (row.kind === "dead") errors.push(`${pair} never had a live v4: ${row.why}`);
		else if (!EXECUTED.includes(pair)) errors.push(`${pair} executed on the default harness and is not one of the three named tests (${row.kind}: ${row.why})`);
		else if (row.kind !== "served") errors.push(`${pair} ran but did not pass (${row.kind}: ${row.why})`);
	}
	for (const pair of EXECUTED) {
		if (!executed.some((row) => pairOf(row) === pair)) {
			errors.push(`${pair} did not run on the default harness, so the comparison it owns is no longer measured - if its page now answers 501 it needs a key instead`);
		}
	}
	return { errors, executed: executed.length, skipped, booted };
}

/** The diff count Playwright puts in a screenshot failure, in pixels; null when the message has none. */
export function diffCount(text) {
	const m = /([\d,]+) pixels \(ratio [\d.]+ of all image pixels\) are different/.exec(text);
	if (m === null) return null;
	return Number(m[1].replace(/,/g, ""));
}

/** Overwrite the first `count` pixels of `img` row-major, each moved far enough to count as different. */
export function plantPixels(img, count) {
	if (count > img.width * img.height) throw new Error(`${count} pixels do not fit in ${img.width}x${img.height}`);
	const out = { width: img.width, height: img.height, rgba: Uint8Array.from(img.rgba) };
	for (let i = 0; i < count; i++) {
		const at = i * 4;
		// XOR rather than a fixed colour: a plant over a pixel that already has that colour would not
		// be a difference, and the control would fail for the wrong reason.
		out.rgba[at] ^= 0x40;
		out.rgba[at + 1] ^= 0x40;
		out.rgba[at + 2] ^= 0x40;
		out.rgba[at + 3] = 255;
	}
	return out;
}

/** Run one spec under the default harness; returns { ok, output }. */
function run(spec, grep) {
	const result = spawnSync(
		"npx",
		["playwright", "test", spec, "--project=desktop-chromium", "--grep", grep, "--retries=0", "--workers=1", "--reporter=line"],
		{ cwd: ROOT, encoding: "utf8", maxBuffer: 64 * 1024 * 1024 },
	);
	return { ok: result.status === 0, output: `${result.stdout ?? ""}\n${result.stderr ?? ""}` };
}

function selfTest() {
	const cases = [];
	const check = (name, fn) => {
		let ok = false;
		let why = "";
		try {
			const verdict = fn();
			ok = verdict === true || (verdict && verdict.ok === true);
			why = typeof verdict === "string" ? verdict : "";
		} catch (error) {
			why = String(error);
		}
		cases.push({ name, ok, why });
	};

	const row = (file, title, kind, status = "expected", booted = true) => ({
		key: `${file}: describe › ${title}`,
		status,
		kind,
		booted,
		why: kind,
	});
	const good = () => [
		...EXECUTED.map((pair) => {
			const [file, title] = pair.split("|");
			return row(file, title, "served");
		}),
		row("add.spec.ts", "add item", "skipped", "skipped"),
	];

	check("a run of the three named tests and skips is the flip", () => flipVerdict(good()).errors.length === 0);
	check("a fourth test executing is named, not counted", () => {
		const rows = [...good(), row("barcode.spec.ts", "barcodes", "served")];
		return flipVerdict(rows).errors.some((e) => e.includes("barcode.spec.ts|barcodes"));
	});
	check("a keyed test that passes is a finding: the map no longer matches the app", () => {
		const rows = [...good(), row("add.spec.ts", "add item", "unported", "expected")];
		return flipVerdict(rows).errors.some((e) => e.includes("add.spec.ts|add item") && e.includes("not one of the three"));
	});
	check("a boot death is a defect, never a skip", () => {
		const rows = [...good(), row("attachments.spec.ts", "notes", "dead", "unexpected", false)];
		return flipVerdict(rows).errors.some((e) => e.includes("never had a live v4"));
	});
	check("a named test that stops running fails: its baseline stopped being compared", () => {
		const rows = good().filter((r) => !r.key.startsWith("visual.spec.ts:"));
		return flipVerdict(rows).errors.some((e) => e.includes("visual.spec.ts|error page") && e.includes("did not run"));
	});
	check("a diff count is read in pixels, commas and all", () => diffCount("  6,042 pixels (ratio 0.01 of all image pixels) are different.") === 6042);
	check("a size mismatch is not a diff count", () => diffCount("Expected an image 1280px by 860px, received 1280px by 861px.") === null);
	check("planting N pixels is exactly N differing pixels", () => {
		const base = { width: 40, height: 20, rgba: new Uint8Array(40 * 20 * 4).fill(200) };
		const planted = plantPixels(base, 250);
		const diff = locateDiff(base, planted);
		return diff.count === 250 && diff.box.x === 0 && diff.box.y === 0 && diff.box.width === 40;
	});
	check("the comparator is the flip rule: 5,000 pixels, byte-exact", () => {
		const config = readFileSync(path.join(ROOT, "playwright.config.ts"), "utf8");
		return /maxDiffPixels:\s*5_000/.test(config) && /threshold:\s*0[,\s]/.test(config) && !/maxDiffPixelRatio/.test(config);
	});
	check("the harness boots v4 when E2E_APP says nothing", () => {
		const harness = readFileSync(path.join(ROOT, "tests/visual/fixtures.ts"), "utf8");
		return harness.includes("REQUESTED_APP === 'php' || (!REQUESTED_APP && DOCKER_IMAGE) ? 'php' : 'v4'");
	});
	check("the baseline renderer still asks for v3", () => {
		const renderer = readFileSync(path.join(ROOT, "scripts/update-snapshots.sh"), "utf8");
		const workflow = readFileSync(path.join(ROOT, ".github/workflows/snapshots.yml"), "utf8");
		return renderer.includes("-e E2E_APP=php") && /E2E_APP:\s*php/.test(workflow);
	});

	let failed = 0;
	for (const c of cases) {
		if (!c.ok) {
			failed++;
			console.error(`  FAIL ${c.name}${c.why === "" ? "" : `: ${c.why}`}`);
		}
	}
	if (failed === 0) console.log(`self-test: ${cases.length} cases, all as intended`);
	else console.error(`self-test: ${failed} of ${cases.length} cases failed`);
	return failed === 0;
}

/** The flattened verdict, one line per fact, plus the exit code. */
function report(rows) {
	const { errors, executed, skipped, booted } = flipVerdict(rows);
	console.log(`flip: ${rows.length} test(s); ${executed} executed, ${skipped} skipped (${booted} with a v4 boot)`);
	for (const row of rows.filter((r) => r.status !== "skipped")) console.log(`  ran: ${pairOf(row)} (${row.kind})`);
	console.log(`flip: compared ${COMPARED.map((c) => path.basename(c.file)).join(", ")} on desktop-chromium`);
	for (const e of errors) console.error(`flip: ${e}`);
	if (errors.length > 0) {
		console.error(`flip: ${errors.length} finding(s) - the default harness is not the flip this gate signed`);
		process.exit(1);
	}
}

/**
 * The comparator, in the path a run uses: plant pixels into a committed baseline and require the
 * assertion to fail naming it; then restore the file byte for byte. The unplanted run first, because
 * it is the other side of the budget (and it tells us the base difference when the plant fails).
 */
function control() {
	const failures = [];
	for (const { file, spec, grep } of COMPARED) {
		const name = path.basename(file);
		if (!readFileSync(file)) throw new Error(`${file} is not readable`);
		const original = readFileSync(file);
		const unplanted = run(spec, grep);
		if (!unplanted.ok) {
			failures.push(`${name}: the unplanted run is red, so the budget cannot be separated from the page (${diffCount(unplanted.output) ?? "no diff count"} px)`);
			continue;
		}
		console.log(`control: ${name} unplanted -> green`);
		try {
			const planted = plantPixels(decodePng(original), BUDGET + 1_000);
			writeFileSync(file, encodePng(planted));
			const red = run(spec, grep);
			const count = diffCount(red.output);
			if (red.ok) {
				failures.push(`${name}: ${BUDGET + 1_000} planted pixels did not turn the assertion red, so this path does not compare this baseline`);
			} else if (count === null) {
				failures.push(`${name}: red for a reason that is not a pixel difference: ${red.output.split("\n").filter((l) => l.trim() !== "").slice(-3).join(" | ")}`);
			} else if (count <= BUDGET) {
				failures.push(`${name}: red with ${count} differing pixels, which is inside the ${BUDGET}-pixel budget`);
			} else if (!red.output.includes(name)) {
				failures.push(`${name}: red, ${count} pixels differ, but the message does not name the baseline`);
			} else {
				console.log(`control: ${name} ${BUDGET + 1_000} planted -> red, ${count} pixels differ (base ${count - (BUDGET + 1_000)})`);
			}
		} finally {
			writeFileSync(file, original);
		}
	}
	for (const f of failures) console.error(`control: ${f}`);
	if (failures.length > 0) {
		console.error(`control: ${failures.length} finding(s) - the comparator is not reachable in the path the flip uses`);
		process.exit(1);
	}
	console.log("control: every compared baseline is read by the assertion, and the budget is above a plant of its own size");
}

async function main(argv) {
	if (!selfTest()) {
		console.error("flip-check: the self-test failed, so this gate cannot be trusted");
		process.exit(1);
	}
	if (argv.includes("--self-test")) return;
	const at = argv.indexOf("--report");
	if (at !== -1) {
		const file = argv[at + 1];
		if (file === undefined) {
			console.error("flip-check: usage: check-visual-flip.mjs --report <playwright-json-report> | --control | --self-test");
			process.exit(1);
		}
		report(harvest(JSON.parse(readFileSync(file, "utf8"))));
		return;
	}
	if (argv.includes("--control")) {
		control();
		return;
	}
	console.error("flip-check: usage: check-visual-flip.mjs --report <playwright-json-report> | --control | --self-test");
	process.exit(1);
}

if (process.argv[1] !== undefined && new URL(import.meta.url).pathname === process.argv[1]) {
	await main(process.argv.slice(2));
}
