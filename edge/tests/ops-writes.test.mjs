// Operator console E6-3: the apotheke-ops writes (edge/ops/src/writes.ts): plan limits, a subscription's plan and
// status, lifecycle jobs (create, confirm) and limit overrides. Each needs Access and the CSRF check, refuses primary,
// validates its body (422), and writes its change and its operator_audit row in one D1 batch. Also the audit CSV at
// exactly its row cap and the Access key failure window at its edges. Access keys are generated here; hosts and
// operators are fakes. Loaded by r2.test.mjs (cloudflare:workers stub and extensionless import hook).
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
const { handle } = await import('../ops/src/index.ts');
const { clearJwksCache, JWKS_FAILURE_MS } = await import('../ops/src/access.ts');
const { CSV_MAX_ROWS } = await import('../ops/src/reads.ts');
const { LIMIT_KEYS, JOB_KINDS } = await import('../ops/src/writes.ts');

const TEAM = 'ops-team.cloudflareaccess.com';
const AUD = 'fake-aud-writes-0123456789';
const ORIGIN = 'https://console.example.test';
const API = '/api/ops/v1';
const NOW = Date.UTC(2026, 10, 20, 12, 0, 0);
const VARS = { ACCESS_TEAM_DOMAIN: TEAM, ACCESS_AUD: AUD, OPS_ALLOWED_EMAILS: 'operator@example.test', OPS_PROVISIONING: '0', LIMITS_ENABLED: '0', OPS_WRITES_ENABLED: '1' };
const OPERATOR = 'operator@example.test';

