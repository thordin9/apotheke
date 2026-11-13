// E4: lifecycle jobs (src/lifecycle.ts) on the real control migrations (node:sqlite behind D1's API) with a fake R2
// bucket, fake container and Installation objects, and fake C1 claim. Nothing here reaches real R2 or primary.
// Loaded by r2.test.mjs, which registers the cloudflare:workers stub, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
const { createJob, confirmJob, runJob, steps, PURGE_PAGE } = await import('../src/lifecycle.ts');
const { lifecycleDeps, LifecycleWorkflow } = await import('../src/lifecycle-workflow.ts');
const { lifecycleName, Installation } = await import('../src/installation.ts');
const { ApothekeContainer } = await import('../src/index.ts');
const { multipartMarkerKey } = await import('../src/r2.ts');

const dir = new URL('../migrations/control/', import.meta.url);
const migrations = readdirSync(dir).filter((f) => f.endsWith('.sql')).sort().map((f) => readFileSync(new URL(f, dir), 'utf8'));

/** D1's prepare/bind/first/all/run and batch (one transaction) over node:sqlite. */
function d1(raw) {
  const stmt = (sql, params = []) => ({
    sql, params, bind: (...v) => stmt(sql, v),
    first: async () => { const r = raw.prepare(sql).get(...params); return r ? { ...r } : null; },
    all: async () => ({ results: raw.prepare(sql).all(...params).map((r) => ({ ...r })) }),
    run: async () => ({ meta: { changes: Number(raw.prepare(sql).run(...params).changes) } }),
  });
  return {
    prepare: (sql) => stmt(sql),
    batch: async (list) => {
      raw.exec('BEGIN');
      try { const out = list.map((s) => raw.prepare(s.sql).run(...s.params)); raw.exec('COMMIT'); return out; } catch (e) { raw.exec('ROLLBACK'); throw e; }
    },
  };
}

/** Objects, list pages, batch deletes (at most 1000 keys each, as R2 allows) and multipart abort. */
class Bucket {
  objects = new Map(); uploads = new Map(); batches = [];
  async put(key, body, uploaded = new Date(0)) { this.objects.set(key, { body: String(body), uploaded }); }
  async get(key) { const o = this.objects.get(key); return o ? { size: o.body.length, text: async () => o.body } : null; }
  async list({ prefix, limit = 1000, cursor }) {
    const keys = [...this.objects.keys()].filter((k) => k.startsWith(prefix)).sort();
    const from = cursor ? Number(cursor) : 0;
    const page = keys.slice(from, from + limit);
    const truncated = from + limit < keys.length;
    return { objects: page.map((key) => ({ key, size: this.objects.get(key).body.length, uploaded: this.objects.get(key).uploaded })), truncated, ...(truncated ? { cursor: String(from + limit) } : {}) };
  }
  async delete(keys) {
    const list = Array.isArray(keys) ? keys : [keys];
    assert.ok(list.length <= 1000, 'R2 deletes at most 1000 keys per call');
    this.batches.push(list.length);
    for (const k of list) this.objects.delete(k);
  }
  resumeMultipartUpload(key, id) {
    return { abort: async () => {
      if (!this.uploads.has(id)) throw Object.assign(new Error('upload gone (10024)'), { code: 10024 });
      assert.equal(this.uploads.get(id), key);
      this.uploads.delete(id);
    } };
  }
}

