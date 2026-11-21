// E4: lifecycle jobs (src/lifecycle.ts) on the real control migrations (node:sqlite behind D1's API) with a fake R2
// bucket, fake container and Installation objects, and fake C1 claim. Nothing here reaches real R2 or primary.
// Loaded by r2.test.mjs, which registers the cloudflare:workers stub, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
const { createJob, confirmJob, runJob, steps, PURGE_PAGE, LifecycleError, LifecycleRetry, EXPORT_STEP, lifecycleCode } = await import('../src/lifecycle.ts');
const { NonRetryableError } = await import('cloudflare:workflows');
const { lifecycleDeps, LifecycleWorkflow, deleteAutofillBudget } = await import('../src/lifecycle-workflow.ts');
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
      this.running.add(n); // the backup may start the container (L-2167)
      const key = `installations/${n}/backups/${id}.zip`;
      if (!this.backups.has(id)) { this.backups.set(id, key); await bucket.put(key, 'zip'); }
      return { key };
    },
  };
  const claims = new Set();
  const purged = [];
  /** The autofill service's budgets by installation, and every delete call with what was gone by then. */
  const autofill = {
    budgets: new Map([['primary', 9], ['tenant-a', 3], ['tenant-b', 2]]), calls: [],
    async delete(n) {
      this.calls.push({ n, status: raw.prepare('SELECT status FROM installations WHERE do_name = ?').get(n)?.status, containerGone: !container.storage.has(n) && !container.running.has(n), dbGone: !doStorage.has(n) });
      const deleted = this.budgets.get(n) ?? 0; this.budgets.delete(n); return { deleted };
    },
  };
  const deps = {
    db: d1(raw), bucket, container,
    installation: { purge: async (n) => { doStorage.delete(n); } },
    claim: { create: async (n, job) => { claims.add(`${n}:${job}`); } },
    routeCache: { purge: async (hosts) => { purged.push(...hosts); } },
    autofill,
  };
  const tables = raw.prepare(`SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name`).all().map((r) => r.name);
  /** Everything a step could change, including SQLite's count of written rows (a no-op UPDATE still counts). */
  const snapshot = () => ({
    db: Object.fromEntries(tables.map((t) => [t, raw.prepare(`SELECT * FROM ${t} ORDER BY rowid`).all().map((r) => ({ ...r }))])),
    writes: raw.prepare('SELECT total_changes() AS n').get().n,
    objects: [...bucket.objects.keys()].sort(), uploads: [...bucket.uploads.entries()].sort(),
    doStorage: [...doStorage.keys()].sort(), running: [...container.running].sort(), containerStorage: [...container.storage.keys()].sort(),
    booted: [...container.booted], backups: [...container.backups.entries()], claims: [...claims],
    budgets: [...autofill.budgets.entries()].sort(),
  });
  return { raw, bucket, container, doStorage, claims, purged, autofill, deps, snapshot };
}

/** A Workflow step runner that runs every step twice and checks the second run changed nothing and returned the same. */
function twice(w, names = []) {
  return { async do(name, config, f) {
    const fn = f ?? config;
    names.push(name);
    const first = await fn();
    const after = w.snapshot();
    const again = await fn();
    assert.deepEqual(w.snapshot(), after, `step ${name} changed state when run again`);
    assert.deepEqual(again, first, `step ${name} returned something else when run again`);
    return first;
  } };
}
const once = { do: async (_name, config, fn) => (fn ?? config)() };

/** Like once, but rethrows a plain Error the way Workflows does after step.do (wrangler 4.160.0). */
function workflowOnce(seen) {
  return { async do(name, config, fn) {
    seen?.push(name);
    try {
      return await (fn ?? config)();
    } catch (e) {
      if (e instanceof NonRetryableError) throw new Error(`NonRetryableError: ${e.message}`);
      throw new Error(e instanceof Error ? e.message : String(e));
    }
  } };
}