const pair = await crypto.subtle.generateKey({ name: 'RSASSA-PKCS1-v1_5', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['sign', 'verify']);
const pub = await crypto.subtle.exportKey('jwk', pair.publicKey);
const JWK = { kty: 'RSA', n: pub.n, e: pub.e, kid: 'kid-w', alg: 'RS256', use: 'sig' };
const b64url = (v) => Buffer.from(JSON.stringify(v)).toString('base64url');
async function jwt() {
  const now = Math.floor(NOW / 1000);
  const head = b64url({ alg: 'RS256', kid: 'kid-w', typ: 'JWT' });
  const body = b64url({ iss: `https://${TEAM}`, aud: [AUD], email: 'Operator@Example.test', iat: now - 10, exp: now + 3600 });
  const sig = await crypto.subtle.sign('RSASSA-PKCS1-v1_5', pair.privateKey, new TextEncoder().encode(`${head}.${body}`));
  return `${head}.${body}.${Buffer.from(sig).toString('base64url')}`;
}
const TOKEN = await jwt();

/** The control migrations on a real SQLite: plans basic (lower) and pro (higher), installations in each state. */
function controlDb() {
  const db = new DatabaseSync(':memory:');
  db.exec('PRAGMA foreign_keys = ON');
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  db.exec(`INSERT INTO plans (id, name) VALUES ('basic', 'Basic'), ('pro', 'Pro');
    INSERT INTO plans (id, name, active) VALUES ('old', 'Old', 0);
    INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES
      ('basic', 'items', 800, 1000), ('basic', 'users', NULL, 3), ('pro', 'items', 8000, 10000), ('pro', 'users', NULL, 10), ('pro', 'storage_bytes', NULL, 5000000);`);
  const add = (id, status, host, plan) => {
    db.prepare('INSERT INTO installations (id, do_name, r2_prefix, status) VALUES (?, ?, ?, ?)').run(id, `tenant-${id}`, `installations/tenant-${id}/`, status);
    if (host) db.prepare('INSERT INTO installation_routes (host, installation_id) VALUES (?, ?)').run(host, id);
    if (plan) db.prepare("INSERT INTO subscriptions (installation_id, plan_id, status) VALUES (?, ?, 'active')").run(id, plan);
  };
  add('b', 'active', 'b.example.test', 'pro');
  add('n', 'active', 'n.example.test', null);
  add('x', 'deleting', 'x.example.test', 'basic');
  add('d', 'deleted', 'd.example.test', 'basic');
  const batches = [];
  const stmt = (sql, v = []) => ({
    sql, v,
    bind: (...b) => stmt(sql, b),
    first: async () => { const r = db.prepare(sql).get(...v); return r ? { ...r } : null; },
    all: async () => ({ results: db.prepare(sql).all(...v).map((r) => ({ ...r })) }),
    run: async () => { db.prepare(sql).run(...v); return { success: true }; },
  });
  return {
    raw: db, batches, failBatch: false,
    prepare: (sql) => stmt(sql),
    async batch(list) {
      batches.push(list.map((s) => ({ sql: s.sql, v: s.v })));
      if (this.failBatch) throw new Error('D1_ERROR: fake failure');
      db.exec('BEGIN');
      try { for (const s of list) db.prepare(s.sql).run(...s.v); db.exec('COMMIT'); } catch (e) { db.exec('ROLLBACK'); throw e; }
      return list.map(() => ({ success: true }));
    },
  };
}

function workflowStub({ fail = false } = {}) {
  const instances = new Set();
  return {
    instances, calls: [],
    async create({ id, params }) {
      this.calls.push({ id, params });
      if (fail) throw new Error('workflow down');
      if (instances.has(id)) throw new Error('instance already exists');
      instances.add(id);
      return { id };
    },
    async get(id) { if (!instances.has(id)) throw new Error('not found'); return { id }; },
  };
}

function setup({ vars = VARS, db = controlDb(), lifecycle, fetch } = {}) {
  clearJwksCache();
  const env = { ...vars, DB: db, OPS: {}, ...(lifecycle ? { LIFECYCLE: lifecycle } : {}) };
  let now = NOW;
  const calls = [];
  const deps = { fetch: fetch ?? (async (url) => { calls.push(url); return Response.json({ keys: [JWK] }); }), now: () => now };
  const go = async (path, { method = 'GET', headers = {}, body, token = TOKEN } = {}) => {
    const h = { ...headers };
    if (token !== null) h['Cf-Access-Jwt-Assertion'] = token;
    const res = await handle(new Request(ORIGIN + path, { method, headers: h, body }), env, deps);
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch {}
    return { res, status: res.status, json, text };
  };
  const send = (method, path, body, headers = { 'X-CSRF': '1', Origin: ORIGIN }) =>
    go(API + path, { method, headers: { ...headers, 'Content-Type': 'application/json' }, body: body === undefined ? undefined : typeof body === 'string' ? body : JSON.stringify(body) });
  return { env, db, go, send, fetchCalls: calls, tick: (ms) => { now += ms; } };
}

const audits = (db) => db.raw.prepare('SELECT * FROM operator_audit ORDER BY rowid').all().map((r) => ({ ...r }));
const limitsOf = (db, plan) => db.raw.prepare('SELECT limit_key, soft_value, hard_value FROM plan_limits WHERE plan_id = ? ORDER BY limit_key').all(plan).map((r) => ({ ...r }));
const sub = (db, id) => ({ ...db.raw.prepare('SELECT plan_id, status, trial_end, period_end FROM subscriptions WHERE installation_id = ?').get(id) });

const WRITES = [
  ['PUT', '/plans/basic/limits', { limits: {} }],
  ['PUT', '/installations/b/subscription', { status: 'past_due' }],
  ['POST', '/jobs', { kind: 'suspend', installation_id: 'b', nonce: 'nonce-0001' }],
  ['POST', '/jobs/j1/confirm', {}],
  ['PUT', '/installations/b/limits/items', { hard_value: 20000 }],
  ['DELETE', '/installations/b/limits/items', undefined],
];

test('E6-3 ops writes: Access and CSRF refuse every write before anything is read or written', async () => {
  const t = setup({ lifecycle: workflowStub() });
  for (const [method, path, body] of WRITES) {
    const noAccess = await t.go(API + path, { method, token: null, headers: { 'X-CSRF': '1', Origin: ORIGIN }, body: body && JSON.stringify(body) });
    assert.deepEqual([noAccess.status, noAccess.json], [401, { ok: false, code: 'unauthorized' }], `${method} ${path}`);
    for (const headers of [{ Origin: ORIGIN }, { 'X-CSRF': '1' }, { 'X-CSRF': '1', Origin: 'https://evil.example.test' }, { 'X-CSRF': 'true', Origin: ORIGIN }]) {
      const r = await t.send(method, path, body, headers);
      assert.deepEqual([r.status, r.json], [403, { ok: false, code: 'csrf' }], `${method} ${path} ${JSON.stringify(headers)}`);
    }
  }
  assert.equal(t.db.batches.length, 0);
  assert.equal(audits(t.db).length, 0);
});

test('E6-3 ops writes: primary is refused (403 primary) by id and by its row, and nothing is written', async () => {
  const t = setup({ lifecycle: workflowStub() });
  const cases = [
    ['PUT', '/installations/primary/subscription', { plan_id: 'pro' }],
    ['PUT', '/installations/primary/limits/items', { hard_value: 5 }],
    ['DELETE', '/installations/primary/limits/items', undefined],
    ['POST', '/jobs', { kind: 'suspend', installation_id: 'primary', nonce: 'nonce-0001' }],
    ['PUT', '/plans/internal/limits', { limits: { items: { hard_value: 1 } } }],
  ];
  for (const [method, path, body] of cases) {
    const r = await t.send(method, path, body);
    assert.deepEqual([r.status, r.json], [403, { ok: false, code: 'primary' }], `${method} ${path}`);
  }
  // A row whose do_name is primary under another id, and a job row aimed at primary.
  t.db.raw.exec("INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('main', 'tenant-main', 'installations/tenant-main/', 'active')");
  t.db.raw.exec("INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, created_by) VALUES ('jp', 'primary', 'suspend', 'suspend:primary:nonce-0001', 'x@example.test')");
  assert.deepEqual((await t.send('POST', '/jobs/jp/confirm', {})).json, { ok: false, code: 'primary' });
  const db = controlDb();
  db.raw.exec("DELETE FROM subscriptions WHERE installation_id = 'primary'; DELETE FROM installation_routes WHERE installation_id = 'primary'; DELETE FROM installations WHERE id = 'primary'");
  db.raw.exec("INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('main', 'primary', 'installations/primary/', 'active')");
  const u = setup({ db });
  assert.deepEqual((await u.send('PUT', '/installations/main/subscription', { plan_id: 'pro', status: 'active' })).json, { ok: false, code: 'primary' });
  assert.equal(t.db.batches.length + u.db.batches.length, 0);
  assert.equal(sub(t.db, 'primary').plan_id, 'internal');
});

test('E6-3 ops writes: PUT /plans/:id/limits replaces the rows and audits before and after in one batch', async () => {
  const t = setup();
  const r = await t.send('PUT', '/plans/basic/limits', { limits: { items: { soft_value: 900, hard_value: 1200 }, photos: { hard_value: 0 }, users: null, sleep_after_ms: { soft_value: null, hard_value: null } } });
  assert.equal(r.status, 200, r.text);
  assert.deepEqual(r.json, { ok: true, plan_id: 'basic', limits: [
    { plan_id: 'basic', limit_key: 'items', soft_value: 900, hard_value: 1200 },
    { plan_id: 'basic', limit_key: 'photos', soft_value: null, hard_value: 0 },
  ] });
  assert.deepEqual(limitsOf(t.db, 'basic'), [{ limit_key: 'items', soft_value: 900, hard_value: 1200 }, { limit_key: 'photos', soft_value: null, hard_value: 0 }]);
  assert.equal(t.db.batches.length, 1);
  assert.match(t.db.batches[0].at(-1).sql, /INSERT INTO operator_audit/);
  const [a] = audits(t.db);
  assert.equal(a.operator, OPERATOR);
  assert.equal(a.action, 'plan.limits');
  assert.equal(a.installation_id, null);
  assert.equal(a.request_id, r.res.headers.get('x-request-id'));
  assert.deepEqual(JSON.parse(a.before_json), { plan_id: 'basic', limits: { items: { soft_value: 800, hard_value: 1000 }, users: { soft_value: null, hard_value: 3 } } });
  assert.deepEqual(JSON.parse(a.after_json), { plan_id: 'basic', limits: { items: { soft_value: 900, hard_value: 1200 }, photos: { soft_value: null, hard_value: 0 } } });
  // Every known key is accepted.
  const all = Object.fromEntries(LIMIT_KEYS.map((k) => [k, { hard_value: 1 }]));
  assert.equal((await t.send('PUT', '/plans/pro/limits', { limits: all })).status, 200);
  assert.equal(limitsOf(t.db, 'pro').length, LIMIT_KEYS.length);
  assert.equal((await t.send('PUT', '/plans/nope/limits', { limits: {} })).status, 404);
});

test('E6-3 ops writes: PUT /plans/:id/limits validates keys and values (422) and keeps the rows', async () => {
  const t = setup();
  const before = limitsOf(t.db, 'basic');
  for (const [body, detail] of [
    [{}, 'limits'],
    [{ limits: [] }, 'limits'],
    [{ limits: { bogus: { hard_value: 1 } } }, 'limits.bogus'],
    [{ limits: { items: 5 } }, 'limits.items'],
    [{ limits: { items: { hard_value: -1 } } }, 'limits.items.hard_value'],
    [{ limits: { items: { hard_value: 1.5 } } }, 'limits.items.hard_value'],
    [{ limits: { items: { hard_value: '10' } } }, 'limits.items.hard_value'],
    [{ limits: { items: { soft_value: 2, hard_value: 1 } } }, 'limits.items.soft_value'],
    [{ limits: { items: { hard: 1 } } }, 'limits.items.hard'],
    [{ limits: {}, extra: 1 }, 'extra'],
  ]) {
    const r = await t.send('PUT', '/plans/basic/limits', body);
    assert.deepEqual([r.status, r.json], [422, { ok: false, code: 'invalid_body', detail }], JSON.stringify(body));
  }
  for (const raw of ['not json', '[]', 'null', JSON.stringify({ limits: {}, pad: 'x'.repeat(5000) })]) {
    assert.deepEqual((await t.send('PUT', '/plans/basic/limits', raw)).json, { ok: false, code: 'invalid_body' });
  }
  assert.deepEqual(limitsOf(t.db, 'basic'), before);
  assert.equal(t.db.batches.length, 0);
  const m = await t.go(API + '/plans/basic/limits', { method: 'POST', headers: { 'X-CSRF': '1', Origin: ORIGIN } });
  assert.deepEqual([m.status, m.res.headers.get('allow')], [405, 'PUT']);
});

test('E6-3 ops writes: PUT /installations/:id/subscription changes status and plan; a downgrade needs confirm', async () => {
  const t = setup();
  const r = await t.send('PUT', '/installations/b/subscription', { status: 'past_due', period_end: '2026-12-31' });
  assert.equal(r.status, 200, r.text);
  assert.deepEqual({ ...r.json.subscription, updated_at: 'x' }, { installation_id: 'b', plan_id: 'pro', status: 'past_due', trial_end: null, period_end: '2026-12-31', updated_at: 'x' });
  let [a] = audits(t.db);
  assert.deepEqual([a.action, a.installation_id, a.operator], ['subscription.update', 'b', OPERATOR]);
  assert.deepEqual(JSON.parse(a.before_json), { plan_id: 'pro', status: 'active', trial_end: null, period_end: null });
  assert.deepEqual(JSON.parse(a.after_json), { plan_id: 'pro', status: 'past_due', trial_end: null, period_end: '2026-12-31' });
  assert.equal(t.db.batches.length, 1);

  // pro -> basic lowers items, users (and storage_bytes: basic has no row, so no limit, which is not lower).
  const down = await t.send('PUT', '/installations/b/subscription', { plan_id: 'basic' });
  assert.deepEqual([down.status, down.json], [409, { ok: false, code: 'downgrade_requires_confirm', keys: ['items', 'users'] }]);
  assert.equal(sub(t.db, 'b').plan_id, 'pro');
  assert.equal(audits(t.db).length, 1);
  assert.equal((await t.send('PUT', '/installations/b/subscription', { plan_id: 'basic', confirm: false })).status, 409);
  const ok = await t.send('PUT', '/installations/b/subscription', { plan_id: 'basic', confirm: true });
  assert.equal(ok.status, 200);
  assert.equal(sub(t.db, 'b').plan_id, 'basic');
  a = audits(t.db)[1];
  assert.deepEqual([JSON.parse(a.before_json).plan_id, JSON.parse(a.after_json).plan_id], ['pro', 'basic']);
  // basic -> pro raises items and users, but caps storage_bytes, which basic does not: that is lower too.
  assert.deepEqual((await t.send('PUT', '/installations/b/subscription', { plan_id: 'pro' })).json.keys, ['storage_bytes']);
  t.db.raw.exec("DELETE FROM plan_limits WHERE plan_id = 'pro' AND limit_key = 'storage_bytes'");
  assert.equal((await t.send('PUT', '/installations/b/subscription', { plan_id: 'pro' })).status, 200, 'an upgrade needs no confirm');
  // A plan with the same hard values is not lower.
  t.db.raw.exec("INSERT INTO plans (id, name) VALUES ('pro2', 'Pro 2'); INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) SELECT 'pro2', limit_key, NULL, hard_value FROM plan_limits WHERE plan_id = 'pro'");
  assert.equal((await t.send('PUT', '/installations/b/subscription', { plan_id: 'pro2' })).status, 200, 'same hard values need no confirm');
  assert.equal((await t.send('PUT', '/installations/b/subscription', { plan_id: 'pro' })).status, 200);
  t.db.raw.exec("INSERT INTO plan_limits (plan_id, limit_key, hard_value) VALUES ('basic', 'photos', 100)");
  t.db.raw.exec("DELETE FROM plan_limits WHERE plan_id = 'basic' AND limit_key IN ('items', 'users')");
  assert.deepEqual((await t.send('PUT', '/installations/b/subscription', { plan_id: 'basic' })).json.keys, ['photos']);

  // No subscription yet: plan_id and status are needed; then it is created.
  assert.deepEqual((await t.send('PUT', '/installations/n/subscription', { plan_id: 'basic' })).json, { ok: false, code: 'invalid_body', detail: 'status' });
  const created = await t.send('PUT', '/installations/n/subscription', { plan_id: 'basic', status: 'trial', trial_end: '2026-12-01T00:00:00Z' });
  assert.equal(created.status, 200);
  assert.equal(audits(t.db).at(-1).before_json, null);
  assert.deepEqual(sub(t.db, 'n'), { plan_id: 'basic', status: 'trial', trial_end: '2026-12-01T00:00:00Z', period_end: null });
});

test('E6-3 ops writes: PUT /installations/:id/subscription refusals', async () => {
  const t = setup();
  for (const [body, detail] of [
    [{}, 'plan_id'], [{ confirm: true }, 'plan_id'], [{ status: 'gold' }, 'status'], [{ plan_id: 7 }, 'plan_id'],
    [{ trial_end: '2026-02-30' }, 'trial_end'], [{ period_end: '31.12.2026' }, 'period_end'], [{ status: 'active', confirm: 'yes' }, 'confirm'],
    [{ status: 'active', notes: 'x' }, 'notes'],
  ]) {
    const r = await t.send('PUT', '/installations/b/subscription', body);
    assert.deepEqual([r.status, r.json], [422, { ok: false, code: 'invalid_body', detail }], JSON.stringify(body));
  }
  assert.deepEqual((await t.send('PUT', '/installations/b/subscription', { plan_id: 'gold' })).json, { ok: false, code: 'unknown_plan' });
  assert.deepEqual((await t.send('PUT', '/installations/b/subscription', { plan_id: 'old', confirm: true })).json, { ok: false, code: 'plan_inactive' });
  assert.deepEqual((await t.send('PUT', '/installations/zz/subscription', { status: 'active' })).json, { ok: false, code: 'not_found' });
  const gone = await t.send('PUT', '/installations/d/subscription', { status: 'cancelled' });
  assert.deepEqual([gone.status, gone.json], [410, { ok: false, code: 'gone' }]);
  assert.equal(t.db.batches.length, 0);
  t.db.failBatch = true;
  const failed = await t.send('PUT', '/installations/b/subscription', { status: 'cancelled' });
  assert.deepEqual([failed.status, failed.json], [503, { ok: false, code: 'unavailable' }]);
  assert.equal(sub(t.db, 'b').status, 'active');
  assert.equal(audits(t.db).length, 0);
});

test('E6-3 ops writes: POST /jobs creates a paused job with its audit row, idempotently; provision is off by default', async () => {
  const t = setup();
  const r = await t.send('POST', '/jobs', { kind: 'delete', installation_id: 'b', nonce: 'nonce-0001', retention_days: 30 });
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.created, true);
  const job = r.json.job;
  assert.deepEqual([job.kind, job.installation_id, job.state, job.idempotency_key, job.created_by, job.params_json], ['delete', 'b', 'paused', 'delete:b:nonce-0001', OPERATOR, '{"retention_days":30}']);
  const [a] = audits(t.db);
  assert.deepEqual([a.id, a.action, a.installation_id, a.operator, a.request_id], [`${job.id}:create`, 'lifecycle.delete.create', 'b', OPERATOR, r.res.headers.get('x-request-id')]);
  assert.deepEqual(JSON.parse(a.after_json), { job_id: job.id, retention_days: 30 });
  assert.equal(t.db.batches.length, 1);
  const again = await t.send('POST', '/jobs', { kind: 'delete', installation_id: 'b', nonce: 'nonce-0001', retention_days: 30 });
  assert.deepEqual([again.status, again.json.created, again.json.job.id], [200, false, job.id]);
  assert.equal(audits(t.db).length, 1, 'the same request again writes no second audit row');
  for (const kind of ['suspend', 'resume', 'export']) assert.equal((await t.send('POST', '/jobs', { kind, installation_id: 'b', nonce: 'nonce-0002' })).status, 201, kind);

  const prov = await t.send('POST', '/jobs', { kind: 'provision', installation_id: 'b', nonce: 'nonce-0003' });
  assert.deepEqual([prov.status, prov.json], [403, { ok: false, code: 'provisioning_disabled' }]);
  const on = setup({ vars: { ...VARS, OPS_PROVISIONING: '1' } });
  assert.equal((await on.send('POST', '/jobs', { kind: 'provision', installation_id: 'b', nonce: 'nonce-0003' })).status, 201);
  assert.deepEqual(JOB_KINDS, ['provision', 'suspend', 'resume', 'export', 'delete', 'purge']);
});

