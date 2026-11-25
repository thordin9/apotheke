// The v4 visual instrument (D49/D51): boots v4 through the harness's own boot seam, renders /login in
// the pinned Playwright build, and compares the pixels against the committed v3 baseline.
//
// It exists because of one property of a negative control: a red is exactly what it wants, so a red
// cannot be its evidence. A browser-version mismatch produces the very red a broken comparator would,
// and an exit status cannot tell them apart. So this instrument does two separate things and reports
// them separately:
//
//   1. DATA - the app's pixels against the baseline's, reported with the count and the bounding box.
//      A difference here is a real G1 finding (v4's login does not render like v3's), not a harness
//      failure, so it is printed loudly and does not fail the step. `--strict-baseline` flips that,
//      for the flip gate later, when "baselines unchanged" is the thing being proved.
//   2. CONTROL - one pixel is planted at a known coordinate and the same comparison must locate it,
//      exactly, at that coordinate. If it does not, the comparison is not measuring what it claims
//      and the step fails.
//
// What this is not: Playwright's toHaveScreenshot tolerance (0.2 per-pixel, 0.002 ratio). This
// comparator is exact, so it is stricter than the suite - it can report a diff the suite would
// accept, never the reverse. Running the real spec through the real runner needs the harness's
// seeding step (fixtures.ts's auto `reset` refuses under E2E_APP=v4), which is G1's next increment.

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { decodePng, encodePng, withPixel, pixelAt, locateDiff } from './lib/png.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '../..');
const require = createRequire(import.meta.url);
const { startV4 } = require(path.join(ROOT, 'tests/visual/v4-server.cjs'));

const BASELINE = path.join(ROOT, 'tests/visual/__screenshots__/desktop-chromium/visual-login.spec.ts/login.png');
const SPEC = 'visual-login.spec.ts: visual: login › login page';
const VIEWPORT = { width: 1280, height: 860 };
const PLANT = { x: 10, y: 10 };
const OUT = path.join(ROOT, 'tests/visual/results/visual-v4-instrument');

/* --------------------------------------------------------------------------------------------- */
/* pure pieces, all self-tested                                                                   */

const image = (width, height, fill) => ({ width, height, rgba: new Uint8Array(width * height * 4).fill(fill) });

/** A colour that provably differs from the one under the plant point, so the plant cannot vanish. */
function plantColour(base) {
	const [r, g, b] = base;
	const candidates = [[255, 0, 255], [0, 255, 0], [255, 255, 0], [0, 0, 0], [255, 255, 255]];
	const distance = (c) => Math.abs(c[0] - r) + Math.abs(c[1] - g) + Math.abs(c[2] - b);
	const best = candidates.find((c) => distance(c) > 200);
	if (!best) throw new Error(`no planting colour differs enough from rgb(${r},${g},${b})`);
	return best;
}

/** The control's verdict: the planted pixel must be the only difference, and it must be located. */
function controlVerdict(diff, plant) {
	if (diff.count === 0) return { ok: false, why: 'the planted pixel produced no difference at all: the comparison is not reading these pixels' };
	if (diff.count !== 1) return { ok: false, why: `the plant produced ${diff.count} differing pixel(s) (${diff.text}): a plant of one pixel must locate one pixel` };
	if (!diff.box || diff.box.width !== 1 || diff.box.height !== 1 || diff.box.x !== plant.x || diff.box.y !== plant.y) {
		return { ok: false, why: `the difference was located at ${JSON.stringify(diff.box)}, not at the planted pixel (${plant.x},${plant.y})` };
	}
	return { ok: true, why: `one pixel planted at (${plant.x},${plant.y}) and located there exactly` };
}

/* --------------------------------------------------------------------------------------------- */
/* self-test: no browser, no boot, no network                                                     */