let n = 0;
async function confirmed(w, kind, id = 't-a', extra = {}) {
  const job = await createJob(w.deps.db, { kind, installationId: id, nonce: `nonce-${++n}-x`, createdBy: 'op@example.test', ...extra });
  return confirmJob(w.deps.db, job.id, 'op@example.test', extra.confirmHost);
}
const status = (w, id = 't-a') => w.raw.prepare('SELECT status FROM installations WHERE id = ?').get(id).status;
const jobRow = (w, id) => ({ ...w.raw.prepare('SELECT state, error_code, result_json FROM lifecycle_jobs WHERE id = ?').get(id) });
const audits = (w, id = 't-a') => w.raw.prepare('SELECT action FROM operator_audit WHERE installation_id = ? ORDER BY rowid').all(id).map((r) => r.action);
const refusedAs = (code) => (e) => e.code === code;
/** Moves a finished delete job's end back by `days`, as if that much time had passed. */
const age = (w, jobId, days) => w.raw.exec(`UPDATE lifecycle_jobs SET updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '-${days} days') WHERE id = '${jobId}'`);
/** A running purge job written straight into the table, past createJob and confirmJob. */
const forcedPurge = (w, id = 't-a', jobId = 'forced-purge') => {
  w.raw.exec(`INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, state, created_by, confirmed_by)
    VALUES ('${jobId}', '${id}', 'purge', 'purge:${id}:${jobId}', 'running', 'op@example.test', 'op@example.test')`);
  return jobId;
};
const purgeSteps = (w, jobId) => [steps.purgeUploads(w.deps, jobId, 0), steps.purgeObjects(w.deps, jobId, 0), steps.purgeStorage(w.deps, jobId), steps.purgeAutofill(w.deps, jobId), steps.purgeStatus(w.deps, jobId)];
const withoutJobs = (x) => ({ ...x, db: { ...x.db, lifecycle_jobs: [] }, writes: 0 });

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
  // A purge is only for a soft-deleted installation whose export retention has run out.
  await assert.rejects(confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' }), refusedAs('retention'));
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

  await assert.rejects(confirmed(w, 'purge', 't-a'), refusedAs('retention'));
  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 30 });
  await runJob(w.deps, del.id, once);
  age(w, del.id, 30);
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
    'purge.objects.2', 'purge.objects.3', 'purge.storage', 'purge.autofill', 'purge.status', 'done']);
  // The budget is deleted once (the second run of the step reads the recorded count), after the container is gone and
  // while the installation is still deleting; the other installation's budget stays.
  assert.deepEqual(w.autofill.calls, [{ n: 'tenant-a', status: 'deleting', containerGone: true, dbGone: true }]);
  assert.deepEqual(w.snapshot().budgets, [['primary', 9], ['tenant-b', 2]]);
  assert.equal(JSON.parse(jobRow(w, purge.id).result_json)['purge.autofill'], 3);
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
  const delJob = await confirmed(w, 'delete', 't-a', { retentionDays: 1 });
  await runJob(w.deps, delJob.id, once);
  age(w, delJob.id, 1);
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
  const retrying = { async do(_name, config, f) { const fn = f ?? config; for (let a = 1; ; a++) { try { return await fn(); } catch (e) { if (a >= 3) throw e; } } } };
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
  assert.deepEqual(w.autofill.calls, []);
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
  assert.deepEqual(w.autofill.calls, []);
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