test('E6-3 ops writes: POST /jobs refusals (422, 404, 410, purge retention)', async () => {
  const t = setup();
  const base = { kind: 'suspend', installation_id: 'b', nonce: 'nonce-0001' };
  for (const [body, detail] of [
    [{ ...base, kind: 'reboot' }, 'kind'], [{ ...base, installation_id: 5 }, 'installation_id'], [{ ...base, nonce: 'short' }, 'nonce'],
    [{ ...base, nonce: 'bad nonce!' }, 'nonce'], [{ ...base, kind: 'delete' }, 'retention_days'], [{ ...base, kind: 'delete', retention_days: 0 }, 'retention_days'],
    [{ ...base, kind: 'delete', retention_days: 3651 }, 'retention_days'], [{ ...base, retention_days: 30 }, 'retention_days'], [{ ...base, extra: 1 }, 'extra'],
  ]) {
    const r = await t.send('POST', '/jobs', body);
    assert.deepEqual([r.status, r.json], [422, { ok: false, code: 'invalid_body', detail }], JSON.stringify(body));
  }
  assert.deepEqual((await t.send('POST', '/jobs', { ...base, installation_id: 'zz' })).json, { ok: false, code: 'not_found' });
  assert.equal((await t.send('POST', '/jobs', { ...base, installation_id: 'd' })).status, 410);
  const purge = await t.send('POST', '/jobs', { kind: 'purge', installation_id: 'x', nonce: 'nonce-0001' });
  assert.deepEqual([purge.status, purge.json], [409, { ok: false, code: 'retention' }]);
  assert.equal(t.db.raw.prepare('SELECT COUNT(*) AS n FROM lifecycle_jobs').get().n, 0);
  // A finished delete whose retention is over allows the purge.
  t.db.raw.exec(`INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, state, params_json, result_json, created_by, confirmed_by, updated_at)
    VALUES ('del', 'x', 'delete', 'delete:x:nonce-0001', 'done', '{"retention_days":1}', '{"key":"installations/tenant-x/backups/k"}', 'o@example.test', 'o@example.test', '2020-01-01T00:00:00Z')`);
  assert.equal((await t.send('POST', '/jobs', { kind: 'purge', installation_id: 'x', nonce: 'nonce-0001' })).status, 201);
  const get = await t.go(API + '/jobs');
  assert.equal(get.status, 200, 'GET /jobs is still the read');
  const m = await t.go(API + '/jobs', { method: 'PUT', headers: { 'X-CSRF': '1', Origin: ORIGIN } });
  assert.deepEqual([m.status, m.res.headers.get('allow')], [405, 'GET, HEAD, POST']);
});