function world() {
  const raw = new DatabaseSync(':memory:');
  raw.exec('PRAGMA foreign_keys = ON');
  for (const sql of migrations) raw.exec(sql);
  raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('t-a', 'tenant-a', 'installations/tenant-a/', 'active'),
    ('t-b', 'tenant-b', 'installations/tenant-b/', 'active')`);
  raw.exec(`INSERT INTO installation_routes (host, installation_id) VALUES ('a.example.test', 't-a'), ('b.example.test', 't-b')`);
  const bucket = new Bucket();
  const doStorage = new Map([['primary', 'db'], ['tenant-a', 'db'], ['tenant-b', 'db']]);
  const container = {
    running: new Set(['primary', 'tenant-a', 'tenant-b']), storage: new Map([['tenant-a', 's'], ['tenant-b', 's']]), booted: new Set(), backups: new Map(),
    async stop(n) { this.running.delete(n); },
    async purge(n) { this.running.delete(n); this.storage.delete(n); },
    async bootstrap(n) { this.booted.add(n); this.running.add(n); },
    async backup(n, id) {
      const key = `installations/${n}/backups/${id}.zip`;
      if (!this.backups.has(id)) { this.backups.set(id, key); await bucket.put(key, 'zip'); }
      return { key };
    },
  };
  const claims = new Set();
  const purged = [];
  const deps = {
    db: d1(raw), bucket, container,
    installation: { purge: async (n) => { doStorage.delete(n); } },
    claim: { create: async (n, job) => { claims.add(`${n}:${job}`); } },
    routeCache: { purge: async (hosts) => { purged.push(...hosts); } },
  };
  const tables = raw.prepare(`SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name`).all().map((r) => r.name);
  /** Everything a step could change, including SQLite's count of written rows (a no-op UPDATE still counts). */
  const snapshot = () => ({
    db: Object.fromEntries(tables.map((t) => [t, raw.prepare(`SELECT * FROM ${t} ORDER BY rowid`).all().map((r) => ({ ...r }))])),
    writes: raw.prepare('SELECT total_changes() AS n').get().n,
    objects: [...bucket.objects.keys()].sort(), uploads: [...bucket.uploads.entries()].sort(),
    doStorage: [...doStorage.keys()].sort(), running: [...container.running].sort(), containerStorage: [...container.storage.keys()].sort(),
    booted: [...container.booted], backups: [...container.backups.entries()], claims: [...claims],
  });
  return { raw, bucket, container, doStorage, claims, purged, deps, snapshot };
}

/** A Workflow step runner that runs every step twice and checks the second run changed nothing and returned the same. */
function twice(w, names = []) {
  return { async do(name, fn) {
    names.push(name);
    const first = await fn();
    const after = w.snapshot();
    const again = await fn();
    assert.deepEqual(w.snapshot(), after, `step ${name} changed state when run again`);
    assert.deepEqual(again, first, `step ${name} returned something else when run again`);
    return first;
  } };
}
const once = { do: async (_name, fn) => fn() };

let n = 0;
async function confirmed(w, kind, id = 't-a', extra = {}) {
  const job = await createJob(w.deps.db, { kind, installationId: id, nonce: `nonce-${++n}-x`, createdBy: 'op@example.test', ...extra });
  return confirmJob(w.deps.db, job.id, 'op@example.test', extra.confirmHost);
}
const status = (w, id = 't-a') => w.raw.prepare('SELECT status FROM installations WHERE id = ?').get(id).status;
const jobRow = (w, id) => ({ ...w.raw.prepare('SELECT state, error_code, result_json FROM lifecycle_jobs WHERE id = ?').get(id) });
const audits = (w, id = 't-a') => w.raw.prepare('SELECT action FROM operator_audit WHERE installation_id = ? ORDER BY rowid').all(id).map((r) => r.action);
const refusedAs = (code) => (e) => e.code === code;

test('E4 jobs: the same idempotency key gives the same job; a job is created paused and never runs unconfirmed', async () => {
  const w = world();
  const req = { kind: 'suspend', installationId: 't-a', nonce: 'form-nonce-1', createdBy: 'op@example.test' };
  const a = await createJob(w.deps.db, req);
  const b = await createJob(w.deps.db, req);
  assert.equal(a.id, b.id);
  assert.equal(a.idempotency_key, 'suspend:t-a:form-nonce-1');
  assert.equal(a.state, 'paused');
  assert.equal(w.raw.prepare('SELECT count(*) AS n FROM lifecycle_jobs').get().n, 1);
  const before = w.snapshot();
  await assert.rejects(runJob(w.deps, a.id, once), refusedAs('not_confirmed'));
  for (const fn of [steps.suspendStatus, steps.suspendStop]) await assert.rejects(fn(w.deps, a.id), refusedAs('not_running'));
  assert.deepEqual(w.snapshot(), before);
  assert.equal(status(w), 'active');
  const c1 = await confirmJob(w.deps.db, a.id, 'op@example.test');
  const after = w.snapshot();
  await confirmJob(w.deps.db, a.id, 'op@example.test');
  assert.deepEqual(w.snapshot(), after);
  assert.equal(c1.state, 'queued');
  for (const bad of [{ kind: 'wipe' }, { nonce: 'short' }, { nonce: 'has space here' }, { createdBy: '' }, { installationId: 'nope' }]) {
    await assert.rejects(createJob(w.deps.db, { ...req, ...bad }));
  }
});

test('E4 delete needs a retention period: there is no default', async () => {
  const w = world();
  const req = { kind: 'delete', installationId: 't-a', nonce: 'nonce-delete-1', createdBy: 'op@example.test' };
  for (const retentionDays of [undefined, 0, -1, 1.5]) await assert.rejects(createJob(w.deps.db, { ...req, retentionDays }), refusedAs('retention_required'));
  assert.equal((await createJob(w.deps.db, { ...req, retentionDays: 30 })).params_json, '{"retention_days":30}');
});

test('E4 provision: every step twice changes nothing more; the route is cached fresh and the installation is active', async () => {
  const w = world();
  w.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('t-new', 'tenant-new', 'installations/tenant-new/', 'provisioning')`);
  w.raw.exec(`INSERT INTO installation_routes (host, installation_id) VALUES ('new.example.test', 't-new')`);
  const job = await confirmed(w, 'provision', 't-new');
  const names = [];
  await runJob(w.deps, job.id, twice(w, names));
  assert.deepEqual(names, ['start', 'provision.check', 'provision.bootstrap', 'provision.claim', 'provision.activate', 'done']);
  assert.equal(status(w, 't-new'), 'active');
  assert.deepEqual([...w.container.booted], ['tenant-new']);
  assert.deepEqual([...w.claims], [`tenant-new:${job.id}`]);
  assert.deepEqual([...new Set(w.purged)], ['new.example.test']);
  assert.deepEqual(audits(w, 't-new'), ['lifecycle.provision.confirm', 'lifecycle.provision.status.active']);
  assert.equal(jobRow(w, job.id).state, 'done');
  // The whole job again changes nothing.
  const after = w.snapshot();
  await runJob(w.deps, job.id, once).catch(() => {});
  assert.deepEqual(w.snapshot(), after);
});