test('E4 Workflow wiring: C1 is not wired yet and fails closed; the instance runs the job by id', async () => {
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

test('E4 purge after a failed delete is refused: no export, nothing removed (M-2157 P1)', async () => {
  const w = world();
  await w.bucket.put('installations/tenant-a/photo.jpg', 'x');
  w.deps.container.backup = async () => { throw new LifecycleError('backup_410'); };
  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 1 });
  await assert.rejects(runJob(w.deps, del.id, once), refusedAs('backup_410'));
  assert.equal(status(w), 'deleting');
  assert.equal(jobRow(w, del.id).state, 'failed');
  age(w, del.id, 400);
  const before = w.snapshot();
  await assert.rejects(confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' }), refusedAs('retention'));
  const forced = forcedPurge(w);
  const paused = forcedPurge(w, 't-a', 'forced-paused');
  w.raw.exec(`UPDATE lifecycle_jobs SET state = 'paused', confirmed_by = NULL WHERE id = '${paused}'`);
  const start = w.snapshot();
  await assert.rejects(confirmJob(w.deps.db, paused, 'op@example.test', 'a.example.test'), refusedAs('retention'));
  for (const p of purgeSteps(w, forced)) await assert.rejects(p, refusedAs('retention'));
  assert.deepEqual(w.snapshot(), { ...start, writes: start.writes }, 'refused steps change nothing');
  await assert.rejects(runJob(w.deps, forced, twice(w)), refusedAs('retention'));
  assert.deepEqual(withoutJobs(w.snapshot()), withoutJobs(before));
  assert.ok(w.snapshot().objects.includes('installations/tenant-a/photo.jpg'));
  assert.deepEqual(w.snapshot().doStorage, ['primary', 'tenant-a', 'tenant-b']);
  assert.equal(status(w), 'deleting');
  // A failed delete stays refused even if its export key was recorded before the failure.
  w.raw.exec(`UPDATE lifecycle_jobs SET result_json = '{"key":"installations/tenant-a/backups/x.zip"}' WHERE id = '${del.id}'`);
  age(w, del.id, 400);
  await assert.rejects(confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' }), refusedAs('retention'));
});

test('E4 purge waits for the delete job\'s retention: the export stays until then (M-2157 P2)', async () => {
  const w = world();
  await w.bucket.put('installations/tenant-a/photo.jpg', 'x');
  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 30 });
  await runJob(w.deps, del.id, once);
  const exportKey = JSON.parse(jobRow(w, del.id).result_json).key;
  assert.ok(w.snapshot().objects.includes(exportKey));
  const forced = forcedPurge(w);
  for (const days of [0, 29]) {
    age(w, del.id, days);
    const before = w.snapshot();
    await assert.rejects(confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' }), refusedAs('retention'), `after ${days} days`);
    for (const p of purgeSteps(w, forced)) await assert.rejects(p, refusedAs('retention'));
    assert.deepEqual(w.snapshot(), before, `after ${days} days nothing changes`);
  }
  assert.ok(w.snapshot().objects.includes(exportKey), 'the export is intact');
  age(w, del.id, 30);
  const purge = await confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' });
  await runJob(w.deps, purge.id, twice(w));
  assert.equal(status(w), 'deleted');
  assert.deepEqual(w.snapshot().objects.filter((k) => k.startsWith('installations/tenant-a/')), []);
});

test('E4 purge pages never run on an installation that is not deleting or deleted (L-2160)', async () => {
  const w = world();
  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 1 });
  await runJob(w.deps, del.id, once);
  age(w, del.id, 2);
  // Retention is over, but the installation is active again (set by hand): purge must still touch nothing.
  w.raw.exec(`UPDATE installations SET status = 'active' WHERE id = 't-a'`);
  await w.bucket.put('installations/tenant-a/photo.jpg', 'x');
  w.bucket.uploads.set('up-1', 'installations/tenant-a/big.bin');
  await w.bucket.put(await multipartMarkerKey('installations/tenant-a/', 'up-1'), JSON.stringify({ id: 'up-1', key: 'installations/tenant-a/big.bin' }));
  const forced = forcedPurge(w);
  const before = w.snapshot();
  for (const p of purgeSteps(w, forced)) await assert.rejects(p, refusedAs('status_conflict'));
  assert.deepEqual(w.snapshot(), before);
  await assert.rejects(runJob(w.deps, forced, once), refusedAs('status_conflict'));
  const after = w.snapshot();
  assert.deepEqual(withoutJobs(after), withoutJobs(before));
  assert.equal(after.objects.filter((k) => k.startsWith('installations/tenant-a/')).length, 3);
  assert.deepEqual(after.uploads, [['up-1', 'installations/tenant-a/big.bin']]);
  assert.deepEqual(after.doStorage, ['primary', 'tenant-a', 'tenant-b']);
  assert.deepEqual(after.containerStorage, ['tenant-a', 'tenant-b']);
  assert.equal(status(w), 'active');
});