test('E6-3 ops writes: POST /jobs/:id/confirm is 503 not_configured without the Workflow binding, and changes nothing', async () => {
  const t = setup();
  const job = (await t.send('POST', '/jobs', { kind: 'suspend', installation_id: 'b', nonce: 'nonce-0001' })).json.job;
  const r = await t.send('POST', `/jobs/${job.id}/confirm`, {});
  assert.deepEqual([r.status, r.json], [503, { ok: false, code: 'not_configured' }]);
  assert.equal(t.db.raw.prepare('SELECT state FROM lifecycle_jobs WHERE id = ?').get(job.id).state, 'paused');
  assert.equal(audits(t.db).length, 1);
});

test('E6-3 ops writes: POST /jobs/:id/confirm queues the job, audits it and starts its Workflow instance', async () => {
  const wf = workflowStub();
  const t = setup({ lifecycle: wf });
  const job = (await t.send('POST', '/jobs', { kind: 'suspend', installation_id: 'b', nonce: 'nonce-0001' })).json.job;
  const r = await t.send('POST', `/jobs/${job.id}/confirm`, {});
  assert.equal(r.status, 200, r.text);
  assert.deepEqual([r.json.job.state, r.json.job.confirmed_by], ['queued', OPERATOR]);
  assert.deepEqual(wf.calls, [{ id: job.id, params: { jobId: job.id } }]);
  const a = audits(t.db).at(-1);
  assert.deepEqual([a.id, a.action, a.installation_id, a.request_id], [`${job.id}:confirm`, 'lifecycle.suspend.confirm', 'b', r.res.headers.get('x-request-id')]);
  // Confirming again: the instance exists already; no second audit row.
  assert.equal((await t.send('POST', `/jobs/${job.id}/confirm`, {})).status, 200);
  assert.equal(audits(t.db).length, 2);
  t.db.raw.exec(`UPDATE lifecycle_jobs SET state = 'running' WHERE id = '${job.id}'`);
  assert.deepEqual((await t.send('POST', `/jobs/${job.id}/confirm`, {})).json, { ok: false, code: 'not_paused', detail: 'running' });
  assert.deepEqual((await t.send('POST', '/jobs/nope/confirm', {})).json, { ok: false, code: 'not_found' });

  // The instance cannot be created: 503, the job stays queued and a later confirm starts it.
  const down = setup({ lifecycle: workflowStub({ fail: true }) });
  const j2 = (await down.send('POST', '/jobs', { kind: 'resume', installation_id: 'b', nonce: 'nonce-0002' })).json.job;
  assert.deepEqual((await down.send('POST', `/jobs/${j2.id}/confirm`, {})).json, { ok: false, code: 'workflow_unavailable' });
  assert.equal(down.db.raw.prepare('SELECT state FROM lifecycle_jobs WHERE id = ?').get(j2.id).state, 'queued');
});