test('E4 provision refuses a non-empty R2 prefix and starts nothing', async () => {
  const w = world();
  w.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('t-new', 'tenant-new', 'installations/tenant-new/', 'provisioning')`);
  w.raw.exec(`INSERT INTO installation_routes (host, installation_id) VALUES ('new.example.test', 't-new')`);
  await w.bucket.put('installations/tenant-new/left-over.jpg', 'x');
  const job = await confirmed(w, 'provision', 't-new');
  await assert.rejects(runJob(w.deps, job.id, twice(w)), refusedAs('prefix_not_empty'));
  assert.equal(status(w, 't-new'), 'provisioning');
  assert.equal(w.container.booted.size, 0);
  assert.equal(w.claims.size, 0);
  assert.deepEqual({ ...jobRow(w, job.id) }, { state: 'failed', error_code: 'prefix_not_empty', result_json: null });
});

test('E4 suspend, resume, export and soft delete: every step twice changes nothing more, no data is removed', async () => {
  const w = world();
  await w.bucket.put('installations/tenant-a/photo.jpg', 'x');
  const files = w.snapshot().objects;

  const suspend = await confirmed(w, 'suspend');
  await runJob(w.deps, suspend.id, twice(w));
  assert.equal(status(w), 'suspended');
  assert.ok(!w.container.running.has('tenant-a') && w.container.running.has('tenant-b') && w.container.running.has('primary'));
  assert.deepEqual([...new Set(w.purged)], ['a.example.test']);
  w.purged.length = 0;
  assert.deepEqual(w.snapshot().objects, files);

  const resume = await confirmed(w, 'resume');
  await runJob(w.deps, resume.id, twice(w));
  assert.equal(status(w), 'active');
  assert.deepEqual([...new Set(w.purged)], ['a.example.test']);

  const exp = await confirmed(w, 'export');
  await runJob(w.deps, exp.id, twice(w));
  assert.deepEqual(w.container.backups.size, 1);
  assert.deepEqual(JSON.parse(jobRow(w, exp.id).result_json), { key: `installations/tenant-a/backups/${exp.id}.zip` });

  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 30 });
  w.container.running.add('tenant-a');
  await runJob(w.deps, del.id, twice(w));
  assert.equal(status(w), 'deleting');
  assert.ok(!w.container.running.has('tenant-a'));
  assert.deepEqual(JSON.parse(jobRow(w, del.id).result_json), { key: `installations/tenant-a/backups/${del.id}.zip`, retention_days: 30 });
  assert.ok(w.snapshot().objects.includes('installations/tenant-a/photo.jpg'), 'a soft delete removes nothing');
  assert.deepEqual(w.snapshot().doStorage, ['primary', 'tenant-a', 'tenant-b']);
  assert.equal(status(w, 't-b'), 'active');
  assert.deepEqual(audits(w), ['lifecycle.suspend.confirm', 'lifecycle.suspend.status.suspended', 'lifecycle.resume.confirm',
    'lifecycle.resume.status.active', 'lifecycle.export.confirm', 'lifecycle.export.export', 'lifecycle.delete.confirm',
    'lifecycle.delete.status.deleting', 'lifecycle.delete.export']);
  for (const j of [suspend, resume, exp, del]) assert.equal(jobRow(w, j.id).state, 'done');
});

test('E4 a status conflict fails the job without touching anything', async () => {
  const w = world();
  // Resuming an active installation is already done: nothing to change.
  const resume = await confirmed(w, 'resume');
  await assert.rejects(steps.resumeStatus(w.deps, resume.id), refusedAs('not_running'));
  await runJob(w.deps, resume.id, twice(w));
  assert.equal(status(w), 'active');
  // Suspending one that is being deleted is a conflict.
  w.raw.exec(`UPDATE installations SET status = 'deleting' WHERE id = 't-a'`);
  const suspend = await confirmed(w, 'suspend');
  const before = w.snapshot();
  await assert.rejects(runJob(w.deps, suspend.id, once), refusedAs('status_conflict'));
  assert.equal(status(w), 'deleting');
  const after = w.snapshot();
  assert.deepEqual({ ...after, db: { ...after.db, lifecycle_jobs: [] }, writes: 0 }, { ...before, db: { ...before.db, lifecycle_jobs: [] }, writes: 0 });
  assert.equal(jobRow(w, suspend.id).error_code, 'status_conflict');
  w.raw.exec(`UPDATE installations SET status = 'active' WHERE id = 't-a'`);
  // A purge is only for a soft-deleted installation.
  const purge = await confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' });
  await assert.rejects(runJob(w.deps, purge.id, once), refusedAs('status_conflict'));
  assert.equal(status(w), 'active');
});

test('E4 purge: 0 objects, markers and unfinished uploads under the prefix, empty objects, tombstone; others untouched', async () => {
  const w = world();
  const prefix = 'installations/tenant-a/';
  for (let i = 0; i < 2 * PURGE_PAGE + 345; i++) await w.bucket.put(`${prefix}media/${String(i).padStart(5, '0')}.jpg`, 'x');
  for (let i = 0; i < 130; i++) {
    const id = `upload-${i}`;
    w.bucket.uploads.set(id, `${prefix}import-${i}.zip`);
    // Uploads that began just now: a purge aborts them too, not only the expired ones.
    await w.bucket.put(await multipartMarkerKey(prefix, id), JSON.stringify({ id, key: `${prefix}import-${i}.zip` }), new Date());
  }
  await w.bucket.put(`${prefix}.mpu/not-a-marker`, 'garbage');
  w.bucket.uploads.set('tenant-b-upload', 'installations/tenant-b/x.zip');
  for (const p of ['installations/primary/', 'installations/tenant-b/', 'installations/tenant-ab/']) await w.bucket.put(`${p}keep.jpg`, 'x');
  const outside = w.snapshot().objects.filter((k) => !k.startsWith(prefix));

  await assert.rejects(confirmed(w, 'purge', 't-a'), refusedAs('confirm_host'));
  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 30 });
  await runJob(w.deps, del.id, once);
  await assert.rejects(confirmed(w, 'purge', 't-a', { confirmHost: 'b.example.test' }), refusedAs('confirm_host'));
  const purge = await confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' });
  const names = [];
  await runJob(w.deps, purge.id, twice(w, names));

  assert.deepEqual(w.snapshot().objects.filter((k) => k.startsWith(prefix)), []);
  assert.deepEqual(w.snapshot().objects, outside);
  assert.deepEqual([...w.bucket.uploads.keys()], ['tenant-b-upload']);
  assert.ok(w.bucket.batches.every((b) => b <= 1000) && w.bucket.batches.includes(1000));
  assert.deepEqual(w.snapshot().doStorage, ['primary', 'tenant-b']);
  assert.deepEqual(w.snapshot().containerStorage, ['tenant-b']);
  assert.equal(status(w), 'deleted');
  assert.equal(status(w, 't-b'), 'active');
  assert.equal(w.raw.prepare(`SELECT do_name FROM installations WHERE id = 't-a'`).get().do_name, 'tenant-a');
  assert.deepEqual(names, ['start', 'purge.uploads.0', 'purge.uploads.1', 'purge.uploads.2', 'purge.objects.0', 'purge.objects.1',
    'purge.objects.2', 'purge.objects.3', 'purge.storage', 'purge.status', 'done']);
  assert.equal(jobRow(w, purge.id).state, 'done');
  assert.deepEqual(audits(w).slice(-2), ['lifecycle.purge.confirm', 'lifecycle.purge.status.deleted']);
  // The name stays taken: a new installation can never reuse it.
  assert.throws(() => w.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('t-x', 'tenant-a', 'installations/tenant-a/', 'provisioning')`), /already exists/);
});

