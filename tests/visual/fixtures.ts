import { test as base, expect, Page, TestInfo } from '@playwright/test';
import { spawn, execFileSync, ChildProcess } from 'node:child_process';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import * as net from 'node:net';

export const ROOT = path.resolve(__dirname, '../..');
/** Frozen server clock (UTC) – all timestamps and "last 30 days" charts are deterministic. */
export const CLOCK = '2026-06-15 09:00:00';

type Server = { base: string; dataDir: string; env: NodeJS.ProcessEnv; proc: ChildProcess };
type WorkerOpts = { appPassword: string; dbDriver: 'sqlite' | 'pgsql'; publicUrl: string; server: Server };
type TestOpts = { seed: 'demo' | 'empty'; reset: void; errors: string[]; app: App };

const freePort = () => new Promise<number>((res, rej) => {
  const s = net.createServer();
  s.listen(0, '127.0.0.1', () => { const p = (s.address() as net.AddressInfo).port; s.close(() => res(p)); });
  s.on('error', rej);
});

async function waitUp(base: string) {
  for (let i = 0; i < 100; i++) {
    try { const r = await fetch(base + '/healthz'); if (r.ok) return; } catch { /* not yet */ }
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('php -S did not start at ' + base);
}

export class App {
  constructor(public page: Page, public server: Server, private info: TestInfo) {}
  get base() { return this.server.base; }
  get mobile() { return !!this.info.project.use.isMobile; }
  /** Navigate and wait until the page is idle. */
  async go(p: string) { await this.page.goto(p, { waitUntil: 'networkidle' }); }
  /** A small deterministic JPEG/PNG on disk, for file inputs. */
  image(name: string, rgb: [number, number, number], png = false): string {
    const out = path.join(this.server.dataDir + '-files', name);
    if (fs.existsSync(out)) return out;
    fs.mkdirSync(path.dirname(out), { recursive: true });
    const code = `$im=imagecreatetruecolor(640,480);imagefill($im,0,0,imagecolorallocate($im,${rgb.join(',')}));`
      + `imagefilledellipse($im,320,240,300,300,imagecolorallocatealpha($im,255,255,255,60));` + (png ? `imagepng($im,$argv[1]);` : `imagejpeg($im,$argv[1],85);`);
    execFileSync('php', ['-r', code, out]);
    return out;
  }
  /** Read the SQLite/PostgreSQL catalog through the app's own PHP (driver-independent). */
  sql<T = Record<string, unknown>>(query: string): T[] {
    const code = `require '${ROOT}/src/bootstrap.php'; echo json_encode(db()->query($argv[1])->fetchAll());`;
    return JSON.parse(execFileSync('php', ['-r', code, query], { env: this.server.env }).toString());
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
    const env: NodeJS.ProcessEnv = { ...process.env, DATA_DIR: dataDir, TZ: 'Europe/Athens', APOTHEKE_CLOCK: CLOCK, APP_PASSWORD: appPassword, PUBLIC_URL: publicUrl,
      SEED_DEFAULTS: '0', APOTHEKE_CONFIG: '/nonexistent', DB_DRIVER: dbDriver };
    if (dbDriver === 'pgsql') Object.assign(env, { DB_SCHEMA: `pw_${workerInfo.workerIndex}_${process.pid}`, DB_HOST: process.env.TEST_PG_HOST || '127.0.0.1',
      DB_PORT: process.env.TEST_PG_PORT || '5432', DB_NAME: process.env.TEST_PG_DB || 'apotheke', DB_USER: process.env.TEST_PG_USER || 'apotheke',
      DB_PASSWORD: process.env.TEST_PG_PASSWORD || 'apotheke' });
    execFileSync('php', [path.join(ROOT, 'tests/visual/seed.php'), dataDir, 'empty'], { env });
    const proc = spawn('php', ['-d', 'upload_max_filesize=20M', '-d', 'post_max_size=22M', '-d', 'memory_limit=256M',
      '-S', `127.0.0.1:${port}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'public/index.php')], { env, stdio: ['ignore', 'ignore', 'pipe'] });
    let log = '';
    proc.stderr!.on('data', d => { log += d; if (log.length > 200_000) log = log.slice(-100_000); });
    const server = { base: `http://127.0.0.1:${port}`, dataDir, env, proc };
    await waitUp(server.base);
    await use(server);
    proc.kill();
    if (/\[apotheke\]|PHP (Fatal|Warning|Notice|Deprecated)/.test(log)) console.error('server log:\n' + log.split('\n').filter(l => /apotheke|PHP /.test(l)).join('\n'));
    fs.rmSync(dataDir, { recursive: true, force: true });
    fs.rmSync(dataDir + '-files', { recursive: true, force: true });
  }, { scope: 'worker' }],
  baseURL: async ({ server }, use) => { await use(server.base); },
  seed: ['demo', { option: true }],
  /** Fresh, deterministic catalog for every test. */
  reset: [async ({ server, seed }, use) => {
    execFileSync('php', [path.join(ROOT, 'tests/visual/seed.php'), server.dataDir, seed], { env: server.env });
    await use();
  }, { auto: true }],
  /** Console errors and uncaught page errors fail the test. */
  errors: [async ({ page, browserName }, use) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push('pageerror: ' + e.message));
    page.on('console', m => {
      if (m.type() !== 'error') return;
      if (/status of (401|404|410|419|422)/.test(m.text())) return;   // intentional error pages
      // WebKit-internal: its UA shadow-root styles for <select>/<details> trip a CSP report under style-src 'self'
      // (no inline styles exist in the app; Chromium is checked without this exemption).
      if (browserName === 'webkit' && /^Refused to apply a stylesheet because its hash/.test(m.text())) return;
      errors.push('console: ' + m.text());
    });
    await use(errors);
    expect(errors, 'no console errors').toEqual([]);
  }, { auto: true }],
  app: async ({ page, server }, use, info) => { await use(new App(page, server, info)); },
});

export { expect };