test('E4 refusals are NonRetryableError, so the Workflow does not retry them; an unfinished R2 call is retried (L-2159)', async () => {
  const w = world();
  const resume = await confirmed(w, 'resume');
  w.raw.exec(`UPDATE installations SET status = 'deleting' WHERE id = 't-a'`);
  const errors = [];
  for (const p of [createJob(w.deps.db, { kind: 'suspend', installationId: 'primary', nonce: 'nonce-primary', createdBy: 'op' }),
    createJob(w.deps.db, { kind: 'purge', installationId: 't-a', nonce: 'nonce-retention', createdBy: 'op' }),
    steps.resumeStatus(w.deps, resume.id), runJob(w.deps, resume.id, once)]) {
    await p.then(() => assert.fail('expected a refusal'), (e) => errors.push(e));
  }
  assert.deepEqual(errors.map((e) => [e instanceof NonRetryableError, e.code]),
    [[true, 'refused'], [true, 'retention'], [true, 'not_running'], [true, 'status_conflict']]);
  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 1 });
  await runJob(w.deps, del.id, once);
  age(w, del.id, 1);
  const id = 'upload-1';
  w.bucket.uploads.set(id, 'installations/tenant-a/a.bin');
  await w.bucket.put(await multipartMarkerKey('installations/tenant-a/', id), JSON.stringify({ id, key: 'installations/tenant-a/a.bin' }));
  w.bucket.resumeMultipartUpload = () => ({ abort: async () => { throw new Error('R2 unavailable'); } });
  const purge = await confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' });
  const e = await runJob(w.deps, purge.id, once).then(() => null, (x) => x);
  assert.ok(e instanceof LifecycleRetry && !(e instanceof NonRetryableError));
  assert.equal(jobRow(w, purge.id).error_code, 'r2_interrupted');
});

test('E4 purge confirmation needs an exact host; an export outside the prefix is refused (N-2162)', async () => {
  const w = world();
  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 1 });
  await runJob(w.deps, del.id, once);
  age(w, del.id, 1);
  const job = await createJob(w.deps.db, { kind: 'purge', installationId: 't-a', nonce: 'nonce-host-1', createdBy: 'op' });
  for (const host of ['A.EXAMPLE.TEST', 'a.example', 'example.test', 'a.example.test ', 'a.example.test.', '', 'b.example.test']) {
    await assert.rejects(confirmJob(w.deps.db, job.id, 'op', host), refusedAs('confirm_host'), host);
  }
  assert.equal(jobRow(w, job.id).state, 'paused');

  for (const key of ['installations/tenant-b/backups/x.zip', 'installations/tenant-a/', 'installations/tenant-ab/x.zip', 'backups/x.zip', 42]) {
    const v = world();
    v.deps.container.backup = async () => ({ key });
    const exp = await confirmed(v, 'export');
    await assert.rejects(runJob(v.deps, exp.id, once), refusedAs('bad_export'), String(key));
    assert.equal(jobRow(v, exp.id).result_json, null);
  }
});

test('L-2167: export and delete pass the export step config only to the backup step, then stop a non-active container again', async () => {
  const w = world();
  const seen = [];
  const rec = { do: async (name, config, fn) => { seen.push([name, fn ? config : null]); return (fn ?? config)(); } };
  const sus = await confirmed(w, 'suspend');
  await runJob(w.deps, sus.id, once);
  const exp = await confirmed(w, 'export');
  await runJob(w.deps, exp.id, rec);
  assert.ok(!w.container.running.has('tenant-a'), 'a suspended export stops the container it started');
  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 30 });
  await runJob(w.deps, del.id, rec);
  assert.deepEqual(seen, [['start', null], ['export.backup', EXPORT_STEP], ['export.stop', null], ['done', null],
    ['start', null], ['delete.status', null], ['delete.stop', null], ['delete.export', EXPORT_STEP], ['delete.export.stop', null], ['done', null]]);
  assert.ok(!w.container.running.has('tenant-a'), 'the delete export stops the container again');
  assert.equal(status(w), 'deleting');
  // An active installation's export leaves its container running.
  const exB = await confirmed(w, 'export', 't-b');
  await runJob(w.deps, exB.id, once);
  assert.ok(w.container.running.has('tenant-b'));
});

