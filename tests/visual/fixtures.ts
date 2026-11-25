import { test as base, expect, Page, TestInfo } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import { startV4 } from './v4-server.cjs';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import * as net from 'node:net';

export const ROOT = path.resolve(__dirname, '../..');
/** Frozen server clock (UTC) – all timestamps and "last 30 days" charts are deterministic. */
export const CLOCK = '2026-06-15 09:00:00';

/**
 * The app under test: v4 on workerd by default (G1's boot seam - see ./v4-server.cjs and
 * v4/scripts/check-visual-boot.mjs); `E2E_APP=php` boots v3 through `php -S`; with
 * E2E_DOCKER_IMAGE=<image> the real container (nginx + php-fpm) instead, with the repo mounted
 * read-only at /repo so seed/SQL helpers run inside it.
 *
 * v4 became the default at G1's flip. The 112 keys in ci4-unported.json name every test whose page v4
 * refuses, so the run skips what it cannot measure instead of reading it as a page that renders wrong,
 * and those keys come from a live run rather than a hand. v4 is not the whole suite yet: 102 pages
 * answer 501 and the four specs that shell `php` skip in their own files, so a green default run is
 * three tests (v4/scripts/check-visual-flip.mjs names them), not 170. v3 stays reachable because the
 * 512 rendered baselines are v3's until phase 2 ports the pages that own them: the renderer pins
 * E2E_APP=php (snapshots.yml, scripts/update-snapshots.sh), and so does the pgsql smoke project,
 * whose subject is v3's PostgreSQL driver rather than a page.
 */
type Server = {
  base: string; dataDir: string; filesDir: string; env: NodeJS.ProcessEnv;
  /** Run PHP CLI with the app's environment (host php, or `docker exec` as www-data). Paths in args: use `at()`. */
  php: (args: string[]) => Buffer;
  /** Map a repo / data / files path to where the PHP process sees it. */
  at: (p: string) => string;
  /** v4 only: stop the app, wipe its persist directory and boot it again on the same port, so v4's own
   *  migrate+seed phase rebuilds the database. That is `reset`'s seeding step (G1), and it starts no `php`. */
  restartV4?: () => Promise<void>;
};
export const DOCKER_IMAGE = process.env.E2E_DOCKER_IMAGE || '';
const REQUESTED_APP = process.env.E2E_APP ?? '';
if (REQUESTED_APP && REQUESTED_APP !== 'php' && REQUESTED_APP !== 'v4') {
  throw new Error(`E2E_APP=${REQUESTED_APP} is not an app: leave it unset for v4, or say php for v3`);
}
/** Which app the harness boots: v4 (workerd) unless `E2E_APP=php` asks for v3, which the Docker image always does. */
export const APP = REQUESTED_APP === 'php' || (!REQUESTED_APP && DOCKER_IMAGE) ? 'php' : 'v4';
type WorkerOpts = { appPassword: string; dbDriver: 'sqlite' | 'pgsql'; publicUrl: string; server: Server };
type TestOpts = { seed: 'demo' | 'empty'; reset: void; errors: string[]; app: App; ci4Unported: void };

/**
 * Tests that need a page CI4 has not ported yet, skipped (docs/HANDOFF.md keeps this list).
 * Key: "<spec file>: <describe> › <test title>" (the title path); value: the missing page. A key that names no
 * test is a finding: v4/scripts/check-visual-skip-list.mjs reads this file and fails the build, which is the
 * replacement for tests/Unit/Ci4E2eSkipListTest.php, retired with the PHP suite by D36. The keys themselves live
 * in ci4-unported.json, beside this file, so that the check and this fixture read one copy - edit that, not this.
 */
export const CI4_UNPORTED: Record<string, string> = JSON.parse(
  fs.readFileSync(path.join(__dirname, 'ci4-unported.json'), 'utf8'),
);

/**
 * v4 only: the paths v4 answered 501 for during the current test, from any context.
 * v4 serves /login and the sign-in chain and answers 501 for every page no slice has ported; the
 * `errors` fixture turns this set into a `v4-unported` annotation, and v4/scripts/visual-v4-map.mjs
 * turns that into a CI4_UNPORTED entry, so the map comes from a run (D49) rather than a red read by
 * hand. Under v3 nothing writes to it.
 */