test('E4 purge: a page that fails part way is retried and carries on; nothing outside the prefix is listed', async () => {
  const w = world();
  const prefix = 'installations/tenant-a/';
  for (let i = 0; i < 1500; i++) await w.bucket.put(`${prefix}f${i}`, 'x');
  const id = 'upload-stuck';
  w.bucket.uploads.set(id, `${prefix}big.bin`);
  await w.bucket.put(await multipartMarkerKey(prefix, id), JSON.stringify({ id, key: `${prefix}big.bin` }));
  w.raw.exec(`UPDATE installations SET status = 'deleting' WHERE id = 't-a'`);
  const purge = await confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' });
  const list = w.bucket.list.bind(w.bucket);
  w.bucket.list = async (o) => { assert.equal(o.prefix.startsWith(prefix), true); return list(o); };
  const abort = w.bucket.resumeMultipartUpload.bind(w.bucket);
  let fail = 1;
  w.bucket.resumeMultipartUpload = (k, i) => ({ abort: async () => { if (fail-- > 0) throw new Error('R2 unavailable'); return abort(k, i).abort(); } });
  const del = w.bucket.delete.bind(w.bucket);
  let failDelete = 1;
  w.bucket.delete = async (keys) => { if (failDelete-- > 0) throw new Error('R2 unavailable'); return del(keys); };
  /** Workflows retries a failed step: here up to 3 attempts. */
  const retrying = { async do(_name, fn) { for (let a = 1; ; a++) { try { return await fn(); } catch (e) { if (a >= 3) throw e; } } } };
  await runJob(w.deps, purge.id, retrying);
  assert.deepEqual(w.snapshot().objects.filter((k) => k.startsWith(prefix)), []);
  assert.equal(w.bucket.uploads.size, 0);
  assert.equal(status(w), 'deleted');
});