function selfTest() {
	const cases = [];
	const check = (name, fn) => {
		try {
			const result = fn();
			cases.push({ name, ok: result === true, why: result === true ? '' : String(result) });
		} catch (error) {
			cases.push({ name, ok: false, why: error instanceof Error ? error.message : String(error) });
		}
	};

	check('a synthetic image survives encode/decode unchanged', () => {
		const img = image(3, 2, 7);
		const back = decodePng(encodePng(img));
		const diff = locateDiff(img, back);
		return (back.width === 3 && back.height === 2 && diff.count === 0) || `round trip differs: ${diff.text}`;
	});

	check('encoding is deterministic', () => {
		const img = image(5, 5, 3);
		return encodePng(img).equals(encodePng(img)) || 'two encodes of the same image produced different bytes';
	});

	check('alpha is preserved', () => {
		const img = image(2, 1, 0);
		img.rgba[3] = 80;
		const back = decodePng(encodePng(img));
		return back.rgba[3] === 80 || `alpha 80 came back as ${back.rgba[3]}`;
	});

	check('identical images locate nothing', () => {
		const diff = locateDiff(image(4, 4, 9), image(4, 4, 9));
		return (diff.count === 0 && diff.box === null) || `expected no diff, got ${diff.text}`;
	});

	check('one planted pixel is located at itself', () => {
		const img = image(8, 8, 9);
		const planted = withPixel(img, 3, 5, [1, 2, 3]);
		const diff = locateDiff(img, planted);
		const box = diff.box || {};
		return (diff.count === 1 && box.x === 3 && box.y === 5 && box.width === 1 && box.height === 1) || diff.text;
	});

	check('two planted pixels give the box that spans them', () => {
		const img = image(10, 10, 9);
		const planted = withPixel(withPixel(img, 1, 2, [1, 1, 1]), 7, 9, [2, 2, 2]);
		const box = locateDiff(img, planted).box || {};
		return (box.x === 1 && box.y === 2 && box.width === 7 && box.height === 8) || JSON.stringify(box);
	});

	check('a size change is refused, not located', () => {
		try {
			locateDiff(image(2, 2, 0), image(2, 3, 0));
			return 'a size change was accepted as a diff';
		} catch (error) {
			return /size differs/.test(error.message) || `unexpected error: ${error.message}`;
		}
	});

	check('a non-PNG is refused', () => {
		try {
			decodePng(Buffer.from('not a png'));
			return 'a non-PNG decoded';
		} catch (error) {
			return /not a PNG/.test(error.message) || `unexpected error: ${error.message}`;
		}
	});

	check('truncated PNG data is refused', () => {
		const png = encodePng(image(4, 4, 1));
		try {
			decodePng(png.subarray(0, 40));
			return 'truncated PNG decoded';
		} catch (error) {
			return true;   // any refusal is right; the message is the PNG library's
		}
	});

	check('the planting colour differs from what it covers', () => {
		for (const base of [[245, 245, 247], [255, 0, 255], [0, 0, 0], [255, 255, 255], [0, 255, 0]]) {
			const c = plantColour(base);
			const distance = Math.abs(c[0] - base[0]) + Math.abs(c[1] - base[1]) + Math.abs(c[2] - base[2]);
			if (distance <= 200) return `plantColour(${base}) = ${c}, distance ${distance}`;
		}
		return true;
	});

	check('an unplanted comparison is a control failure, not a pass', () => {
		const verdict = controlVerdict({ count: 0, box: null, text: 'pixels identical' }, PLANT);
		return verdict.ok === false || 'an unplanted comparison passed as a control';
	});

	check('a scattered difference is a control failure, and names the count', () => {
		const verdict = controlVerdict({ count: 412, box: { x: 0, y: 0, width: 400, height: 300 }, text: '412 pixel(s) differ' }, PLANT);
		return (verdict.ok === false && /412/.test(verdict.why)) || `a scattered diff passed: ${verdict.why}`;
	});

	check('a difference at the wrong coordinate is a control failure', () => {
		const verdict = controlVerdict({ count: 1, box: { x: 11, y: 10, width: 1, height: 1 }, text: '1 pixel' }, PLANT);
		return verdict.ok === false || 'a diff one pixel to the right passed as the plant';
	});

	check('a difference at the pixel passes', () => {
		const verdict = controlVerdict({ count: 1, box: { x: PLANT.x, y: PLANT.y, width: 1, height: 1 }, text: '1 pixel' }, PLANT);
		return verdict.ok === true || `the planted pixel was not accepted: ${verdict.why}`;
	});

	const failed = cases.filter((c) => !c.ok);
	for (const c of cases) console.log(`${c.ok ? 'ok  ' : 'FAIL'}  ${c.name}${c.ok ? '' : `\n        ${c.why}`}`);
	console.log(`\nself-test: ${cases.length} cases, ${failed.length === 0 ? 'all as intended' : `${failed.length} wrong`}`);
	return failed.length === 0;
}

/* --------------------------------------------------------------------------------------------- */
/* live: boot, render, compare                                                                    */

