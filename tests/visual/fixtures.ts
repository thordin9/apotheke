import { test as base, expect, Page, TestInfo } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import * as net from 'node:net';

export const ROOT = path.resolve(__dirname, '../..');
/** Frozen server clock (UTC) – all timestamps and "last 30 days" charts are deterministic. */
export const CLOCK = '2026-06-15 09:00:00';

/**
 * The app under test. By default `php -S` on the host; with E2E_DOCKER_IMAGE=<image> the real container
 * (nginx + php-fpm) instead, with the repo mounted read-only at /repo so seed/SQL helpers run inside it.
 */
type Server = {
  base: string; dataDir: string; filesDir: string; env: NodeJS.ProcessEnv;
  /** Run PHP CLI with the app's environment (host php, or `docker exec` as www-data). Paths in args: use `at()`. */
  php: (args: string[]) => Buffer;
  /** Map a repo / data / files path to where the PHP process sees it. */
  at: (p: string) => string;
};
export const DOCKER_IMAGE = process.env.E2E_DOCKER_IMAGE || '';
/** E2E_APP=ci4 runs the host server on the CodeIgniter 4 port (ci4/public and its router) instead of legacy. */
export const APP: 'legacy' | 'ci4' = process.env.E2E_APP === 'ci4' ? 'ci4' : 'legacy';
type WorkerOpts = { appPassword: string; dbDriver: 'sqlite' | 'pgsql'; publicUrl: string; server: Server };
type TestOpts = { seed: 'demo' | 'empty'; reset: void; errors: string[]; app: App; ci4Unported: void };

/**
 * Tests that need a page CI4 has not ported yet, skipped under E2E_APP=ci4 (docs/HANDOFF.md keeps this list).
 * Key: "<spec file>: <describe> › <test title>" (the title path); value: the missing page. A key that names no
 * test fails tests/Unit/Ci4E2eSkipListTest.php, so a stale entry cannot linger.
 */
export const CI4_UNPORTED: Record<string, string> = {
};

const freePort = () => new Promise<number>((res, rej) => {
  const s = net.createServer();
  s.listen(0, '127.0.0.1', () => { const p = (s.address() as net.AddressInfo).port; s.close(() => res(p)); });
  s.on('error', rej);
});

async function waitUp(base: string) {
  for (let i = 0; i < 300; i++) {
    try { const r = await fetch(base + '/healthz'); if (r.ok) return; } catch { /* not yet */ }
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('app server did not start at ' + base);
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
    const out = path.join(this.server.filesDir, name);
    if (fs.existsSync(out)) return out;
    const code = `$im=imagecreatetruecolor(640,480);imagefill($im,0,0,imagecolorallocate($im,${rgb.join(',')}));`
      + `imagefilledellipse($im,320,240,300,300,imagecolorallocatealpha($im,255,255,255,60));` + (png ? `imagepng($im,$argv[1]);` : `imagejpeg($im,$argv[1],85);`);
    this.server.php(['-r', code, this.server.at(out)]);
    return out;
  }
  /** Read the SQLite/PostgreSQL catalog through the app's own PHP (driver-independent). */
  sql<T = Record<string, unknown>>(query: string): T[] {
    const code = `require '${this.server.at(ROOT)}/src/bootstrap.php'; echo json_encode(db()->query($argv[1])->fetchAll());`;
    return JSON.parse(this.server.php(['-r', code, query]).toString());
  }
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
    if (DOCKER_IMAGE) {
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
        php: args => execFileSync('docker', ['exec', '-u', 'www-data', ...e, name, 'php', ...args]) };
      stop = () => {
        try { execFileSync('docker', ['exec', name, 'sh', '-c', 'rm -rf /data/* /files/*']); } catch { /* already gone */ }
        execFileSync('docker', ['rm', '-f', name], { stdio: 'ignore' });
        logs.kill();
      };
    } else {
      const env: NodeJS.ProcessEnv = { ...process.env, ...appEnv, DATA_DIR: dataDir };
      const proc = spawn('php', ['-d', 'upload_max_filesize=20M', '-d', 'post_max_size=22M', '-d', 'memory_limit=256M',
        '-S', `127.0.0.1:${port}`, ...(APP === 'ci4' ? ['-t', path.join(ROOT, 'ci4/public'), path.join(ROOT, 'ci4/public/router.php')]
          : ['-t', path.join(ROOT, 'public'), path.join(ROOT, 'public/index.php')])], { env, stdio: ['ignore', 'ignore', 'pipe'] });
      proc.stderr!.on('data', collect);
      server = { base: `http://127.0.0.1:${port}`, dataDir, filesDir, env, at: p => p, php: args => execFileSync('php', args, { env }) };
      stop = () => { proc.kill(); };
    }
    await waitUp(server.base);   // first: in Docker the entrypoint must have prepared /data
    server.php([server.at(path.join(ROOT, 'tests/visual/seed.php')), server.at(dataDir), 'empty']);
    await use(server);
    stop();
    if (/\[apotheke\]|PHP (Fatal|Warning|Notice|Deprecated)/.test(log)) console.error('server log:\n' + log.split('\n').filter(l => /apotheke|PHP /.test(l)).join('\n'));
    for (const d of [dataDir, filesDir]) {
      try { fs.rmSync(d, { recursive: true, force: true }); } catch { /* owned by the container's www-data under sticky /tmp */ }
    }
  }, { scope: 'worker' }],
  baseURL: async ({ server }, use) => { await use(server.base); },
  seed: ['demo', { option: true }],
  /** Fresh, deterministic catalog for every test. */
  reset: [async ({ server, seed }, use) => {
    server.php([server.at(path.join(ROOT, 'tests/visual/seed.php')), server.at(server.dataDir), seed]);
    await use();
  }, { auto: true }],
  /** Console errors and uncaught page errors fail the test. */
  errors: [async ({ page, browserName }, use) => {
    const errors: string[] = [];
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
    expect(errors, 'no console errors').toEqual([]);
  }, { auto: true }],
  app: async ({ page, server }, use, info) => { await use(new App(page, server, info)); },
  ci4Unported: [async ({}, use, info) => {
    const why = APP === 'ci4' ? CI4_UNPORTED[`${path.basename(info.file)}: ${info.titlePath.slice(1).join(' › ')}`] : undefined;
    info.skip(why !== undefined, `not ported to CI4 yet: ${why}`);
    await use();
  }, { auto: true }],
});

export { expect };