test('E6-3 ops writes: confirming a purge needs one of its hosts; a provision job needs provisioning on', async () => {
  const t = setup({ lifecycle: workflowStub() });
  t.db.raw.exec(`INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, state, params_json, result_json, created_by, confirmed_by, updated_at)
    VALUES ('del', 'x', 'delete', 'delete:x:nonce-0001', 'done', '{"retention_days":1}', '{"key":"installations/tenant-x/backups/k"}', 'o@example.test', 'o@example.test', '2020-01-01T00:00:00Z')`);
  const job = (await t.send('POST', '/jobs', { kind: 'purge', installation_id: 'x', nonce: 'nonce-0001' })).json.job;
  for (const body of [{}, { confirm_host: 'b.example.test' }]) assert.deepEqual((await t.send('POST', `/jobs/${job.id}/confirm`, body)).json, { ok: false, code: 'confirm_host' });
  assert.deepEqual((await t.send('POST', `/jobs/${job.id}/confirm`, { confirm_host: 5 })).json, { ok: false, code: 'invalid_body', detail: 'confirm_host' });
  const ok = await t.send('POST', `/jobs/${job.id}/confirm`, { confirm_host: ' X.Example.Test ' });
  assert.equal(ok.status, 200, ok.text);
  assert.deepEqual(JSON.parse(audits(t.db).at(-1).after_json), { job_id: job.id, host: 'x.example.test' });

  t.db.raw.exec("INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, created_by) VALUES ('prov', 'b', 'provision', 'provision:b:nonce-0001', 'o@example.test')");
  assert.deepEqual((await t.send('POST', '/jobs/prov/confirm', {})).json, { ok: false, code: 'provisioning_disabled' });
});