test('L-2230: a failed backup still stops a non-active container for export and delete', async () => {
  const fail = (w) => async (n) => { w.container.running.add(n); throw new LifecycleError('backup_404'); };
  const w = world();
  const sus = await confirmed(w, 'suspend');
  await runJob(w.deps, sus.id, once);
  w.deps.container.backup = fail(w);
  const exp = await confirmed(w, 'export');
  const seen = [];
  const rec = { do: async (name, config, fn) => { seen.push(name); return (fn ?? config)(); } };
  await assert.rejects(runJob(w.deps, exp.id, rec), refusedAs('backup_404'));
  assert.deepEqual(seen, ['start', 'export.backup', 'export.stop', 'failed']);
  assert.ok(!w.container.running.has('tenant-a'), 'suspended export stops after a failed backup');
  assert.equal(jobRow(w, exp.id).state, 'failed');
  assert.equal(jobRow(w, exp.id).error_code, 'backup_404');

  const d = world();
  d.deps.container.backup = fail(d);
  const del = await confirmed(d, 'delete', 't-a', { retentionDays: 30 });
  const seenDel = [];
  const recDel = { do: async (name, config, fn) => { seenDel.push(name); return (fn ?? config)(); } };
  await assert.rejects(runJob(d.deps, del.id, recDel), refusedAs('backup_404'));
  assert.deepEqual(seenDel, ['start', 'delete.status', 'delete.stop', 'delete.export', 'delete.export.stop', 'failed']);
  assert.ok(!d.container.running.has('tenant-a'), 'delete export stops after a failed backup');
  assert.equal(jobRow(d, del.id).state, 'failed');
  // After step.do throws, retries are finished: a 409/5xx/unreachable also stops (N-2261).
  const r = world();
  const sus2 = await confirmed(r, 'suspend');
  await runJob(r.deps, sus2.id, once);
  r.deps.container.backup = async (n) => { r.container.running.add(n); throw new LifecycleRetry('backup_409'); };
  const exp2 = await confirmed(r, 'export');
  await assert.rejects(runJob(r.deps, exp2.id, once), (e) => e instanceof LifecycleRetry && e.code === 'backup_409');
  assert.ok(!r.container.running.has('tenant-a'), 'after the last retry the container is stopped');
  assert.equal(jobRow(r, exp2.id).error_code, 'backup_409');
});

