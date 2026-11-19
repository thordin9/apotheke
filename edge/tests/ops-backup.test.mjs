// C2 wiring (L-2167): ApothekeContainer.opsBackup signs the backup request with the installation's own APP_KEY and
// sends it to the container's port, not through the public forward. It may start a suspended or deleting installation;
// public requests still may not. The Workflow maps the answer and never holds APP_KEY. All key values are fakes.
// Loaded by r2.test.mjs, which registers the cloudflare:workers stub.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
const { ApothekeContainer } = await import('../src/index.ts');
const { installationAppKey } = await import('../src/installation.ts');
const { opsSignature, OPS_SIGNATURE_HEADER } = await import('../src/ops-signature.ts');
const { backupKey, lifecycleDeps } = await import('../src/lifecycle-workflow.ts');
const { EXPORT_STEP, LifecycleError, LifecycleRetry } = await import('../src/lifecycle.ts');

const MASTER = Buffer.alloc(32, 0x5a).toString('base64');
const quiet = async (fn) => { const e = console.error; console.error = () => {}; try { return await fn(); } finally { console.error = e; } };

/** The control migrations behind D1's prepare().bind().all()/first(). */
function controlDb(status) {
  const db = new DatabaseSync(':memory:');
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  db.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'tenant-b', 'installations/tenant-b/', '${status}');
    INSERT INTO installation_routes (host, installation_id) VALUES ('b.example.test', 'b');`);
  const bound = (sql, v) => ({
    all: async () => ({ results: db.prepare(sql).all(...v).map((r) => ({ ...r })) }),
    first: async () => { const r = db.prepare(sql).get(...v); return r ? { ...r } : null; },
  });
  return { raw: db, prepare: (sql) => ({ bind: (...v) => bound(sql, v) }) };
}

/** A container object with a fake container. answer(req) is the backup endpoint's response. */
function box(name, { status = 'active', running = false, answer } = {}) {
  const requests = [];
  const container = {
    running, starts: 0, destroys: 0,
    start() { container.starts += 1; container.running = true; },
    destroy: async () => { container.destroys += 1; container.running = false; },
    setInactivityTimeout: async () => {}, interceptOutboundHttp: async () => {},
    getTcpPort: () => ({ fetch: async (req) => {
      requests.push(req);
      const path = new URL(req.url).pathname;
      if (path === '/healthz') return new Response('ok');
      if (path === '/internal/backup') return answer ? answer(req) : Response.json({ ok: true, created: true, backup: { job_id: 'job-1', id: 'bk1', key: 'backups/bk1/manifest.json' } });
      return new Response('app');
    } }),
  };
  const db = controlDb(status);
  const ctx = { id: { name }, container, waitUntil() {}, blockConcurrencyWhile: (f) => f(), exports: { D1Outbound: () => ({}), R2Outbound: () => ({}) } };
  const env = { APP_PASSWORD: 'placeholder', APP_KEY: MASTER, ALLOWED_HOSTS: 'api.example.test', STORAGE: {}, INSTALLATION: {}, DB: db };
  return { b: new ApothekeContainer(ctx, env), container, requests, db };
}
const publicReq = () => new Request('https://b.example.test/api/items', { headers: { 'cf-connecting-ip': '192.0.2.1' } });

test('C2 wiring: the backup request is signed with the installation key, Host ops.internal, no forwarding headers, on the port', async () => {
  const { b, requests } = box('tenant-b', { running: true });
  const r = await b.opsBackup('job-1');
  assert.deepEqual(r, { key: 'installations/tenant-b/backups/bk1/manifest.json' });
  const req = requests.find((q) => new URL(q.url).pathname === '/internal/backup');
  assert.equal(req.method, 'POST');
  assert.equal(req.url, 'http://ops.internal/internal/backup');
  assert.equal(new URL(req.url).host, 'ops.internal');
  for (const h of ['x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-for', 'x-apotheke-client-ip']) assert.equal(req.headers.get(h), null, h);
  const body = await req.text();
  assert.equal(body, '{"job_id":"job-1"}');
  const sig = req.headers.get(OPS_SIGNATURE_HEADER);
  const t = Number(/^t=(\d+),/.exec(sig)[1]);
  assert.ok(Math.abs(t - Date.now() / 1000) < 5);
  assert.equal(sig, await opsSignature(await installationAppKey('tenant-b', MASTER), 'POST', '/internal/backup', body, t));
  assert.notEqual(sig, await opsSignature(MASTER, 'POST', '/internal/backup', body, t), 'not the Worker master key');
});

test('C2 wiring: the key is the installation prefix plus a relative backups/ key; anything else has no key', async () => {
  const ok = (key, extra = {}) => () => Response.json({ ok: true, created: false, backup: { job_id: 'job-1', key, ...extra } });
  for (const key of ['backups/bk1/manifest.json', 'backups/x']) {
    assert.deepEqual(await box('tenant-b', { running: true, answer: ok(key) }).b.opsBackup('job-1'), { key: `installations/tenant-b/${key}` });
  }
  for (const key of ['/backups/x', 'backups/../photo.jpg', 'backups/', 'backups//x', 'backups/./x', 'photos/x', '../tenant-a/backups/x', 'installations/tenant-b/backups/x', 42, null, undefined]) {
    assert.deepEqual(await box('tenant-b', { running: true, answer: ok(key) }).b.opsBackup('job-1'), { status: 200 }, String(key));
  }
  assert.deepEqual(await box('tenant-b', { running: true, answer: ok('backups/x', { job_id: 'job-2' }) }).b.opsBackup('job-1'), { status: 200 }, 'another job');
  assert.deepEqual(await box('tenant-b', { running: true, answer: () => Response.json({ ok: false, backup: { job_id: 'job-1', key: 'backups/x' } }) }).b.opsBackup('job-1'), { status: 200 });
  assert.deepEqual(await box('tenant-b', { running: true, answer: () => new Response('{"ok":tr') }).b.opsBackup('job-1'), { status: 502 }, 'a cut-off answer');
  for (const s of [400, 401, 404, 405, 409, 410, 500, 503]) {
    assert.deepEqual(await box('tenant-b', { running: true, answer: () => Response.json({ ok: false }, { status: s }) }).b.opsBackup('job-1'), { status: s });
  }
});

test('C2 wiring: 409, 5xx and an unfinished call are retried; 400, 401, 410, 503 and a bad key fail the job', async () => {
  for (const s of [409, 500, 502, 504]) await assert.rejects(backupKey(async () => ({ status: s })), (e) => e instanceof LifecycleRetry && e.code === `backup_${s}`);
  await assert.rejects(backupKey(async () => { throw new Error('network connection lost'); }), (e) => e instanceof LifecycleRetry && e.code === 'backup_unreachable');
  for (const s of [400, 401, 404, 410, 503]) await assert.rejects(backupKey(async () => ({ status: s })), (e) => e instanceof LifecycleError && e.code === `backup_${s}`);
  await assert.rejects(backupKey(async () => ({ status: 200 })), (e) => e instanceof LifecycleError && e.code === 'bad_export');
  assert.deepEqual(await backupKey(async () => ({ key: 'installations/tenant-b/backups/x/manifest.json' })), { key: 'installations/tenant-b/backups/x/manifest.json' });
});

test('L-2167: a suspended or deleting installation starts for the backup only; public requests stay refused', async () => {
  await quiet(async () => {
    for (const status of ['suspended', 'deleting']) {
      const { b, container, db } = box('tenant-b', { status });
      assert.equal((await b.fetch(publicReq())).status, 503, `${status}: public start refused`);
      assert.equal(container.starts, 0);
      assert.deepEqual(await b.opsBackup('job-1'), { key: 'installations/tenant-b/backups/bk1/manifest.json' });
      assert.equal(container.starts, 1, `${status}: started for the backup`);
      assert.equal((await b.fetch(publicReq())).status, 503, `${status}: not forwarded to the backup's container`);
      await b.stopContainer();
      assert.equal(container.running, false);
      assert.equal((await b.fetch(publicReq())).status, 503, `${status}: after the stop, still refused`);
      assert.equal(container.starts, 1);
      db.raw.exec(`UPDATE installations SET status = 'active'`);
      assert.equal((await b.fetch(publicReq())).status, 200, 'resumed: starts as usual');
    }
    // Active: a container the backup started serves public requests once the public gate passes.
    const a = box('tenant-b', { status: 'active' });
    await a.b.opsBackup('job-1');
    assert.equal((await a.b.fetch(publicReq())).status, 200);
    // deleted (purged) never starts, not even for a backup.
    const d = box('tenant-b', { status: 'deleted' });
    await assert.rejects(d.b.opsBackup('job-1'));
    assert.equal(d.container.starts, 0);
  });
});