test('E4 primary is refused by every step, whatever its status, and nothing changes', async () => {
  const w = world();
  await w.bucket.put('installations/primary/photo.jpg', 'x');
  for (const kind of ['provision', 'suspend', 'resume', 'export', 'delete', 'purge']) {
    await assert.rejects(createJob(w.deps.db, { kind, installationId: 'primary', nonce: 'nonce-primary', createdBy: 'op@example.test', retentionDays: 1 }), refusedAs('refused'));
  }
  // Rows written past createJob (a bug or a hand edit) are still refused, as running confirmed jobs, at every status.
  for (const kind of ['provision', 'suspend', 'resume', 'export', 'delete', 'purge']) {
    w.raw.exec(`INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, state, created_by, confirmed_by)
      VALUES ('p-${kind}', 'primary', '${kind}', '${kind}:primary:forced-nonce', 'running', 'op@example.test', 'op@example.test')`);
  }
  for (const s of ['provisioning', 'active', 'suspended', 'deleting', 'deleted']) {
    w.raw.exec(`UPDATE installations SET status = '${s}' WHERE id = 'primary'`);
    const before = w.snapshot();
    for (const kind of ['provision', 'suspend', 'resume', 'export', 'delete', 'purge']) {
      await assert.rejects(runJob(w.deps, `p-${kind}`, once), refusedAs('refused'), `${kind} at ${s}`);
      await assert.rejects(confirmJob(w.deps.db, `p-${kind}`, 'op@example.test', 'api.services.getapotheke.com'), refusedAs('refused'));
    }
    for (const [name, fn] of Object.entries(steps)) await assert.rejects(fn(w.deps, 'p-purge', 0), refusedAs('refused'), `${name} at ${s}`);
    // Only the forced jobs themselves change: marked failed with code refused.
    const after = w.snapshot();
    const noJobs = (x) => ({ ...x, db: { ...x.db, lifecycle_jobs: [] }, writes: 0 });
    assert.deepEqual(noJobs(after), noJobs(before), `primary at ${s}`);
    assert.deepEqual(after.db.lifecycle_jobs.map((j) => [j.state, j.error_code, j.step]), Array(6).fill(['failed', 'refused', null]));
  }
  assert.ok(w.snapshot().objects.includes('installations/primary/photo.jpg'));
  assert.ok(w.snapshot().doStorage.includes('primary') && w.snapshot().running.includes('primary'));
});