test('L-2260: after step.do the error is a plain Error; the job still records the real code and stops', async () => {
  assert.equal(lifecycleCode(new LifecycleError('backup_404')), 'backup_404');
  assert.equal(lifecycleCode(new LifecycleRetry('backup_409')), 'backup_409');
  assert.equal(lifecycleCode(new Error('NonRetryableError: lifecycle: backup_404')), 'backup_404');
  assert.equal(lifecycleCode(new Error('lifecycle: backup_409')), 'backup_409');
  assert.equal(lifecycleCode(new Error('network connection lost')), undefined);

  const fail = (w, err) => async (n) => { w.container.running.add(n); throw err; };
  const w = world();
  const sus = await confirmed(w, 'suspend');
  await runJob(w.deps, sus.id, once);
  w.deps.container.backup = fail(w, new LifecycleError('backup_404'));
  const exp = await confirmed(w, 'export');
  const seen = [];
  await assert.rejects(runJob(w.deps, exp.id, workflowOnce(seen)), (e) => e instanceof Error && e.code === undefined && e.message === 'NonRetryableError: lifecycle: backup_404');
  assert.deepEqual(seen, ['start', 'export.backup', 'export.stop', 'failed']);
  assert.ok(!w.container.running.has('tenant-a'));
  assert.equal(jobRow(w, exp.id).state, 'failed');
  assert.equal(jobRow(w, exp.id).error_code, 'backup_404');

  const r = world();
  const sus2 = await confirmed(r, 'suspend');
  await runJob(r.deps, sus2.id, once);
  r.deps.container.backup = fail(r, new LifecycleRetry('backup_unreachable'));
  const exp2 = await confirmed(r, 'export');
  await assert.rejects(runJob(r.deps, exp2.id, workflowOnce()), (e) => e instanceof Error && e.code === undefined && e.message === 'lifecycle: backup_unreachable');
  assert.ok(!r.container.running.has('tenant-a'), 'retries exhausted: the container is stopped');
  assert.equal(jobRow(r, exp2.id).error_code, 'backup_unreachable');

  // A non-export job also records the real code once the class is gone (predates #328).
  const s = world();
  s.raw.exec(`UPDATE installations SET status = 'deleting' WHERE id = 't-a'`);
  const sus3 = await confirmed(s, 'suspend');
  await assert.rejects(runJob(s.deps, sus3.id, workflowOnce()), (e) => e.message === 'NonRetryableError: lifecycle: status_conflict');
  assert.equal(jobRow(s, sus3.id).error_code, 'status_conflict');
});

test('N-2263/M04: a stop that throws does not hide the backup error', async () => {
  const w = world();
  const sus = await confirmed(w, 'suspend');
  await runJob(w.deps, sus.id, once);
  w.deps.container.backup = async (n) => { w.container.running.add(n); throw new LifecycleError('backup_404'); };
  w.deps.container.stop = async () => { throw new Error('stop failed'); };
  const exp = await confirmed(w, 'export');
  await assert.rejects(runJob(w.deps, exp.id, workflowOnce()), (e) => e.message === 'NonRetryableError: lifecycle: backup_404');
  assert.equal(jobRow(w, exp.id).error_code, 'backup_404');
});

/** A soft-deleted installation whose retention is over, and a confirmed purge job for it. */
async function readyToPurge(w) {
  const del = await confirmed(w, 'delete', 't-a', { retentionDays: 1 });
  await runJob(w.deps, del.id, once);
  age(w, del.id, 1);
  return confirmed(w, 'purge', 't-a', { confirmHost: 'a.example.test' });
}

/** A fake HostedAutofill binding: records each request and answers with `answer(request)`. */
function autofillBinding(answer) {
  const calls = [];
  return { calls, fetch: async (input, init) => { const r = new Request(input, init); calls.push([r.method, new URL(r.url).pathname, [...r.headers.keys()]]); return answer(r); } };
}
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });

test('A2 purge.autofill: DELETE /v1/installations/<do_name>/budget on the binding; 200 gives the deleted count', async () => {
  const binding = autofillBinding(() => json(200, { ok: true, installation: 'tenant-a', deleted: 4 }));
  assert.deepEqual(await deleteAutofillBudget(binding, 'tenant-a'), { deleted: 4 });
  assert.deepEqual(binding.calls, [['DELETE', '/v1/installations/tenant-a/budget', []]]);
  // Through the Workflow's deps, with the job: the call comes after the container and database objects are purged.
  const w = world();
  const order = [];
  const env = { DB: w.deps.db, STORAGE: w.bucket,
    INSTALLATION: { jurisdiction: () => ({ idFromName: (n) => ({ name: n }), get: () => ({ purge: async () => order.push('installation') }) }) },
    APOTHEKE: { getByName: () => ({ stopContainer: async () => order.push('stop'), purge: async () => order.push('container') }) },
    AUTOFILL: autofillBinding((r) => { order.push(`autofill ${r.method} ${new URL(r.url).pathname}`); return json(200, { ok: true, installation: 'tenant-a', deleted: 0 }); }) };
  const job = await readyToPurge(w);
  const names = [];
  await runJob({ ...lifecycleDeps(env), routeCache: w.deps.routeCache }, job.id, twice(w, names));
  assert.deepEqual(order, ['installation', 'container', 'installation', 'container', 'autofill DELETE /v1/installations/tenant-a/budget']);
  assert.ok(names.indexOf('purge.autofill') === names.indexOf('purge.storage') + 1 && names.indexOf('purge.status') === names.indexOf('purge.autofill') + 1);
  assert.equal(status(w), 'deleted');
  assert.equal(jobRow(w, job.id).state, 'done');
});