test('E6-3 ops writes: limit overrides are granted at or above the plan hard value and withdrawn, each with its audit row', async () => {
  const t = setup();
  const r = await t.send('PUT', '/installations/b/limits/items', { hard_value: 20000, reason: 'migration', expires_at: '2026-12-31T00:00:00Z' });
  assert.equal(r.status, 200, r.text);
  assert.deepEqual({ ...r.json.override, created_at: 'x' }, { installation_id: 'b', limit_key: 'items', hard_value: 20000, reason: 'migration', expires_at: '2026-12-31T00:00:00Z', created_by: OPERATOR, created_at: 'x' });
  assert.equal(t.db.batches.length, 1);
  let a = audits(t.db).at(-1);
  assert.deepEqual([a.action, a.installation_id, a.before_json], ['limit_override.set', 'b', null]);
  assert.deepEqual(JSON.parse(a.after_json), { limit_key: 'items', hard_value: 20000, reason: 'migration', expires_at: '2026-12-31T00:00:00Z' });
  assert.equal((await t.send('PUT', '/installations/b/limits/items', { hard_value: 10000 })).status, 200, 'equal to the plan value');
  assert.deepEqual(JSON.parse(audits(t.db).at(-1).before_json).hard_value, 20000);

  const below = await t.send('PUT', '/installations/b/limits/items', { hard_value: 9999 });
  assert.deepEqual([below.status, below.json], [422, { ok: false, code: 'below_plan_limit', plan_hard_value: 10000 }]);
  assert.deepEqual((await t.send('PUT', '/installations/b/limits/photos', { hard_value: 5 })).json, { ok: false, code: 'no_plan_limit' });
  assert.deepEqual((await t.send('PUT', '/installations/n/limits/items', { hard_value: 5 })).json, { ok: false, code: 'no_subscription' });
  assert.deepEqual((await t.send('PUT', '/installations/b/limits/bogus', { hard_value: 5 })).json, { ok: false, code: 'unknown_limit' });
  assert.equal((await t.send('PUT', '/installations/d/limits/items', { hard_value: 5 })).status, 410);
  for (const [body, detail] of [
    [{}, 'hard_value'], [{ hard_value: -1 }, 'hard_value'], [{ hard_value: 20000, expires_at: '2026-11-20T11:59:59Z' }, 'expires_at'],
    [{ hard_value: 20000, expires_at: '2026-12-31' }, 'expires_at'], [{ hard_value: 20000, reason: 'x'.repeat(201) }, 'reason'],
    [{ hard_value: 20000, reason: 'a\nb' }, 'reason'], [{ hard_value: 20000, soft_value: 1 }, 'soft_value'],
  ]) {
    const x = await t.send('PUT', '/installations/b/limits/items', body);
    assert.deepEqual([x.status, x.json], [422, { ok: false, code: 'invalid_body', detail }], JSON.stringify(body));
  }
  assert.equal(audits(t.db).length, 2);

  const del = await t.send('DELETE', '/installations/b/limits/items');
  assert.deepEqual([del.status, del.json], [200, { ok: true }]);
  a = audits(t.db).at(-1);
  assert.deepEqual([a.action, a.after_json, JSON.parse(a.before_json).hard_value], ['limit_override.delete', null, 10000]);
  assert.equal(t.db.raw.prepare('SELECT COUNT(*) AS n FROM limit_overrides').get().n, 0);
  assert.deepEqual((await t.send('DELETE', '/installations/b/limits/items')).json, { ok: false, code: 'not_found' });
  const m = await t.go(API + '/installations/b/limits/items', { method: 'POST', headers: { 'X-CSRF': '1', Origin: ORIGIN } });
  assert.deepEqual([m.status, m.res.headers.get('allow')], [405, 'PUT, DELETE']);
});

