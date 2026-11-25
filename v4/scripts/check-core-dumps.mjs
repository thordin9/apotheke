#!/usr/bin/env node
// A workerd that dumps core while every test passes is a boot death no test reported (D105).
//
// On 2026-11-25 a 70 MB core appeared in v4/ at 23:56, twenty-four minutes after the six local gates
// returned green (`330 passed`, `gate-vitest.log` closed 23:31:58). Gate colour reads an exit code and
// the census reads annotations, so neither can see a process that dies after its verdict is written:
// only the filesystem knows, and only if something looks. This looks.
//
//     node scripts/check-core-dumps.mjs                       # every core under v4/ and the repo root
//     node scripts/check-core-dumps.mjs --since 1764106138    # only cores at or after that instant fail
//     node scripts/check-core-dumps.mjs --self-test           # only the cases
//
// `--since` is the gate run's start, in epoch seconds or an ISO instant: a core older than the run is
// printed as `older` and does not fail the gate (it is not this run's death), and a core at or after
// it exits 1 with its size and time. Nothing is ever deleted here - the file is the evidence, and the
// investigation that names the crashing process needs it on disk.
//
// What a core is named: the kernel's `core_pattern` is `core` on this box and `core_uses_pid` is 1, so
// a crash lands as `core` or `core.<pid>` in the crashing process's cwd. That is the whole pattern:
// `corepack`, `core.txt` and a `core-js/` directory are not cores, and a case below says so, because a
// scanner that reports noise is one someone turns off.
//
// The self-test runs first on every invocation: a check that has never failed is a promise with a
// filename.

import { mkdirSync, mkdtempSync, readdirSync, rmSync, statSync, utimesSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import * as path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, "../..");

/** True when `name` is how the kernel writes a core on this box: `core`, or `core.<pid>`. */
export function isCoreName(name) {
	return name === "core" || /^core\.\d+$/.test(name);
}

/**
 * Cores directly under `dir` — not recursive, because a core is dumped in the crashing process's cwd,
 * so a core two levels down belongs to a process started there and a top-level scan of this tree has
 * never needed to look for one.
 */
export function coresIn(dir) {
	const cores = [];
	for (const name of readdirSync(dir)) {
		if (!isCoreName(name)) continue;
		const full = path.join(dir, name);
		const st = statSync(full);
		if (!st.isFile()) continue;
		cores.push({ path: full, bytes: st.size, mtimeMs: st.mtimeMs });
	}
	return cores;
}

/** Split what `coresIn` found into this run's deaths and everything older, by mtime. */
export function scan(dirs, sinceMs) {
	const fresh = [];
	const older = [];
	const missing = [];
	for (const dir of dirs) {
		let cores;
		try {
			cores = coresIn(dir);
		} catch (err) {
			missing.push({ dir, why: err instanceof Error ? err.message : String(err) });
			continue;
		}
		for (const core of cores) (core.mtimeMs >= sinceMs ? fresh : older).push(core);
	}
	return { fresh, older, missing };
}

/** Epoch milliseconds from `--since`: epoch seconds, epoch milliseconds or an ISO instant. */
export function sinceMs(value) {
	if (value === undefined) return 0;
	const n = Number(value);
	if (Number.isFinite(n) && String(value).trim() !== "") return n > 1e11 ? n : n * 1000;
	const parsed = Date.parse(value);
	if (Number.isNaN(parsed)) throw new Error(`--since is not a time: ${JSON.stringify(value)}`);
	return parsed;
}

const iso = (ms) => new Date(ms).toISOString().replace(".000Z", "Z");