const UNPORTED = new Set<string>();

/**
 * v4 only: watch an APIRequestContext. `page.request.get('/x')` raises no `response` event on
 * the page or on the context (D76), so a spec that reads a page that way - qa-fixes.spec.ts does, on
 * its first line - got a 501 no listener could see, and the map called it unexplained. Wrapping the
 * verb methods records the status the context got, which is the only place it exists.
 */
const watchApi = <T extends object>(ctx: T): T => {
  const c = ctx as unknown as Record<string, unknown>;
  for (const name of ['get', 'post', 'put', 'patch', 'delete', 'head', 'fetch']) {
    const original = c[name];
    if (typeof original !== 'function') continue;
    const call = (original as (...args: unknown[]) => Promise<{ status: () => number; url: () => string }>).bind(ctx);
    c[name] = async (...args: unknown[]) => {
      const response = await call(...args);
      if (response.status() === 501) UNPORTED.add(new URL(response.url()).pathname);
      return response;
    };
  }
  return ctx;
};

const freePort = () => new Promise<number>((res, rej) => {
  const s = net.createServer();
  s.listen(0, '127.0.0.1', () => { const p = (s.address() as net.AddressInfo).port; s.close(() => res(p)); });
  s.on('error', rej);
});

/** Wait until nothing listens on `port`: a stopped process group releases it a moment after SIGTERM. */
async function waitPortClosed(port: number) {
  for (let i = 0; i < 200; i++) {
    const closed = await new Promise<boolean>(res => {
      const s = net.connect({ port, host: '127.0.0.1' });
      s.once('connect', () => { s.destroy(); res(false); });
      s.once('error', () => res(true));
    });
    if (closed) return;
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error(`the stopped v4 process still holds 127.0.0.1:${port}`);
}

async function waitUp(base: string) {
  for (let i = 0; i < 300; i++) {
    try { const r = await fetch(base + '/healthz'); if (r.ok) return; } catch { /* not yet */ }
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('app server did not start at ' + base);
}

/**
 * Fixture work through the app's own code: tests/visual/ci4-fixtures.php.
 *
 * Under v4 it refuses rather than answering: ci4-fixtures.php writes the PHP app's `DATA_DIR`,
 * which v4 never reads, so a silent success here would be a fixture that seeds nothing. v4's own
 * migrate+seed phase runs at boot (`/readyz`), which `startV4` awaits; the catalog fixtures the v3
 * suite uses per test are G1's next step and do not exist on v4 yet.
 */
function fixture(server: Server, op: 'seed' | 'sql' | 'exec' | 'migrate' | 'default-category' | 'settings', ...args: string[]): Buffer {
  if (APP === 'v4') {
    throw new Error(`the visual harness's \`${op}\` fixture runs tests/visual/ci4-fixtures.php inside PHP, and v4 has `
      + 'no equivalent yet. Seeding is not this function under v4: `reset` calls `server.restartV4`, which reseeds '
      + 'v4 through v4 itself. An `' + op + '` call means the spec is asserting on the catalog, and no v4 page serves '
      + 'it yet - a fact about the port, not about the fixture.');
  }
  return server.php([server.at(path.join(ROOT, 'tests/visual/ci4-fixtures.php')), op, ...args]);
}

export class App {
  constructor(public page: Page, public server: Server, private info: TestInfo) {}
  get base() { return this.server.base; }
  get mobile() { return !!this.info.project.use.isMobile; }
  /** Navigate and wait until the page is idle. */
  async go(p: string) { await this.page.goto(p, { waitUntil: 'networkidle' }); }
  /** Expand the Overview (phones start with it collapsed) and wait for its content. */
  async openOverview() {
    const s = this.page.locator('#stats');
    if ((await s.getAttribute('open')) === null) await s.locator('summary').click();
    await expect(this.page.locator('#stats .tile').first()).toBeVisible();
  }
  /** A small deterministic JPEG/PNG on disk, for file inputs. */
  image(name: string, rgb: [number, number, number], png = false): string {
    if (APP === 'v4') throw new Error('the visual harness\'s image() fixture shells `php` for GD; under v4 '
      + 'no `php` process is started, so a spec that uploads a photo cannot run until the upload path has a v4 fixture.');
    const out = path.join(this.server.filesDir, name);
    if (fs.existsSync(out)) return out;
    const code = `$im=imagecreatetruecolor(640,480);imagefill($im,0,0,imagecolorallocate($im,${rgb.join(',')}));`
      + `imagefilledellipse($im,320,240,300,300,imagecolorallocatealpha($im,255,255,255,60));` + (png ? `imagepng($im,$argv[1]);` : `imagejpeg($im,$argv[1],85);`);
    this.server.php(['-r', code, this.server.at(out)]);
    return out;
  }
  /** Read the SQLite/PostgreSQL catalog through the app's own PHP (driver-independent). */
  sql<T = Record<string, unknown>>(query: string): T[] {
    return JSON.parse(fixture(this.server, 'sql', query).toString());
  }
  /** Run one SQL statement through the app's own PHP. */
  exec(statement: string) { fixture(this.server, 'exec', statement); }
  /** Apply pending migrations, as the app's first request would. */
  migrate() { fixture(this.server, 'migrate'); }
  /** Create a category and make it the default. */
  defaultCategory(name: string) { fixture(this.server, 'default-category', name); }
  /** Store settings (name => value) through the app. */
  settings(values: Record<string, unknown>) { fixture(this.server, 'settings', JSON.stringify(values)); }
  async noOverflow() {
    const w = await this.page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    expect(w[0], 'no horizontal overflow').toBeLessThanOrEqual(w[1]);
  }
  /** Every visible control is at least 44×44 CSS px on touch devices. */
  async tapTargets() {
    if (!this.mobile) return;
    const small = await this.page.evaluate(() => [...document.querySelectorAll<HTMLElement>(
      'button, .btn, summary, select, input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not(.file):not(.vh), .seg label, .tagchip-x')]
      .filter(e => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e);
        return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && !e.closest('.foot, [hidden], .menu:not([open]) .menu-panel, .fsheet:not([open]) .fpanel')
          && (Math.round(r.height) < 44 || Math.round(r.width) < 44); })
      .map(e => `${e.tagName.toLowerCase()}#${e.id}.${String(e.className).replace(/\s+/g, '.')} ${Math.round(e.getBoundingClientRect().width)}×${Math.round(e.getBoundingClientRect().height)}`));
    expect(small, 'tap targets ≥ 44px').toEqual([]);
  }
  /** Opens the ☰ menu. */
  async menu() {
    await this.page.locator('#menu-btn').click();
    await expect(this.page.locator('#menu .menu-panel')).toBeVisible();
  }
}