test('E4 a row that names the primary objects under another id is refused too', async () => {
  // Without the control triggers, so a row can claim primary's DO name and prefix under another id.
  const raw = new DatabaseSync(':memory:');
  raw.exec(`CREATE TABLE installations (id TEXT PRIMARY KEY, do_name TEXT, r2_prefix TEXT, status TEXT);
    CREATE TABLE installation_routes (host TEXT PRIMARY KEY, installation_id TEXT);
    CREATE TABLE operator_audit (id TEXT PRIMARY KEY, at TEXT, operator TEXT, action TEXT, installation_id TEXT, before_json TEXT, after_json TEXT, request_id TEXT);`);
  raw.exec(migrations[2]);
  raw.exec(`INSERT INTO installations VALUES ('other', 'primary', 'installations/primary/', 'deleting'), ('bad', 'Bad/..', 'installations/Bad/../', 'deleting'),
    ('primary', 'not-primary', 'installations/not-primary/', 'deleting')`);
  raw.exec(`INSERT INTO installation_routes VALUES ('x.example.test', 'other')`);
  const w = world();
  const deps = { ...w.deps, db: d1(raw) };
  for (const id of ['other', 'bad', 'primary']) {
    await assert.rejects(createJob(deps.db, { kind: 'purge', installationId: id, nonce: 'nonce-other', createdBy: 'op@example.test' }), refusedAs('refused'));
    raw.exec(`INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, state, created_by, confirmed_by)
      VALUES ('j-${id}', '${id}', 'purge', 'purge:${id}:forced-nonce', 'running', 'op@example.test', 'op@example.test')`);
    await assert.rejects(runJob(deps, `j-${id}`, once), refusedAs('refused'));
    for (const fn of Object.values(steps)) await assert.rejects(fn(deps, `j-${id}`, 0), refusedAs('refused'));
  }
  assert.deepEqual(raw.prepare(`SELECT status FROM installations`).all().map((r) => r.status), ['deleting', 'deleting', 'deleting']);
  assert.equal(w.bucket.batches.length, 0);
});