test('A2 purge.autofill never calls the service for primary', async () => {
  const binding = autofillBinding(() => json(200, { ok: true, deleted: 0 }));
  await assert.rejects(deleteAutofillBudget(binding, 'primary'), (e) => e instanceof NonRetryableError && e.code === 'refused');
  assert.deepEqual(binding.calls, []);
});

test('A2 purge.autofill: 503, 5xx, a call that did not finish and no binding are retried; the purge waits, status stays deleting', async () => {
  for (const [binding, code] of [
    [autofillBinding(() => json(503, { error: { code: 'backend_unavailable' } })), 'autofill_503'],
    [autofillBinding(() => json(500, {})), 'autofill_500'],
    [autofillBinding(() => { throw new Error('network'); }), 'autofill_unreachable'],
    [undefined, 'autofill_not_configured'],
  ]) {
    const e = await deleteAutofillBudget(binding, 'tenant-a').then(() => null, (x) => x);
    assert.ok(e instanceof LifecycleRetry && !(e instanceof NonRetryableError), code);
    assert.equal(e.code, code);
  }
  // In the job: retried by the Workflow, then a success carries on; with every attempt failing the job records the code.
  const w = world();
  let fail = 2;
  w.deps.autofill = { delete: (n) => deleteAutofillBudget(autofillBinding(() => (fail-- > 0 ? json(503, {}) : json(200, { deleted: 1 }))), n) };
  const job = await readyToPurge(w);
  const retrying = { async do(_name, config, f) { const fn = f ?? config; for (let a = 1; ; a++) { try { return await fn(); } catch (e) { if (a >= 3) throw e; } } } };
  await runJob(w.deps, job.id, retrying);
  assert.equal(status(w), 'deleted');
  assert.equal(JSON.parse(jobRow(w, job.id).result_json)['purge.autofill'], 1);

  const w2 = world();
  w2.deps.autofill = { delete: (n) => deleteAutofillBudget(undefined, n) };
  const job2 = await readyToPurge(w2);
  const seen = [];
  await assert.rejects(runJob(w2.deps, job2.id, workflowOnce(seen)));
  assert.deepEqual(jobRow(w2, job2.id).state, 'failed');
  assert.equal(jobRow(w2, job2.id).error_code, 'autofill_not_configured');
  assert.equal(status(w2), 'deleting');
  assert.ok(!seen.includes('purge.status'));
});

test('A2 purge.autofill: a 4xx or a 200 without a count fails the job at once with its code', async () => {
  for (const [answer, code] of [[() => json(400, { error: { code: 'bad_request' } }), 'autofill_400'], [() => json(404, {}), 'autofill_404'],
    [() => json(200, { ok: true }), 'bad_autofill_answer'], [() => new Response('nope', { status: 200 }), 'bad_autofill_answer']]) {
    const e = await deleteAutofillBudget(autofillBinding(answer), 'tenant-a').then(() => null, (x) => x);
    assert.ok(e instanceof NonRetryableError, code);
    assert.equal(e.code, code);
  }
  const w = world();
  w.deps.autofill = { delete: (n) => deleteAutofillBudget(autofillBinding(() => json(400, {})), n) };
  const job = await readyToPurge(w);
  await assert.rejects(runJob(w.deps, job.id, workflowOnce()));
  assert.deepEqual([jobRow(w, job.id).state, jobRow(w, job.id).error_code], ['failed', 'autofill_400']);
  assert.equal(status(w), 'deleting');
});
