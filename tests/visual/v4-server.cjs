// Boots v4 as the app under test: real workerd through `wrangler dev`, no `php -S`.
//
// This is G1's boot seam (plan §6, Gate G1). It lives in its own module, with no `@playwright/test`
// import, for one reason: the same code that the visual harness calls is what
// `v4/scripts/check-visual-boot.mjs` boots on every push, so the seam is exercised by CI instead of
// being written and never run. The harness owns the fixture plumbing; this file owns the process.
//
// It is CommonJS on purpose. `tests/visual/fixtures.ts` is compiled to CommonJS (the repo root has no
// `"type": "module"`), so it `require`s this file; a `.mjs` here would make the harness depend on
// Node's `require(esm)` support, which is unflagged only from 22.12. A `.cjs` importable from both
// sides is the boring answer, and this is a file whose whole job is to start reliably.
//
// Two things are deliberate:
//   - The app's state is `--persist-to <dataDir>/v4`, a temp directory per worker, so one worker's
//     catalog never leaks into another's and `stop()` leaves nothing behind. Migrations and the
//     seed phase are the app's own (`/readyz` gates on them), so the first request to a fresh
//     directory is the request that builds it; that is why readiness, not `/healthz`, is awaited.
//   - The process is spawned `detached`, and stopped as a group. `wrangler dev` runs workerd as a
//     child; killing only wrangler leaves workerd holding the port, and the next worker's boot then
//     fails on a port that is "free" according to every probe but the kernel's.

const { spawn } = require('node:child_process');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');

const V4_ROOT = path.resolve(__dirname, '../../v4');

/** The v4 config value of `APOTHEKE_CLOCK`: an ISO instant, where v3's env takes `Y-m-d H:i:s`. */
const V4_CLOCK = '2026-06-15T09:00:00Z';

/**
 * The harness hands every app `APOTHEKE_CLOCK` in PHP's stored form (`2026-06-15 09:00:00`), because
 * that is what v3's env accepts; v4 reads an ISO instant (`src/platform/clock.ts`). Converting here
 * keeps one clock value in the fixture and one place that knows the two spellings.
 */
const isoClock = (value) => {
	const phpForm = /^(\d{4}-\d\d-\d\d) (\d\d:\d\d:\d\d)$/.exec(value ?? '');
	if (phpForm) return `${phpForm[1]}T${phpForm[2]}Z`;
	return value || V4_CLOCK;
};

const freePort = () =>
	new Promise((resolve, reject) => {
		const server = net.createServer();
		server.listen(0, '127.0.0.1', () => {
			const { port } = server.address();
			server.close(() => resolve(port));
		});
		server.on('error', reject);
	});

/**
 * `wrangler dev --var KEY:VALUE` pairs for the app environment the harness asks for.
 *
 * `DB_DRIVER` is fixed to `sqlite`: local `wrangler dev` has the D1 emulator and nothing else, so a
 * pgsql project under `E2E_APP=v4` would be sqlite in v4's eyes. That is stated rather than papered
 * over - the pgsql project keeps the v3 app until v4 has an rqlite boot for the harness to use.
 */
const v4Vars = (env = {}) => {
	const vars = {
		DB_DRIVER: 'sqlite',
		APOTHEKE_CLOCK: isoClock(env.APOTHEKE_CLOCK),
		APP_PASSWORD: env.APP_PASSWORD ?? '',
		PUBLIC_URL: env.PUBLIC_URL ?? '',
		SEED_DEFAULTS: env.SEED_DEFAULTS ?? '0',
		TZ: env.TZ ?? 'Europe/Athens',
	};
	return Object.entries(vars).flatMap(([key, value]) => ['--var', `${key}:${value}`]);
};

/** The exact argv the seam runs. Exported so the check can assert it names wrangler and never php. */
const v4Args = ({ port, persist, env }) => [
	'dev',
	'--ip', '127.0.0.1',
	'--port', String(port),
	'--persist-to', persist,
	'--log-level', 'warn',
	'--show-interactive-dev-session=false',
	...v4Vars(env),
];

/**
 * Start v4 on a free port. Resolves once `/readyz` answers 200 - i.e. once the app's own migration
 * and seed phases have run against `dataDir/v4` - and rejects with the tail of the process log
 * otherwise, because "it did not start" without the reason is the least useful failure there is.
 */
const startV4 = async ({ dataDir, env = {}, log = () => {}, timeoutMs = 120_000 }) => {
	const binary = path.join(V4_ROOT, 'node_modules', '.bin', 'wrangler');
	if (!fs.existsSync(binary)) {
		throw new Error(`${binary} is missing: run \`npm install\` in v4/ before the visual suite`);
	}
	const persist = path.join(dataDir, 'v4');
	fs.mkdirSync(persist, { recursive: true });
	const port = await freePort();
	const args = v4Args({ port, persist, env });
	const proc = spawn(binary, args, {
		cwd: V4_ROOT,
		detached: true,
		env: { ...process.env, WRANGLER_SEND_METRICS: 'false', NO_COLOR: '1' },
		stdio: ['ignore', 'ignore', 'pipe'],
	});
	let tail = '';
	proc.stderr.on('data', (chunk) => {
		tail = (tail + chunk).slice(-8000);
		log(chunk);
	});
	const base = `http://127.0.0.1:${port}`;
	const stop = () => {
		try {
			process.kill(-proc.pid, 'SIGTERM');
		} catch {
			/* already gone */
		}
	};
	const deadline = Date.now() + timeoutMs;
	for (;;) {
		if (proc.exitCode !== null) throw new Error(`wrangler dev exited (${proc.exitCode}):\n${tail}`);
		try {
			const response = await fetch(`${base}/readyz`);
			if (response.ok) return { base, port, persist, command: binary, args, stop, tail: () => tail };
			// Any answer at all is terminal, 503 included: the boot gate answers /readyz from a result
			// it has already computed, so a 503 is a decision (a refused seed, a failed migration) and
			// not "still starting". Waiting out the timeout would turn "the seed refused APP_PASSWORD"
			// into "v4 did not become ready", which is the least useful failure there is.
			stop();
			throw new Error(`/readyz answered ${response.status}: ${await response.text()}\n${tail}`);
		} catch (error) {
			if (error instanceof Error && /^\/readyz answered/.test(error.message)) throw error;
			/* not listening yet */
		}
		if (Date.now() > deadline) {
			stop();
			throw new Error(`v4 did not become ready at ${base} within ${timeoutMs} ms:\n${tail}`);
		}
		await new Promise((resolve) => setTimeout(resolve, 150));
	}
};

module.exports = { V4_ROOT, V4_CLOCK, isoClock, v4Vars, v4Args, startV4 };