export const test = base.extend<TestOpts, WorkerOpts>({
  appPassword: ['', { option: true, scope: 'worker' }],
  dbDriver: ['sqlite', { option: true, scope: 'worker' }],
  // Fixed QR/label base URL for screenshots (the server port is random).
  publicUrl: ['', { option: true, scope: 'worker' }],
  server: [async ({ appPassword, dbDriver, publicUrl }, use, workerInfo) => {
    const port = await freePort();
    const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), `apotheke-pw-${workerInfo.workerIndex}-`));
    const filesDir = dataDir + '-files';
    fs.mkdirSync(filesDir);
    const appEnv: Record<string, string> = { TZ: 'Europe/Athens', APOTHEKE_CLOCK: CLOCK, APP_PASSWORD: appPassword, PUBLIC_URL: publicUrl,
      SEED_DEFAULTS: '0', APOTHEKE_CONFIG: '/nonexistent', DB_DRIVER: dbDriver };
    if (dbDriver === 'pgsql') Object.assign(appEnv, { DB_SCHEMA: `pw_${workerInfo.workerIndex}_${process.pid}`, DB_HOST: process.env.TEST_PG_HOST || '127.0.0.1',
      DB_PORT: process.env.TEST_PG_PORT || '5432', DB_NAME: process.env.TEST_PG_DB || 'apotheke', DB_USER: process.env.TEST_PG_USER || 'apotheke',
      DB_PASSWORD: process.env.TEST_PG_PASSWORD || 'apotheke' });
    let log = '';
    const collect = (d: Buffer) => { log += d; if (log.length > 200_000) log = log.slice(-100_000); };
    let server: Server, stop: () => void;
    if (APP === 'v4') {
      // G1's boot seam. `startV4` is the module v4/scripts/check-visual-boot.mjs boots on every push,
      // so this branch is exercised by CI even though no workflow runs Playwright: what it proves here
      // is that the harness starts v4 and no `php -S`, and what it does not prove is a rendered page.
      const boot = (port?: number) =>
        startV4({ dataDir, port, env: appEnv, log: (chunk: string) => collect(Buffer.from(chunk)) });
      let v4 = await boot();
      server = { base: v4.base, dataDir, filesDir, env: appEnv, at: (p: string) => p,
        php: (args: string[]) => execFileSync('php', args, { env: appEnv }),
        // G1's seeding step. v3 reseeds by running tests/visual/ci4-fixtures.php; v4 has no PHP to run,
        // so it reseeds the way v4 seeds: a wiped persist directory and its own migrate+seed on /readyz.
        // Same port, because `baseURL` and the browser's cookies are bound to the origin.
        restartV4: async () => {
          const port = v4.port;
          v4.stop();
          await waitPortClosed(port);
          fs.rmSync(path.join(dataDir, 'v4'), { recursive: true, force: true });
          v4 = await boot(port);
        } };
      stop = () => v4.stop();   // stops the process group, so workerd does not outlive wrangler
    } else if (DOCKER_IMAGE) {
      const name = `apotheke-pw-${workerInfo.workerIndex}-${process.pid}`;
      const env = { ...appEnv, DATA_DIR: '/data', ALLOW_NO_LOGIN: '1' };   // start like php -S: sign-in off unless a test turns it on
      const e = Object.entries(env).flatMap(([k, v]) => ['-e', `${k}=${v}`]);
      fs.chmodSync(filesDir, 0o777);
      execFileSync('docker', ['run', '-d', '--rm', '--name', name, '--network', 'host', '-e', `PORT=${port}`, ...e,
        '-v', `${dataDir}:/data`, '-v', `${filesDir}:/files`, '-v', `${ROOT}:/repo:ro`, DOCKER_IMAGE]);
      const logs = spawn('docker', ['logs', '-f', name], { stdio: ['ignore', 'ignore', 'pipe'] });
      logs.stderr!.on('data', collect);
      const at = (p: string) => p.startsWith(dataDir + '-files') ? '/files' + p.slice(filesDir.length)
        : p.startsWith(dataDir) ? '/data' + p.slice(dataDir.length) : p.startsWith(ROOT) ? '/repo' + p.slice(ROOT.length) : p;
      server = { base: `http://127.0.0.1:${port}`, dataDir, filesDir, env, at,
        php: args => execFileSync('docker', ['exec', '-u', 'www-data', ...e, '-e', 'APOTHEKE_FIXTURE_APP=/var/www/app', name, 'php', ...args]) };
      stop = () => {
        try { execFileSync('docker', ['exec', name, 'sh', '-c', 'rm -rf /data/* /files/*']); } catch { /* already gone */ }
        execFileSync('docker', ['rm', '-f', name], { stdio: 'ignore' });
        logs.kill();
      };
    } else {
      const env: NodeJS.ProcessEnv = { ...process.env, ...appEnv, DATA_DIR: dataDir };
      const proc = spawn('php', ['-d', 'upload_max_filesize=20M', '-d', 'post_max_size=22M', '-d', 'memory_limit=256M',
        '-S', `127.0.0.1:${port}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'public/router.php')], { env, stdio: ['ignore', 'ignore', 'pipe'] });
      proc.stderr!.on('data', collect);
      server = { base: `http://127.0.0.1:${port}`, dataDir, filesDir, env, at: p => p, php: args => execFileSync('php', args, { env }) };
      stop = () => { proc.kill(); };
    }
    if (APP !== 'v4') {
      await waitUp(server.base);   // first: in Docker the entrypoint must have prepared /data
      fixture(server, 'seed', server.at(dataDir), 'empty');
    }   // v4: startV4 resolved on /readyz, which is the app's own migration and seed phase
    await use(server);
    stop();
    if (/\[apotheke\]|PHP (Fatal|Warning|Notice|Deprecated)/.test(log)) console.error('server log:\n' + log.split('\n').filter(l => /apotheke|PHP /.test(l)).join('\n'));
    for (const d of [dataDir, filesDir]) {
      try { fs.rmSync(d, { recursive: true, force: true }); } catch { /* owned by the container's www-data under sticky /tmp */ }
    }
  }, { scope: 'worker' }],
  baseURL: async ({ server }, use) => { await use(server.base); },
  seed: ['demo', { option: true }],
  /** Fresh, deterministic state for every test: v3 reseeds its data directory, v4 boots on a wiped one. */
  reset: [async ({ server, seed }, use, info) => {
    if (server.restartV4) {
      // D81: the boot is per test (restartV4 wipes and reboots on /readyz). Record that this test got
      // a live app, so the harvest can count "N booted / M dead" from the report itself instead of
      // inferring a boot from a failure that does not name one.
      try {
        await server.restartV4();
      } catch (error) {
        info.annotations.push({ type: 'v4-boot-failed', description: String(error) });
        throw error;
      }
      info.annotations.push({ type: 'v4-booted', description: server.base });
    } else fixture(server, 'seed', server.at(server.dataDir), seed);
    await use();
  }, { auto: true }],
  /** Console errors and uncaught page errors fail the test. */
  errors: [async ({ page, browserName }, use, info) => {
    const errors: string[] = [];
    /**
     * v4 only: the paths v4 answered 501 for. v4 serves /login and the sign-in chain and
     * answers 501 for every page no slice has ported, so a test that wandered into one fails on its
     * own assertion - this annotation is what names the page. v4/scripts/visual-v4-map.mjs turns it
     * into a CI4_UNPORTED entry, so the map comes from a run (D49) rather than from a red read by
     * hand. Under v3 no listener is installed and the set stays empty.
     */
    UNPORTED.clear();
    if (APP === 'v4') {
      page.on('response', r => { if (r.status() === 501) UNPORTED.add(new URL(r.url()).pathname); });
    }
    page.on('pageerror', e => errors.push('pageerror: ' + e.message));
    page.on('console', m => {
      if (m.type() !== 'error') return;
      if (/status of (401|404|409|410|419|422)/.test(m.text())) return;   // intentional error responses
      // WebKit-internal: its UA shadow-root styles for <select>/<details> trip a CSP report under style-src 'self'
      // (no inline styles exist in the app; Chromium is checked without this exemption).
      if (browserName === 'webkit' && /^Refused to apply a stylesheet because its hash/.test(m.text())) return;
      errors.push('console: ' + m.text());
    });
    await use(errors);
    for (const p of [...UNPORTED].sort()) info.annotations.push({ type: 'v4-unported', description: p });
    expect(errors, 'no console errors').toEqual([]);
  }, { auto: true }],
  /**
   * v4 only: watch every context the spec opens, not just the fixture's `page`. The consent
   * spec and the no-JavaScript tests call `browser.newContext()` themselves, so a listener on `page`
   * alone never sees the 501 their first navigation gets - and a red the harness cannot explain is a
   * red the map would have to absorb without a reason.
   */
  browser: async ({ browser }, use) => {
    if (APP === 'v4') {
      const open = browser.newContext.bind(browser);
      browser.newContext = async (...args: Parameters<typeof open>) => {
        const ctx = await open(...args);
        ctx.on('response', r => { if (r.status() === 501) UNPORTED.add(new URL(r.url()).pathname); });
        watchApi(ctx.request);
        return ctx;
      };
    }
    await use(browser);
  },
  page: async ({ page }, use) => {
    // `page.request` is an APIRequestContext: its responses fire no event, so it is wrapped here.
    if (APP === 'v4') watchApi(page.request);
    await use(page);
  },
  app: async ({ page, server }, use, info) => { await use(new App(page, server, info)); },
  ci4Unported: [async ({}, use, info) => {
    // Under v3 these same tests own the 512 rendered baselines, so the map must not skip them
    // there: an ungated lookup silences 115 tests in every v3 run, and no workflow would notice
    // (nothing runs tests/visual). v4/scripts/check-visual-skip-list.mjs pins the gate.
    const why = APP === 'v4' ? CI4_UNPORTED[`${path.basename(info.file)}: ${info.titlePath.slice(1).join(' › ')}`] : undefined;
    info.skip(why !== undefined, `not ported to CI4 yet: ${why}`);
    await use();
  }, { auto: true }],
});

export { expect };