test('C2 wiring: primary is refused and its container is never called', async () => {
  const { b, container, requests } = box('primary', { running: true });
  await assert.rejects(b.opsBackup('job-1'), /refused/);
  assert.deepEqual(requests, []);
  assert.equal(container.starts, 0);
});

test('C2 wiring: the Workflow calls the container object by name with the job id and holds no APP_KEY', async () => {
  const src = readFileSync(new URL('../src/lifecycle-workflow.ts', import.meta.url), 'utf8');
  const envType = /export type LifecycleEnv = \{([^}]*)\}/.exec(src)[1];
  assert.doesNotMatch(envType, /APP_KEY/);
  assert.doesNotMatch(src, /APP_KEY\b(?!;| is| in)|ops-signature|installationAppKey/);
  const calls = [];
  const deps = lifecycleDeps({ DB: {}, STORAGE: {}, INSTALLATION: {}, APOTHEKE: { getByName: (n) => ({ opsBackup: async (id) => { calls.push([n, id]); return { key: `installations/${n}/backups/x/manifest.json` }; } }) } });
  assert.deepEqual(await deps.container.backup('tenant-b', 'job-1'), { key: 'installations/tenant-b/backups/x/manifest.json' });
  assert.deepEqual(calls, [['tenant-b', 'job-1']]);
  const failing = lifecycleDeps({ APOTHEKE: { getByName: () => ({ opsBackup: async () => ({ status: 409 }) }) } });
  await assert.rejects(failing.container.backup('tenant-b', 'job-1'), (e) => e instanceof LifecycleRetry && e.code === 'backup_409');
});

test('C2 wiring: the export step config outlasts a 900 s backup and its 409 retries', () => {
  const ms = (v) => { const [n, u] = v.split(' '); return Number(n) * { minute: 6e4, minutes: 6e4 }[u]; };
  assert.ok(ms(EXPORT_STEP.timeout) > 900_000);
  assert.equal(EXPORT_STEP.retries.backoff, 'constant');
  assert.ok(ms(EXPORT_STEP.retries.delay) * EXPORT_STEP.retries.limit >= 2 * 900_000);
});