test('E6-3 ops writes: no audit row from a write holds a credential-like key', async () => {
  const t = setup({ lifecycle: workflowStub() });
  await t.send('PUT', '/plans/basic/limits', { limits: { items: { hard_value: 5 } } });
  await t.send('PUT', '/installations/b/subscription', { status: 'trial' });
  const job = (await t.send('POST', '/jobs', { kind: 'export', installation_id: 'b', nonce: 'nonce-0001' })).json.job;
  await t.send('POST', `/jobs/${job.id}/confirm`, {});
  await t.send('PUT', '/installations/b/limits/items', { hard_value: 20000 });
  const rows = audits(t.db);
  assert.equal(rows.length, 5);
  for (const row of rows) assert.ok(!/token|password|secret|"link"/i.test(JSON.stringify(row)), row.action);
});

test('E6-2 ops: GET /audit.csv with exactly CSV_MAX_ROWS rows is not truncated (X-Truncated: 0)', async () => {
  const t = setup();
  const ins = t.db.raw.prepare('INSERT INTO operator_audit (id, at, operator, action) VALUES (?, ?, ?, ?)');
  t.db.raw.exec('BEGIN');
  for (let i = 0; i < CSV_MAX_ROWS; i++) ins.run(`r${String(i).padStart(6, '0')}`, '2026-11-01T00:00:00Z', 'o@example.test', 'x');
  t.db.raw.exec('COMMIT');
  const r = await t.go(API + '/audit.csv');
  assert.equal(CSV_MAX_ROWS, 10_000);
  assert.equal(r.res.headers.get('x-truncated'), '0');
  assert.equal(r.text.split('\r\n').length, CSV_MAX_ROWS + 2, 'header, every row and the final CRLF');
});