async function render(base) {
	const { chromium, devices } = require('@playwright/test');
	// `PLAYWRIGHT_CHROMIUM_EXECUTABLE` exists for boxes whose browser cache is elsewhere than
	// Playwright's default; CI (the pinned image) never sets it.
	const browser = await chromium.launch({ chromiumSandbox: false, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
	try {
		const context = await browser.newContext({
			...devices['Desktop Chrome'],
			viewport: VIEWPORT,
			deviceScaleFactor: 1,
			colorScheme: 'light',
			locale: 'en-GB',
			timezoneId: 'Europe/Athens',
			// playwright.config.ts's storageState: the consent banner starts accepted on every context.
			storageState: { cookies: [{ name: 'apotheke_consent', value: 'v1', domain: '127.0.0.1', path: '/', expires: -1, httpOnly: false, secure: false, sameSite: 'Lax' }], origins: [] },
		});
		const page = await context.newPage();
		const response = await page.goto(base + '/login', { waitUntil: 'networkidle' });
		await page.evaluate(() => document.fonts.ready);
		await page.locator('img').evaluateAll((imgs) => Promise.all(imgs.map((i) => i.decode().catch(() => {}))));
		const shot = await page.screenshot({ animations: 'disabled', caret: 'hide', scale: 'css' });
		const title = await page.title();
		const h1 = await page.locator('h1').first().textContent().catch(() => null);
		await context.close();
		return { shot, status: response ? response.status() : 0, url: page.url(), title, h1 };
	} finally {
		await browser.close();
	}
}

async function live({ strictBaseline }) {
	fs.mkdirSync(OUT, { recursive: true });
	const findings = [];
	const report = { baseline: path.relative(ROOT, BASELINE), spec: SPEC, plant: PLANT };
	const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'v4-instrument-'));
	let server;
	try {
		if (!fs.existsSync(BASELINE)) throw new Error(`the baseline is missing: ${BASELINE}`);
		const expected = decodePng(fs.readFileSync(BASELINE));
		report.baselineSize = `${expected.width}x${expected.height}`;
		if (expected.width !== VIEWPORT.width || expected.height !== VIEWPORT.height) {
			throw new Error(`the baseline is ${expected.width}x${expected.height}, and this instrument renders ${VIEWPORT.width}x${VIEWPORT.height}: the project it mirrors has changed`);
		}
		console.log(`baseline:  ${report.baseline} (${report.baselineSize})`);

		server = await startV4({ dataDir, env: { APP_PASSWORD: 'pw-visual', PUBLIC_URL: 'http://127.0.0.1:8080' } });
		console.log(`boot:      v4 ready at ${server.base} (wrangler dev through tests/visual/v4-server.cjs, no php)`);
		if (/\bphp\b/.test([server.command, ...server.args].join(' '))) throw new Error('the boot argv names php: the seam is not v4');
		report.boot = { base: server.base, command: path.relative(ROOT, server.command) };

		const rendered = await render(server.base);
		const actual = decodePng(rendered.shot);
		report.render = { status: rendered.status, title: rendered.title, h1: rendered.h1, size: `${actual.width}x${actual.height}`, bytes: rendered.shot.length };
		console.log(`render:    GET /login -> ${rendered.status}, ${actual.width}x${actual.height}, <title> ${JSON.stringify(rendered.title)}`);
		fs.writeFileSync(path.join(OUT, 'actual.png'), rendered.shot);
		if (rendered.status !== 200) throw new Error(`GET /login answered ${rendered.status}`);

		// DATA: the app against the committed baseline. A difference is a G1 finding, not a harness
		// failure, so it is reported and only fails the step under --strict-baseline.
		if (actual.width !== expected.width || actual.height !== expected.height) {
			findings.push(`size: the app rendered ${actual.width}x${actual.height}, the baseline is ${expected.width}x${expected.height}`);
		} else {
			const diff = locateDiff(expected, actual);
			report.appVsBaseline = { count: diff.count, box: diff.box, text: diff.text };
			console.log(`data:      ${SPEC} › login.png: ${diff.text}`);
			if (diff.count === 0) console.log('data:      v4\'s /login is pixel-identical to the v3 baseline at this viewport');
			else if (strictBaseline) findings.push(`${SPEC} › login.png: ${diff.text}`);
			else console.log('data:      this is a finding for G1, not a harness failure (pass --strict-baseline to fail on it)');
		}

		// CONTROL: one pixel, planted where this script can point at it, must be located exactly.
		const base = pixelAt(actual, PLANT.x, PLANT.y);
		const colour = plantColour(base);
		const plantedImage = withPixel(actual, PLANT.x, PLANT.y, [...colour, 255]);
		const planted = encodePng(plantedImage);
		fs.writeFileSync(path.join(OUT, 'planted.png'), planted);
		const control = locateDiff(decodePng(planted), actual);
		const verdict = controlVerdict(control, PLANT);
		report.control = { plantedColour: colour, coveredPixel: base, count: control.count, box: control.box, ok: verdict.ok, why: verdict.why };
		console.log(`control:   planted rgb(${colour.join(',')}) over rgb(${base.slice(0, 3).join(',')}) at (${PLANT.x},${PLANT.y}) -> ${verdict.ok ? 'PASS' : 'FAIL'}: ${verdict.why}`);
		if (!verdict.ok) findings.push(`control: ${verdict.why}`);

		// The wording D51 asks for: the red names the spec and the pixel.
		console.log(`red form:  ${SPEC} › login.png: ${control.text}, expected at (${PLANT.x},${PLANT.y})`);
	} catch (error) {
		findings.push(error instanceof Error ? error.message : String(error));
	} finally {
		if (server) server.stop();
		fs.rmSync(dataDir, { recursive: true, force: true });
	}
	report.findings = findings;
	fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2) + '\n');
	if (findings.length) {
		console.error(`\nvisual-v4-instrument: ${findings.length} finding(s)`);
		for (const f of findings) console.error('  - ' + f);
		return false;
	}
	console.log('\nvisual-v4-instrument: ok (control located its pixel; see the data line for the app)');
	return true;
}

/* --------------------------------------------------------------------------------------------- */

const args = process.argv.slice(2);
const ok = args.includes('--self-test') ? selfTest() : await live({ strictBaseline: args.includes('--strict-baseline') });
process.exit(ok ? 0 : 1);