test('E4 the container and Installation purge RPCs refuse primary and unnamed objects; deleteAll empties the rest', async () => {
  for (const bad of ['primary', undefined, '', 'Bad', 'a/b']) assert.throws(() => lifecycleName(bad), /refused/);
  assert.equal(lifecycleName('tenant-a'), 'tenant-a');
  const ctx = (name, running = true) => {
    const calls = [];
    return { calls, id: name === undefined ? {} : { name }, storage: { deleteAll: async () => { calls.push('deleteAll'); } },
      container: { running, destroy: async () => { calls.push('destroy'); } } };
  };
  for (const name of ['primary', undefined]) {
    const c = ctx(name);
    const obj = Object.assign(Object.create(ApothekeContainer.prototype), { ctx: c, env: {} });
    await assert.rejects(obj.stopContainer(), /refused/);
    await assert.rejects(obj.purge(), /refused/);
    assert.deepEqual(c.calls, []);
  }
  const c = ctx('tenant-a');
  const obj = Object.assign(Object.create(ApothekeContainer.prototype), { ctx: c, env: {} });
  await obj.purge();
  assert.deepEqual(c.calls, ['destroy', 'deleteAll']);
  const stopped = ctx('tenant-a', false);
  await Object.assign(Object.create(ApothekeContainer.prototype), { ctx: stopped, env: {} }).stopContainer();
  assert.deepEqual(stopped.calls, []);

  for (const [own, arg] of [['primary', 'primary'], [undefined, 'primary'], ['tenant-a', 'tenant-b'], ['tenant-a', '']]) {
    const i = ctx(own);
    await assert.rejects(new Installation(i, {}).purge(arg), /refused/);
    assert.deepEqual(i.calls, []);
  }
  const i = ctx('tenant-a');
  await new Installation(i, {}).purge('tenant-a');
  assert.deepEqual(i.calls, ['deleteAll']);
});

test('E4 Workflow wiring: C1 and backup are not wired yet and fail closed; the instance runs the job by id', async () => {
  const calls = [];
  const env = {
    DB: {}, STORAGE: {},
    INSTALLATION: { jurisdiction: () => ({ idFromName: (n) => ({ name: n }), get: (id) => ({ purge: async (n) => calls.push(['installation', id.name, n]) }) }) },
    APOTHEKE: { getByName: (n) => ({ stopContainer: async () => calls.push(['stop', n]), purge: async () => calls.push(['purge', n]) }) },
  };
  const deps = lifecycleDeps(env);
  await deps.installation.purge('tenant-a');
  await deps.container.stop('tenant-a');
  await deps.container.purge('tenant-a');
  assert.deepEqual(calls, [['installation', 'tenant-a', 'tenant-a'], ['stop', 'tenant-a'], ['purge', 'tenant-a']]);
  await assert.rejects(deps.container.bootstrap('tenant-a'), refusedAs('pending_c1'));
  await assert.rejects(deps.claim.create('tenant-a', 'j'), refusedAs('pending_c1'));
  await assert.rejects(deps.container.backup('tenant-a', 'j'), refusedAs('pending_backup_endpoint'));

  const w = world();
  const job = await confirmed(w, 'suspend');
  const wf = new LifecycleWorkflow({}, { DB: w.deps.db, STORAGE: w.bucket, INSTALLATION: env.INSTALLATION,
    APOTHEKE: { getByName: (n) => ({ stopContainer: async () => w.container.stop(n) }) } });
  await wf.run({ payload: { jobId: job.id } }, once);
  assert.equal(status(w), 'suspended');
  assert.ok(!w.container.running.has('tenant-a'));
});

test('E4 ships paused: no cron, workflow or new binding on apotheke-api, and the Workflow is not exported by its Worker', () => {
  const src = (f) => readFileSync(new URL(f, import.meta.url), 'utf8');
  for (const f of ['../wrangler.jsonc', '../storage.jsonc']) assert.doesNotMatch(src(f), /"(workflows|triggers|crons)"/, f);
  assert.doesNotMatch(src('../src/index.ts'), /lifecycle-workflow|LifecycleWorkflow/);
});