test('E6-2 ops: JWKS_FAILURE_MS is 10 s; at 9,999 ms after a failed key fetch it is not retried, at 10 s it is', async () => {
  assert.equal(JWKS_FAILURE_MS, 10_000);
  let fail = true;
  const calls = [];
  const t = setup({ fetch: async (url) => { calls.push(url); if (fail) return new Response('no', { status: 500 }); return Response.json({ keys: [JWK] }); } });
  assert.equal((await t.go(API + '/config')).status, 503);
  fail = false;
  t.tick(9_999);
  const early = await t.go(API + '/config');
  assert.deepEqual([early.status, early.json, calls.length], [503, { ok: false, code: 'access_unavailable' }, 1]);
  t.tick(1);
  assert.equal((await t.go(API + '/config')).status, 200);
  assert.equal(calls.length, 2);
});

test('E6-3 ops writes: every write route is 403 writes_disabled unless OPS_WRITES_ENABLED is "1", after Access and before CSRF', async () => {
  const { writesEnabled } = await import('../ops/src/index.ts');
  const job = "INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, created_by) VALUES ('j1', 'b', 'suspend', 'suspend:b:nonce-0009', 'o@example.test')";
  for (const value of ['0', '', undefined, 'true', ' 1']) {
    const wf = workflowStub();
    const t = setup({ vars: { ...VARS, OPS_WRITES_ENABLED: value }, lifecycle: wf });
    t.db.raw.exec(job);
    assert.equal(writesEnabled(t.env), false, String(value));
    for (const [method, path, body] of WRITES) {
      for (const headers of [{ 'X-CSRF': '1', Origin: ORIGIN }, {}]) {
        const r = await t.send(method, path, body, headers);
        assert.deepEqual([r.status, r.json], [403, { ok: false, code: 'writes_disabled' }], `${String(value)} ${method} ${path}`);
      }
      const anon = await t.go(API + path, { method, token: null, headers: { 'X-CSRF': '1', Origin: ORIGIN }, body: body && JSON.stringify(body) });
      assert.equal(anon.status, 401, `Access comes first: ${method} ${path}`);
    }
    assert.equal(t.db.batches.length, 0);
    assert.equal(audits(t.db).length, 0);
    assert.equal(wf.calls.length, 0);
    for (const path of ['/jobs', '/plans', '/installations/b', '/audit']) assert.equal((await t.go(API + path)).status, 200, `reads stay open: ${path}`);
  }

  // Gate on: the same requests reach their handlers.
  const wf = workflowStub();
  const t = setup({ vars: { ...VARS, OPS_WRITES_ENABLED: '1' }, lifecycle: wf });
  t.db.raw.exec(job);
  const expected = { 'PUT /plans/basic/limits': 200, 'PUT /installations/b/subscription': 200, 'POST /jobs': 201, 'POST /jobs/j1/confirm': 200, 'PUT /installations/b/limits/items': 200, 'DELETE /installations/b/limits/items': 200 };
  for (const [method, path, body] of WRITES) {
    const r = await t.send(method, path, body);
    assert.equal(r.status, expected[`${method} ${path}`], `${method} ${path}: ${r.text}`);
    const noCsrf = await t.send(method, path, body, {});
    assert.deepEqual([noCsrf.status, noCsrf.json], [403, { ok: false, code: 'csrf' }], `CSRF still applies: ${method} ${path}`);
  }
  assert.deepEqual(wf.calls.map((c) => c.id), ['j1']);
  assert.equal(audits(t.db).length, 6);
});