function selfTest() {
	let failed = 0;
	const case_ = (what, ok, detail) => {
		if (!ok) {
			failed++;
			console.error(`self-test FAIL: ${what}: ${detail}`);
		}
	};

	// Names: the kernel's, and the ones that merely start with the letters.
	case_("core is a core", isCoreName("core"), "rejected the bare name");
	case_("core.37999 is a core", isCoreName("core.37999"), "rejected the pid suffix");
	case_("corepack is not a core", !isCoreName("corepack"), "accepted a tool's name");
	case_("core.txt is not a core", !isCoreName("core.txt"), "accepted a non-pid suffix");
	case_("core.37999.bak is not a core", !isCoreName("core.37999.bak"), "accepted a decorated name");

	// The scan, on a tree built for it: one core now, one core an hour ago, one decoy.
	const box = mkdtempSync(path.join(tmpdir(), "core-dumps-"));
	try {
		const fresh = path.join(box, "fresh");
		const stale = path.join(box, "stale");
		const empty = path.join(box, "empty");
		for (const dir of [fresh, stale, empty]) mkdirSync(dir);
		const now = Date.now();
		const thisRun = path.join(fresh, "core.37999");
		writeFileSync(thisRun, Buffer.alloc(4096, 7));
		const lastHour = path.join(stale, "core.2586");
		writeFileSync(lastHour, Buffer.alloc(2048, 7));
		utimesSync(lastHour, new Date(now - 3_600_000), new Date(now - 3_600_000));
		writeFileSync(path.join(fresh, "corepack"), "not a core");
		writeFileSync(path.join(fresh, "core.txt"), "not a core either");

		const got = scan([fresh, stale, empty], now - 60_000);
		case_(
			"a core newer than the run is a finding",
			got.fresh.length === 1 && got.fresh[0].path === thisRun && got.fresh[0].bytes === 4096,
			JSON.stringify(got.fresh),
		);
		case_(
			"a core older than the run is named, not failed",
			got.older.length === 1 && got.older[0].path === lastHour,
			JSON.stringify(got.older),
		);
		case_("decoys are not cores", got.fresh.length + got.older.length === 2, "counted a decoy");

		// A directory that does not exist is a broken invocation, not a clean tree.
		const broken = scan([path.join(box, "absent")], 0);
		case_("an unreadable directory is reported", broken.missing.length === 1, JSON.stringify(broken.missing));

		case_("epoch seconds are seconds", sinceMs("1764106138") === 1_764_106_138_000, String(sinceMs("1764106138")));
		case_("an ISO instant parses", sinceMs("2026-11-25T23:56:00Z") === Date.parse("2026-11-25T23:56:00Z"), "wrong ms");
		let threw = false;
		try {
			sinceMs("half past");
		} catch {
			threw = true;
		}
		case_("a non-time is an error", threw, "accepted nonsense");
	} finally {
		rmSync(box, { recursive: true, force: true });
	}

	console.log(`self-test: 12 cases, ${failed} failed`);
	return failed === 0;
}

function main(argv) {
	if (argv.includes("--self-test")) return selfTest() ? 0 : 1;
	if (!selfTest()) return 1;

	let since;
	try {
		since = sinceMs(argv.includes("--since") ? argv[argv.indexOf("--since") + 1] : undefined);
	} catch (err) {
		console.error(`core:check: ${err instanceof Error ? err.message : String(err)}`);
		return 2;
	}

	const dirs = [];
	for (let i = 0; i < argv.length; i++) if (argv[i] === "--dir" && argv[i + 1]) dirs.push(path.resolve(argv[i + 1]));
	if (dirs.length === 0) dirs.push(path.join(ROOT, "v4"), ROOT);

	const { fresh, older, missing } = scan(dirs, since);
	for (const { dir, why } of missing) console.error(`core:check: cannot read ${dir}: ${why}`);
	for (const core of older)
		console.log(`core:check: older ${core.path} ${core.bytes} bytes ${iso(core.mtimeMs)} (before this run; named, not failed)`);
	for (const core of fresh) console.error(`core:check: ${core.path} ${core.bytes} bytes ${iso(core.mtimeMs)}`);

	if (missing.length > 0) return 2;
	if (fresh.length > 0) {
		console.error(
			`core:check: FAIL ${fresh.length} core file(s) at or after ${iso(since)} - a process dumped core and no test reported it (D105)`,
		);
		return 1;
	}
	console.log(`core:check: no core at or after ${iso(since)} in ${dirs.length} director(ies) (${dirs.join(", ")})`);
	return 0;
}

process.exitCode = main(process.argv.slice(2));
