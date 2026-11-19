// Plan limits in the Worker (operator console E5): requests, container active time, request body size and stored
// bytes, from plan_limits through the installation's subscription. Off unless LIMITS_ENABLED is "1"; primary never.
// Loaded by r2.test.mjs, which registers the cloudflare:workers stub, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';

const { default: publicWorker, ApothekeContainer, R2Outbound, Installation } = await import('../src/index.ts');
const { clearRouteCache, routeFor } = await import('../src/route.ts');
const { CORS_EXPOSE, Counter, FLUSH_MS, LIMITS_HEADER, WARNING_HEADER, UsageMeter, isPreflight, limitsEnabled, parseLimits, period, withCors } = await import('../src/limits.ts');
const { RECOUNT_BEFORE_REFUSAL_MS, RECOUNT_MS } = await import('../src/installation.ts');
const { doStorage } = await import(new URL('./do-storage.mjs', import.meta.url).href);

const ON = { LIMITS_ENABLED: '1' };
const OFF = { LIMITS_ENABLED: '0' };
const REQUESTS_MSG = 'This Apotheke reached its monthly request limit. The owner can upgrade the plan.\n';
const HOURS_MSG = "This month's active time is used up\n";
const MB = 1024 * 1024;

/** The control migrations plus plan "tiny" (every Worker limit small) for tenant-b, on a real SQLite. */
function controlDb() {
  const db = new DatabaseSync(':memory:');
  db.exec('PRAGMA foreign_keys = ON');
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  db.exec(`INSERT INTO plans (id, name) VALUES ('tiny', 'Tiny');
    INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES
      ('tiny', 'requests_month', NULL, 10), ('tiny', 'container_hours_month', 1, 2), ('tiny', 'import_file_bytes', NULL, 1572864),
      ('tiny', 'storage_bytes', 8, 10), ('tiny', 'items', NULL, NULL);
    INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'tenant-b', 'installations/tenant-b/', 'active');
    INSERT INTO installation_routes (host, installation_id) VALUES ('b.example.test', 'b');
    INSERT INTO subscriptions (installation_id, plan_id, status) VALUES ('b', 'tiny', 'active');`);
  const queries = [];
  return { raw: db, queries, prepare: (sql) => ({ bind: (...v) => ({ first: async () => { queries.push(sql); const r = db.prepare(sql).get(...v); return r ? { ...r } : null; } }) }) };
}

function worker(DB, vars) {
  const seen = [];
  seen.tried = 0;
  const env = { DB, ...vars, APOTHEKE: { getByName: (n) => ({ fetch: async (req) => { seen.tried += 1; seen.push({ n, req, body: req.body ? await new Response(req.body).text() : null }); return new Response('app'); } }) } };
  return { seen, go: (url, init) => { const req = new Request(url, init); return publicWorker.fetch(req, env).then((res) => ({ res, req })); } };
}

/** Durable Object storage with the KV and alarm calls the counters use, over the SQLite test storage. */
function kvStorage() {
  const kv = new Map();
  const s = { ...doStorage(), kv, alarm: null, puts: 0 };
  Object.assign(s, {
    get: async (k) => structuredClone(kv.get(k)),
    put: async (k, v) => { s.puts += 1; kv.set(k, structuredClone(v)); },
    getAlarm: async () => s.alarm,
    setAlarm: async (t) => { s.alarm = t; },
  });
  return s;
}

/** A running, ready container object named name, with every storage binding, recording what reaches PHP. */
function readyBox(name, vars, storage = kvStorage(), reply = () => new Response('ok')) {
  const calls = [];
  const wired = [];
  const container = {
    running: true,
    start() { throw new Error('already running'); },
    setInactivityTimeout: async () => {},
    interceptOutboundHttp: async () => {},
    getTcpPort: () => ({ fetch: async (input) => { calls.push(input); return reply(); } }),
  };
  const ctx = { id: { name }, container, storage, blockConcurrencyWhile: (f) => f(), waitUntil() {},
    exports: { D1Outbound: () => ({}), R2Outbound: (o) => { wired.push(o.props); return {}; }, AutofillOutbound: () => ({}) } };
  const env = { APP_PASSWORD: 'p', APP_KEY: 'k', STORAGE: {}, DB: {}, INSTALLATION: {}, ...vars };
  return { box: new ApothekeContainer(ctx, env), calls, wired, storage };
}
const limited = (limits) => new Request('https://b.example.test/api/v1/items', { headers: { [LIMITS_HEADER]: JSON.stringify(limits) } });

test('E5: the route carries the plan limits from the subscription, soft 80% of hard when unset, cached with it', async () => {
  clearRouteCache();
  const db = controlDb();
  const route = await routeFor('b.example.test', db, 1000, true);
  assert.deepEqual(route, { doName: 'tenant-b', status: 'active', limits: {
    requests_month: { soft: 8, hard: 10 }, container_hours_month: { soft: 1, hard: 2 },
    import_file_bytes: { soft: 1572864 * 0.8, hard: 1572864 }, storage_bytes: { soft: 8, hard: 10 } } });
  await routeFor('b.example.test', db, 2000, true);
  assert.equal(db.queries.length, 1);
  clearRouteCache();
  db.raw.exec("DELETE FROM subscriptions WHERE installation_id = 'b'");
  assert.deepEqual((await routeFor('b.example.test', db, 1000, true)).limits, {});
  clearRouteCache();
  // Without limits the lookup and the row are as before E5.
  assert.deepEqual(await routeFor('b.example.test', db, 1000), { doName: 'tenant-b', status: 'active' });
  assert.doesNotMatch(db.queries.at(-1), /plan_limits/);
  assert.deepEqual(parseLimits('not json'), {});
});

test('E5: LIMITS_ENABLED off forwards the very same request, with no limits lookup or header, whatever the plan', async () => {
  for (const vars of [OFF, {}]) {
    clearRouteCache();
    const db = controlDb();
    const w = worker(db, vars);
    const body = 'x'.repeat(2 * MB);
    const { res, req } = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body });
    assert.equal(await res.text(), 'app');
    assert.equal(w.seen[0].req, req);
    assert.equal(w.seen[0].req.headers.has(LIMITS_HEADER), false);
    assert.equal(w.seen[0].body.length, body.length);
    assert.doesNotMatch(db.queries.join(), /plan_limits/);
  }
});

test('E5: off, the container object passes a request through, without a client limits header, and counts nothing', async () => {
  const { box, calls, wired, storage } = readyBox('tenant-b', OFF);
  for (let i = 0; i < 3; i++) assert.equal((await box.fetch(limited({ requests_month: { soft: 0, hard: 0 } }))).status, 200);
  assert.equal(calls[0].headers.get(LIMITS_HEADER), null);
  assert.ok(wired.every((p) => !('storageHard' in p)));
  assert.equal(storage.kv.size, 0);
  assert.equal(storage.alarm, null);
});

test('E5: primary is never limited, at any plan and any counter', async () => {
  clearRouteCache();
  const db = controlDb();
  db.raw.exec("UPDATE subscriptions SET plan_id = 'tiny' WHERE installation_id = 'primary'");
  const w = worker(db, ON);
  const { res, req } = await w.go('https://api.services.getapotheke.com/api/v1/import', { method: 'POST', body: 'x'.repeat(2 * MB) });
  assert.equal(await res.text(), 'app');
  assert.equal(w.seen[0].n, 'primary');
  assert.equal(w.seen[0].req, req);
  // The container object for primary, with every counter used up and a limits header sent by a client.
  const storage = kvStorage();
  storage.kv.set('usage', { period: period(Date.now()), requests: 1e9, containerMs: 1e15, lastActivity: 0, countedTo: 0 });
  const { box, calls, wired } = readyBox('primary', ON, storage);
  const out = await box.fetch(limited({ requests_month: { soft: 0, hard: 0 }, container_hours_month: { soft: 0, hard: 0 } }));
  assert.equal(out.status, 200);
  assert.equal(out.headers.get(WARNING_HEADER), null);
  assert.equal(calls[0].headers.get(LIMITS_HEADER), null);
  assert.ok(wired.every((p) => !('storageHard' in p)));
  assert.equal(storage.alarm, null);
  // primary's stored bytes are never metered either.
  const r2 = new R2Outbound({ props: { doName: 'primary', prefix: 'installations/primary/', storageHard: 0 } }, { ...ON, STORAGE: new Bucket(), DB: {}, INSTALLATION: refusingNs() });
  assert.equal((await r2.fetch(put('a.jpg', 'xx'))).status, 200);
});

test('E5: a body over import_file_bytes is 413 before it is read, by Content-Length or by a streaming cap', async () => {
  clearRouteCache();
  const w = worker(controlDb(), ON);
  const over = 'x'.repeat(1572865);
  const { res } = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body: over });
  assert.equal(res.status, 413);
  assert.equal(await res.text(), 'File is larger than your plan allows (1.5 MB)\n');
  assert.equal(w.seen.length, 0);
  const stream = () => new ReadableStream({ start(c) { for (let i = 0; i < 4; i++) c.enqueue(new Uint8Array(512 * 1024)); c.close(); } });
  const chunked = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body: stream(), duplex: 'half' });
  assert.equal(chunked.res.status, 413);
  assert.equal(await chunked.res.text(), 'File is larger than your plan allows (1.5 MB)\n');
  // At the limit it is forwarded, with the Worker's limits replacing a client's header.
  const ok = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body: over.slice(1), headers: { [LIMITS_HEADER]: '{}' } });
  assert.equal(ok.res.status, 200);
  assert.equal(w.seen.at(-1).body.length, 1572864);
  assert.equal(JSON.parse(w.seen.at(-1).req.headers.get(LIMITS_HEADER)).requests_month.hard, 10);
});

test('E5: requests per month: a warning from the soft value, 429 with the plan message at the hard value', async () => {
  const { box, calls } = readyBox('tenant-b', ON);
  const limits = { requests_month: { soft: 2, hard: 3 } };
  const seen = [];
  for (let i = 0; i < 4; i++) {
    const res = await box.fetch(limited(limits));
    seen.push([res.status, res.headers.get(WARNING_HEADER)]);
    if (res.status === 429) assert.equal(await res.text(), REQUESTS_MSG);
  }
  assert.deepEqual(seen, [[200, null], [200, 'requests_month'], [200, 'requests_month'], [429, null]]);
  assert.equal(calls.length, 3);
  assert.equal(calls[0].headers.get(LIMITS_HEADER), null);
});

test('E5: a new month starts the request and active-time counters from zero', async () => {
  const storage = kvStorage();
  storage.kv.set('usage', { period: '2000-01', requests: 99, containerMs: 1e12, lastActivity: 0, countedTo: 0 });
  const { box } = readyBox('tenant-b', ON, storage);
  const res = await box.fetch(limited({ requests_month: { soft: 50, hard: 99 }, container_hours_month: { soft: 1, hard: 2 } }));
  assert.equal(res.status, 200);
  assert.deepEqual(await box.usage(), { period: period(Date.now()), requests: 1, container_seconds: 0 });
});

test('E5: container active time is metered start to stop and refused with a 402 page once used up', async () => {
  const sleep = 15 * 60_000;
  const meter = new UsageMeter(kvStorage(), () => sleep);
  const limits = { container_hours_month: { soft: 0.5, hard: 1 } };
  const t0 = Date.UTC(2026, 10, 2);
  assert.equal(await meter.admit(limits, t0), null);
  // Requests 10 minutes apart keep it running: 40 minutes so far, under the soft half hour until the last one.
  for (let m = 10; m <= 40; m += 10) assert.equal(await meter.admit(limits, t0 + m * 60_000), null);
  const h = new Headers();
  await meter.done(limits, h, t0 + 40 * 60_000);
  assert.equal(h.get(WARNING_HEADER), 'container_hours_month');
  // A day later: it slept 15 minutes after the last request, so 55 minutes, and the pause is not counted.
  assert.equal((await meter.usage(t0 + 86_400_000)).container_seconds, 55 * 60);
  assert.equal(await meter.admit(limits, t0 + 86_400_000), null);
  const used = await meter.admit(limits, t0 + 86_400_000 + 5 * 60_000);
  assert.equal(used.status, 402);
  assert.equal(await used.text(), HOURS_MSG);
  // The container object answers the same without forwarding (it does not wake the container).
  const storage = kvStorage();
  storage.kv.set('usage', { period: period(Date.now()), requests: 0, containerMs: 3_600_000, lastActivity: 0, countedTo: 0 });
  const { box, calls } = readyBox('tenant-b', ON, storage);
  const res = await box.fetch(limited(limits));
  assert.equal(res.status, 402);
  assert.equal(await res.text(), HOURS_MSG);
  assert.equal(calls.length, 0);
});

test('E5: counters stay in memory and are written by one alarm at most 60 s after a change', async () => {
  const storage = kvStorage();
  const { box } = readyBox('tenant-b', ON, storage);
  const before = Date.now();
  for (let i = 0; i < 5; i++) await box.fetch(limited({ requests_month: { soft: 100, hard: 100 } }));
  assert.equal(storage.puts, 0);
  assert.ok(storage.alarm >= before + FLUSH_MS && storage.alarm <= Date.now() + FLUSH_MS);
  await box.alarm();
  assert.equal(storage.puts, 1);
  assert.equal(storage.kv.get('usage').requests, 5);
  // A restarted object carries on from storage.
  const again = readyBox('tenant-b', ON, storage);
  await again.box.fetch(limited({ requests_month: { soft: 100, hard: 100 } }));
  assert.equal((await again.box.usage()).requests, 6);
});

/** Just enough R2 for put, conditional put, delete and a multipart complete, with sizes on head(). */
class Bucket {
  objects = new Map();
  async put(key, value, options = {}) {
    if (options.onlyIf?.etagDoesNotMatch === '*' && this.objects.has(key)) return null;
    this.objects.set(key, new Uint8Array(typeof value === 'string' ? new TextEncoder().encode(value) : value));
    return { key };
  }
  async head(key) { const b = this.objects.get(key); return b ? { size: b.byteLength, etag: 'e', uploaded: new Date(0) } : null; }
  async get(key) { const b = this.objects.get(key); return b ? { size: b.byteLength, text: async () => new TextDecoder().decode(b) } : null; }
  async delete(key) { this.objects.delete(key); }
  lists = 0;
  async list({ prefix, cursor, limit }) {
    this.lists += 1;
    const keys = [...this.objects.keys()].filter((k) => k.startsWith(prefix)).sort();
    const start = Number(cursor ?? 0);
    const page = keys.slice(start, start + Math.min(limit, 2));
    const truncated = start + page.length < keys.length;
    return { objects: page.map((key) => ({ key, size: this.objects.get(key).byteLength })), truncated, ...(truncated ? { cursor: String(start + page.length) } : {}) };
  }
  resumeMultipartUpload(key) { return { complete: async () => { this.objects.set(key, new Uint8Array(6)); } }; }
}
/** An Installation namespace that must not be called. */
function refusingNs() {
  return { jurisdiction: () => ({ idFromName: (name) => ({ name }), get: () => { throw new Error('the Installation object was called'); } }) };
}
function storageWorld(storageHard) {
  const env = { ...ON, STORAGE: new Bucket(), DB: {} };
  const installation = new Installation({ storage: kvStorage() }, env);
  env.INSTALLATION = { jurisdiction: () => ({ idFromName: (name) => ({ name }), get: () => installation }) };
  const props = { doName: 'tenant-b', prefix: 'installations/tenant-b/', ...(storageHard === undefined ? {} : { storageHard }) };
  return { r2: new R2Outbound({ props }, env), installation, bucket: env.STORAGE, storage: installation.ctx.storage, used: async () => (await installation.stored?.get())?.bytes ?? 0 };
}
const put = (path, body, query = '') => new Request(`http://r2.internal/${path}${query}`, { method: 'PUT', body, duplex: 'half' });

test('E5: stored bytes are counted in the Installation object, and a write past the hard value is 507', async () => {
  const { r2, used } = storageWorld(10);
  assert.equal((await r2.fetch(put('a.jpg', '123456'))).status, 200);
  assert.equal(await used(), 6);
  const full = await r2.fetch(put('b.jpg', '12345'));
  assert.equal(full.status, 507);
  assert.deepEqual(await full.json(), { ok: false, code: 'storage_full', error: 'Storage full: delete photos or attachments, or upgrade' });
  assert.equal(await used(), 6);
  // An overwrite counts only the difference, a delete gives its bytes back.
  assert.equal((await r2.fetch(put('a.jpg', '1234567890'))).status, 200);
  assert.equal(await used(), 10);
  assert.equal((await r2.fetch(new Request('http://r2.internal/a.jpg', { method: 'DELETE' }))).status, 200);
  assert.equal(await used(), 0);
  // A conditional write that does not happen gives its reservation back.
  assert.equal((await r2.fetch(put('c.jpg', '12'))).status, 200);
  assert.equal((await r2.fetch(put('c.jpg', '1234', '?if_none_match=*'))).status, 412);
  assert.equal(await used(), 2);
});

test('E5: a multipart complete is refused once the store is full, and counts the finished object', async () => {
  const { r2, used, bucket } = storageWorld(10);
  const marker = async (id, key) => {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(id));
    bucket.objects.set('installations/tenant-b/.mpu/' + Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join(''), new TextEncoder().encode(JSON.stringify({ id, key })));
  };
  const complete = (id) => new Request(`http://r2.internal/big.bin?action=complete&upload_id=${id}&parts=${encodeURIComponent('[{"partNumber":1,"etag":"e1"}]')}`, { method: 'PUT' });
  await marker('up1', 'installations/tenant-b/big.bin');
  assert.equal((await r2.fetch(complete('up1'))).status, 200);
  assert.equal(await used(), 6);
  assert.equal((await r2.fetch(put('fill.bin', '1234'))).status, 200);
  await marker('up2', 'installations/tenant-b/big.bin');
  assert.equal((await r2.fetch(complete('up2'))).status, 507);
});

test('E5: without storageHard in the props (limits off, or primary) the Installation object is never called', async () => {
  const env = { ...ON, STORAGE: new Bucket(), DB: {}, INSTALLATION: refusingNs() };
  const r2 = new R2Outbound({ props: { doName: 'tenant-b', prefix: 'installations/tenant-b/' } }, env);
  assert.equal((await r2.fetch(put('a.jpg', 'x'.repeat(100)))).status, 200);
  const off = new R2Outbound({ props: { doName: 'tenant-b', prefix: 'installations/tenant-b/', storageHard: 0 } }, { ...env, ...OFF });
  assert.equal((await off.fetch(put('b.jpg', 'x'.repeat(100)))).status, 200);
});

test('E5: with limits on, the container object wires storageHard (null when the plan has no storage limit)', async () => {
  const { box, wired } = readyBox('tenant-b', ON);
  await box.fetch(limited({ storage_bytes: { soft: 8, hard: 10 } }));
  await box.fetch(limited({}));
  assert.deepEqual(wired.slice(-2).map((p) => p.storageHard), [10, null]);
});

// Follow-ups to #315 (QA 2: L-2171 to L-2175).

test('E5: a client limits or warning header never reaches PHP, and only the edge sets the warning, on or off', async () => {
  const sent = { [LIMITS_HEADER]: '{}', [WARNING_HEADER]: 'requests_month' };
  const fromPhp = () => new Response('ok', { headers: { [WARNING_HEADER]: 'items' } });
  for (const [name, vars] of [['tenant-b', OFF], ['tenant-b', {}], ['primary', ON], ['tenant-b', ON]]) {
    const { box, calls } = readyBox(name, vars, kvStorage(), fromPhp);
    const res = await box.fetch(new Request('https://b.example.test/api/v1/items', { headers: sent }));
    assert.equal(res.status, 200);
    assert.equal(calls[0].headers.get(LIMITS_HEADER), null, `${name} ${JSON.stringify(vars)}`);
    assert.equal(calls[0].headers.get(WARNING_HEADER), null);
    assert.equal(res.headers.get(WARNING_HEADER), null);
  }
  // With limits on, the warning a client sees is the edge's own.
  const { box, calls } = readyBox('tenant-b', ON, kvStorage(), fromPhp);
  const res = await box.fetch(new Request('https://b.example.test/api/v1/items', { headers: { [LIMITS_HEADER]: JSON.stringify({ requests_month: { soft: 0, hard: 5 } }), [WARNING_HEADER]: 'x' } }));
  assert.equal(res.headers.get(WARNING_HEADER), 'requests_month');
  assert.equal(calls[0].headers.get(WARNING_HEADER), null);
});

test('E5: the body cap runs whatever Content-Length says: a valid one over the limit is refused before forwarding', async () => {
  clearRouteCache();
  const w = worker(controlDb(), ON);
  const over = 'x'.repeat(1572865);
  const valid = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body: over, headers: { 'content-length': String(over.length) } });
  assert.equal(valid.res.status, 413);
  assert.equal(w.seen.tried, 0, 'refused from the header, nothing forwarded');
  for (const length of ['abc', '', '-1', '1e9']) {
    const { res } = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body: over, headers: { 'content-length': length } });
    assert.equal(res.status, 413, `content-length ${JSON.stringify(length)}`);
    assert.equal(await res.text(), 'File is larger than your plan allows (1.5 MB)\n');
  }
  // A valid length under the limit with a longer body is cut off at that length: a 400 (N-2205), never forwarded.
  assert.equal((await w.go('https://b.example.test/api/v1/import', { method: 'POST', body: over, headers: { 'content-length': '5' } })).res.status, 400);
  assert.equal(w.seen.length, 0);
});

test('E5: a valid Content-Length under the limit is kept on the forwarded body through the runtime FixedLengthStream', async () => {
  const lengths = [];
  globalThis.FixedLengthStream = class extends TransformStream { constructor(n) { super(); lengths.push(n); } };
  try {
    clearRouteCache();
    const w = worker(controlDb(), ON);
    const body = 'x'.repeat(1000);
    const { res } = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body, headers: { 'content-length': '1000' } });
    assert.equal(res.status, 200);
    assert.deepEqual(lengths, [1000]);
    assert.equal(w.seen[0].body, body);
    // An invalid one is not kept.
    await w.go('https://b.example.test/api/v1/import', { method: 'POST', body, headers: { 'content-length': 'abc' } });
    assert.deepEqual(lengths, [1000]);
    assert.equal(w.seen[1].body, body);
    assert.equal(w.seen[1].req.headers.get('content-length'), null);
  } finally {
    delete globalThis.FixedLengthStream;
  }
});

const ORIGINS = { CORS_ALLOWED_ORIGINS: 'https://app.example.test, https://dot.example.test., http://plain.example.test, *' };
const corsOf = (res) => Object.fromEntries([...res.headers].filter(([k]) => k.startsWith('access-control-') || k === 'vary'));
const ALLOWED = { 'access-control-allow-origin': 'https://app.example.test', 'access-control-allow-credentials': 'true', 'access-control-expose-headers': CORS_EXPOSE, vary: 'Origin' };

test('E5: the 413, 429 and 402 refusals carry the app CORS headers for an allowed origin on /api', async () => {
  clearRouteCache();
  const w = worker(controlDb(), { ...ON, ...ORIGINS });
  const big = { method: 'POST', body: 'x'.repeat(1572865) };
  const { res } = await w.go('https://b.example.test/api/v1/import', { ...big, headers: { Origin: 'https://app.example.test' } });
  assert.equal(res.status, 413);
  assert.deepEqual(corsOf(res), ALLOWED);
  const declared = await w.go('https://b.example.test/api/v1/import', { ...big, headers: { Origin: 'https://app.example.test', 'content-length': '1572865' } });
  assert.equal(declared.res.status, 413);
  assert.deepEqual(corsOf(declared.res), ALLOWED, 'refused from Content-Length');
  assert.deepEqual(corsOf((await w.go('https://b.example.test/api/v1/import', { ...big, headers: { Origin: 'https://other.example.test' } })).res), { vary: 'Origin' });
  assert.deepEqual(corsOf((await w.go('https://b.example.test/import', { ...big, headers: { Origin: 'https://app.example.test' } })).res), {}, 'not /api');
  assert.deepEqual(corsOf((await worker(controlDb(), ON).go('https://b.example.test/api/v1/import', { ...big, headers: { Origin: 'https://app.example.test' } })).res), {}, 'no CORS_ALLOWED_ORIGINS');
  // The container object's refusals.
  const storage = kvStorage();
  storage.kv.set('usage', { period: period(Date.now()), requests: 5, containerMs: 0, lastActivity: 0, countedTo: 0 });
  const { box } = readyBox('tenant-b', { ...ON, ...ORIGINS }, storage);
  const ask = (limits, origin, method = 'GET') => box.fetch(new Request('https://b.example.test/api/v1/items', { method,
    headers: { [LIMITS_HEADER]: JSON.stringify(limits), Origin: origin, ...(method === 'OPTIONS' ? { 'Access-Control-Request-Method': 'POST' } : {}) } }));
  const r429 = await ask({ requests_month: { soft: 1, hard: 5 } }, 'https://app.example.test');
  assert.equal(r429.status, 429);
  assert.deepEqual(corsOf(r429), ALLOWED);
  const r402 = await ask({ container_hours_month: { soft: 0, hard: 0 } }, 'https://dot.example.test');
  assert.equal(r402.status, 402);
  assert.equal(r402.headers.get('access-control-allow-origin'), 'https://dot.example.test', 'one trailing dot is the same origin');
  const pre = await ask({ requests_month: { soft: 1, hard: 5 } }, 'https://APP.example.test.', 'OPTIONS');
  assert.equal(pre.status, 204, 'a preflight is never refused (L-2201)');
  assert.deepEqual(corsOf(pre), { ...ALLOWED, 'access-control-allow-origin': 'https://app.example.test.',
    'access-control-allow-methods': 'GET, POST, PATCH, PUT, DELETE', 'access-control-allow-headers': 'Content-Type, X-CSRF-Token, If-Match, Idempotency-Key', 'access-control-max-age': '600' });
  assert.deepEqual(corsOf(await ask({ requests_month: { soft: 1, hard: 5 } }, 'http://app.example.test')), { vary: 'Origin' }, 'scheme counts');
  assert.deepEqual(corsOf(await ask({ requests_month: { soft: 1, hard: 5 } }, 'http://plain.example.test')), { vary: 'Origin' }, 'plain http is never allowed');
  assert.deepEqual(corsOf(await ask({ requests_month: { soft: 1, hard: 5 } }, 'https://any.example.test')), { vary: 'Origin' }, 'no wildcard');
});

test('E5: the edge exposes the same response headers as the app, which include the limit warning', () => {
  const php = readFileSync(new URL('../../modules/Core/Http/Cors.php', import.meta.url), 'utf8');
  assert.equal(php.match(/'Access-Control-Expose-Headers' => '([^']*)'/)[1], CORS_EXPOSE);
  assert.match(CORS_EXPOSE, new RegExp(`(^|, )${WARNING_HEADER}($|,)`));
});

test('E5: concurrent writes to one key that drift the stored bytes are reset by the daily recount in the alarm', async () => {
  const { r2, used, bucket, installation, storage } = storageWorld(1000);
  // A file from before metering, an upload marker, and enough keys for several list pages.
  bucket.objects.set('installations/tenant-b/old.jpg', new Uint8Array(5));
  bucket.objects.set('installations/tenant-b/.mpu/abc', new Uint8Array(50));
  bucket.objects.set('installations/other/x.jpg', new Uint8Array(70));
  // Both writes read the old size before either is stored.
  const head = bucket.head.bind(bucket);
  let release;
  const both = new Promise((r) => { release = r; });
  let waiting = 0;
  bucket.head = async (key) => { const size = await head(key); if (++waiting === 2) release(); await both; return size; };
  await Promise.all([r2.fetch(put('same.jpg', '1234')), r2.fetch(put('same.jpg', '1234'))]);
  bucket.head = head;
  assert.equal(await used(), 8, 'both writes counted against the old size');
  await installation.alarm();
  assert.equal(await used(), 9, 'old.jpg and same.jpg; markers and other prefixes are not counted');
  assert.ok(bucket.lists >= 2, 'paged');
  assert.equal(storage.kv.get('storage_bytes').bytes, 9);
  assert.equal(storage.kv.get('storage_bytes').doName, 'tenant-b');
  // Within the day the alarm only writes.
  const lists = bucket.lists;
  await r2.fetch(put('b.jpg', '1'));
  await installation.alarm();
  assert.equal(bucket.lists, lists);
  assert.equal(storage.kv.get('storage_bytes').bytes, 10);
  // A day later it lists again.
  (await installation.stored.get()).counted -= RECOUNT_MS;
  bucket.objects.delete('installations/tenant-b/old.jpg');
  await installation.alarm();
  assert.equal(storage.kv.get('storage_bytes').bytes, 5);
  // An object evicted since carries on from storage (and its saved name).
  const again = new Installation({ storage }, installation.env);
  storage.kv.get('storage_bytes').counted = 0;
  await again.alarm();
  assert.ok(bucket.lists > lists + 1);
});

test('E5: a write that drift would refuse is recounted first, at most once an hour', async () => {
  const { r2, used, bucket, installation } = storageWorld(10);
  await installation.storageAdd(9, null, 'tenant-b');
  assert.equal((await r2.fetch(put('a.jpg', '123'))).status, 200, 'recounted: nothing is stored');
  assert.equal(await used(), 3);
  assert.equal(bucket.lists, 1);
  await installation.storageAdd(9, null, 'tenant-b');
  assert.equal((await r2.fetch(put('b.jpg', '123'))).status, 507, 'recounted under an hour ago');
  assert.equal(bucket.lists, 1);
  (await installation.stored.get()).counted -= RECOUNT_BEFORE_REFUSAL_MS;
  assert.equal((await r2.fetch(put('b.jpg', '123'))).status, 200);
  assert.equal(await used(), 6);
  // A failed listing leaves the refusal as it was.
  await installation.storageAdd(9, null, 'tenant-b');
  (await installation.stored.get()).counted = 0;
  bucket.list = async () => { throw new Error('list failed'); };
  assert.equal((await r2.fetch(put('c.jpg', '1'))).status, 507);
});

test('E5: primary, or an object without a name, is never listed', async () => {
  const { installation, bucket } = storageWorld(10);
  await installation.storageAdd(20, null, 'primary');
  assert.equal(await installation.storageAdd(1, 10, 'primary'), false);
  await installation.alarm();
  const named = new Installation({ id: { name: 'primary' }, storage: kvStorage() }, installation.env);
  await named.storageAdd(20, null, 'tenant-b');
  assert.equal(await named.storageAdd(1, 10, 'tenant-b'), false);
  await named.alarm();
  assert.equal(bucket.lists, 0);
});

test('E5: a multipart complete over an existing key counts the difference, and a delete never goes below zero', async () => {
  const { r2, used, bucket } = storageWorld(100);
  const marker = async (id, key) => {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(id));
    bucket.objects.set('installations/tenant-b/.mpu/' + Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join(''), new TextEncoder().encode(JSON.stringify({ id, key })));
  };
  const complete = (id) => new Request(`http://r2.internal/big.bin?action=complete&upload_id=${id}&parts=${encodeURIComponent('[{"partNumber":1,"etag":"e1"}]')}`, { method: 'PUT' });
  await marker('up1', 'installations/tenant-b/big.bin');
  assert.equal((await r2.fetch(complete('up1'))).status, 200);
  await marker('up2', 'installations/tenant-b/big.bin');
  assert.equal((await r2.fetch(complete('up2'))).status, 200);
  assert.equal(await used(), 6);
  // A file stored before metering is not in the counter: deleting it leaves zero.
  bucket.objects.set('installations/tenant-b/old.jpg', new Uint8Array(50));
  assert.equal((await r2.fetch(new Request('http://r2.internal/big.bin', { method: 'DELETE' }))).status, 200);
  assert.equal((await r2.fetch(new Request('http://r2.internal/old.jpg', { method: 'DELETE' }))).status, 200);
  assert.equal(await used(), 0);
});

test('E5: a counter sets one alarm for its first change, keeps an alarm already set, and sets one again after a flush', async () => {
  const storage = kvStorage();
  const c = new Counter(storage, 'k', () => ({ n: 0 }));
  (await c.get()).n = 1;
  await c.changed(1000);
  assert.equal(storage.alarm, 1000 + FLUSH_MS);
  await c.changed(2000);
  assert.equal(storage.alarm, 1000 + FLUSH_MS, 'a later change does not push the alarm out');
  storage.alarm = null;
  await c.flush();
  assert.deepEqual(storage.kv.get('k'), { n: 1 });
  (await c.get()).n = 2;
  await c.changed(5000);
  assert.equal(storage.alarm, 5000 + FLUSH_MS, 'clean again after the flush');
  await c.flush();
  assert.equal(storage.puts, 2);
  await c.flush();
  assert.equal(storage.puts, 2, 'nothing to write');
  // An alarm another counter set stays.
  const d = new Counter(storage, 'j', () => ({}));
  await d.get();
  await d.changed(9000);
  assert.equal(storage.alarm, 5000 + FLUSH_MS);
});

test('E5: LIMITS_ENABLED is on only for the exact string "1"', () => {
  assert.equal(limitsEnabled({ LIMITS_ENABLED: '1' }), true);
  for (const v of ['true', 'yes', ' 1', '1 ', '01', 1, true, undefined, '0']) assert.equal(limitsEnabled({ LIMITS_ENABLED: v }), false, String(v));
});

// Follow-ups to #321 (QA 2: L-2201, L-2202, N-2203, N-2205).

test('E5: a real preflight under /api is never refused: 204 without waking the container in a used-up month (L-2201, L-2206)', async () => {
  const usedUp = { period: period(Date.now()), requests: 5, containerMs: 3_600_000, lastActivity: 0, countedTo: 0 };
  const preflight = (path = '/api/v1/items', method = 'OPTIONS') => new Request(`https://b.example.test${path}`, { method,
    headers: { [LIMITS_HEADER]: JSON.stringify({ requests_month: { soft: 1, hard: 5 }, container_hours_month: { soft: 1, hard: 2 } }), Origin: 'https://app.example.test', 'Access-Control-Request-Method': 'POST' } });
  for (const usage of [usedUp, { ...usedUp, containerMs: 7_200_000 }]) {
    const storage = kvStorage();
    storage.kv.set('usage', structuredClone(usage));
    const { box, calls } = readyBox('tenant-b', { ...ON, ...ORIGINS }, storage);
    const res = await box.fetch(preflight());
    assert.equal(res.status, 204, JSON.stringify(usage));
    assert.equal(calls.length, 0);
    assert.equal(await res.text(), '');
    assert.equal(res.headers.get('access-control-allow-origin'), 'https://app.example.test');
    assert.equal(res.headers.get('access-control-allow-methods'), 'GET, POST, PATCH, PUT, DELETE');
    assert.equal(storage.alarm, null, 'nothing changed');
    assert.equal((await box.usage()).requests, usage.requests, 'not counted, like a refusal');
    // As the app reads the path: decoded once (N-2207).
    for (const path of ['/api', '/api/', '/%61pi/v1/items', '/api%2Fv1%2Fitems']) assert.equal((await box.fetch(preflight(path))).status, 204, path);
    assert.equal(calls.length, 0);
  }
  // Anything else in a used-up month is refused and not forwarded.
  const storage = kvStorage();
  storage.kv.set('usage', { ...usedUp, containerMs: 0 });
  const { box, calls } = readyBox('tenant-b', { ...ON, ...ORIGINS }, storage);
  for (const [path, method] of [['/apix', 'OPTIONS'], ['/import', 'OPTIONS'], ['/index.php/api/v1/items', 'OPTIONS'], ['/%2561pi/v1/items', 'OPTIONS'], ['//h/api/v1', 'OPTIONS'], ['/api/v1/items', 'GET'], ['/api/v1/items', 'POST']]) {
    assert.equal((await box.fetch(preflight(path, method))).status, 429, `${method} ${path}`);
  }
  assert.equal(calls.length, 0);
  assert.equal(isPreflight(new Request('https://b.example.test/api/v1/items', { method: 'options', headers: { Origin: 'https://app.example.test', 'Access-Control-Request-Method': 'GET' } }), ORIGINS.CORS_ALLOWED_ORIGINS), true);
});

test('E5: an OPTIONS that is not a real preflight is counted and refused like any request (L-2206)', async () => {
  const limits = { requests_month: { soft: 1, hard: 3 } };
  const options = (headers) => new Request('https://b.example.test/api/v1/items', { method: 'OPTIONS', headers: { [LIMITS_HEADER]: JSON.stringify(limits), ...headers } });
  const notPreflights = {
    'no Origin': { 'Access-Control-Request-Method': 'POST' },
    'no Access-Control-Request-Method': { Origin: 'https://app.example.test' },
    'an origin not listed': { Origin: 'https://other.example.test', 'Access-Control-Request-Method': 'POST' },
    'plain http': { Origin: 'http://plain.example.test', 'Access-Control-Request-Method': 'POST' },
  };
  for (const [why, headers] of Object.entries(notPreflights)) {
    const { box, calls } = readyBox('tenant-b', { ...ON, ...ORIGINS });
    for (let i = 0; i < 3; i++) assert.equal((await box.fetch(options(headers))).status, 200, why);
    assert.equal((await box.usage()).requests, 3, `${why}: counted`);
    assert.equal((await box.fetch(options(headers))).status, 429, `${why}: refused`);
    assert.equal(calls.length, 3, `${why}: the container is not woken once used up`);
  }
  // Without CORS_ALLOWED_ORIGINS no OPTIONS is a real preflight.
  const { box } = readyBox('tenant-b', ON);
  for (let i = 0; i < 3; i++) await box.fetch(options({ Origin: 'https://app.example.test', 'Access-Control-Request-Method': 'POST' }));
  assert.equal((await box.fetch(options({ Origin: 'https://app.example.test', 'Access-Control-Request-Method': 'POST' }))).status, 429);
});

test('E5: a real preflight that is forwarded is counted as a request and meters container time (L-2206)', async () => {
  const meter = new UsageMeter(kvStorage(), () => 15 * 60_000);
  const limits = { requests_month: { soft: 1, hard: 2 }, container_hours_month: { soft: 1, hard: 2 } };
  const t0 = Date.UTC(2026, 10, 2);
  assert.equal(await meter.admit(limits, t0, true), null);
  assert.equal(await meter.admit(limits, t0 + 60_000, true), null);
  assert.deepEqual(await meter.usage(t0 + 86_400_000), { period: '2026-11', requests: 2, container_seconds: 16 * 60 });
  assert.equal((await meter.admit(limits, t0 + 86_400_000)).status, 429);
  assert.equal((await meter.admit(limits, t0 + 86_400_000, true)).status, 204);
  assert.equal((await meter.usage(t0 + 86_400_000)).requests, 2, 'neither refusal nor 204 is counted');
});

test('E5: numeric-looking Content-Length values that are not digits only are not trusted (N-2203 M05, M11)', async () => {
  const lengths = [];
  globalThis.FixedLengthStream = class extends TransformStream { constructor(n) { super(); lengths.push(n); } };
  try {
    clearRouteCache();
    const w = worker(controlDb(), ON);
    const body = 'x'.repeat(5);
    for (const length of ['+5', '5.0', '1e3', '0x5', '5,5', '5 5']) {
      const { res } = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body, headers: { 'content-length': length } });
      assert.equal(res.status, 200, length);
      assert.equal(w.seen.at(-1).body, body);
      assert.equal(w.seen.at(-1).req.headers.get('content-length'), null, length);
    }
    assert.deepEqual(lengths, [], 'no FixedLengthStream for an untrusted length');
    // Digits only, leading zeros and surrounding spaces included (RFC 9110 1*DIGIT), are trusted.
    for (const length of ['05', ' 5 ', '0005']) {
      assert.equal((await w.go('https://b.example.test/api/v1/import', { method: 'POST', body, headers: { 'content-length': length } })).res.status, 200, length);
    }
    assert.deepEqual(lengths, [5, 5, 5]);
    // A huge digit string is over any limit: refused from the header, before anything is forwarded.
    const tried = w.seen.tried;
    const huge = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body, headers: { 'content-length': '9'.repeat(40) } });
    assert.equal(huge.res.status, 413);
    assert.equal(w.seen.tried, tried);
    // An over-limit body with an untrusted length is still capped while it streams.
    const over = 'x'.repeat(1572865);
    for (const length of ['+1', '1.0', '1e1']) {
      assert.equal((await w.go('https://b.example.test/api/v1/import', { method: 'POST', body: over, headers: { 'content-length': length } })).res.status, 413, length);
    }
  } finally {
    delete globalThis.FixedLengthStream;
  }
});

test('E5: a body longer or shorter than its valid Content-Length is a clean 400, with the CORS headers (N-2205)', async () => {
  clearRouteCache();
  const w = worker(controlDb(), { ...ON, ...ORIGINS });
  for (const length of ['3', '10', '0']) {
    const { res } = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body: '12345', headers: { 'content-length': length, Origin: 'https://app.example.test' } });
    assert.equal(res.status, 400, length);
    assert.equal(await res.text(), 'Request body does not match Content-Length\n');
    assert.deepEqual(corsOf(res), ALLOWED);
  }
  assert.equal(w.seen.length, 0, 'the app never got a whole body');
  const ok = await w.go('https://b.example.test/api/v1/import', { method: 'POST', body: '12345', headers: { 'content-length': '5' } });
  assert.equal(ok.res.status, 200);
  assert.equal(w.seen.at(-1).body, '12345');
  // A forward that fails for another reason is still an error.
  const failing = { DB: controlDb(), ...ON, APOTHEKE: { getByName: () => ({ fetch: async () => { throw new Error('down'); } }) } };
  clearRouteCache();
  await assert.rejects(publicWorker.fetch(new Request('https://b.example.test/api/v1/import', { method: 'POST', body: '12345', headers: { 'content-length': '5' } }), failing), /down/);
});

test('E5: CORS only on /api and /api/... as the app reads the path, decoded once: not /apix or /index.php/api (N-2203 M21, N-2205, N-2207)', () => {
  const configured = ORIGINS.CORS_ALLOWED_ORIGINS;
  const at = (path) => corsOf(withCors(new Response(null, { status: 429 }), new Request(`https://b.example.test${path}`, { headers: { Origin: 'https://app.example.test' } }), configured));
  for (const path of ['/api', '/api/', '/api/v1/items', '/%61pi/v1/items', '/%61%70%69', '/api%2Fv1', '/api%2fv1', '/api/%FF%fe']) assert.deepEqual(at(path), ALLOWED, path);
  for (const path of ['/apix', '/apix/v1', '/index.php/api/v1/items', '/API/v1/items', '/%41PI/v1', '/%2561pi/v1/items', '/api%', '/api%zz', '/', '/import']) assert.deepEqual(at(path), {}, path);
  // Documented differences, where PHP's parse_url() reads the path otherwise: the edge is under /api for a ':' and
  // digits segment (PHP: '/'), and not for a path that starts with '//' (PHP: the path after the first segment).
  assert.deepEqual(at('/api/a:1'), ALLOWED);
  assert.deepEqual(at('//h/api/v1'), {});
});

test('E5: the origins list is trimmed as PHP trim() does, not of every Unicode space (N-2205)', () => {
  const allowed = (configured) => withCors(new Response(null), new Request('https://b.example.test/api/v1/items', { headers: { Origin: 'https://app.example.test' } }), configured).headers.get('access-control-allow-origin');
  for (const sep of [' ', '\t', '\n', '\r', '\0', '\x0B', ' \t\r\n']) assert.equal(allowed(`${sep}https://app.example.test${sep},https://x.example.test`), 'https://app.example.test', JSON.stringify(sep));
  for (const sep of ['\u00a0', '\ufeff', '\u2028', '\u3000']) assert.equal(allowed(`${sep}https://app.example.test${sep}`), null, JSON.stringify(sep));
});

test('E5: the recount thresholds are a day in the alarm and an hour before a refusal (N-2203 M36, M37)', () => {
  assert.equal(RECOUNT_MS, 86_400_000);
  assert.equal(RECOUNT_BEFORE_REFUSAL_MS, 3_600_000);
});

test('E5: concurrent writes that drift would refuse share one recount listing (N-2203 M43)', async () => {
  const { r2, bucket, installation } = storageWorld(10);
  bucket.objects.set('installations/tenant-b/x.bin', new Uint8Array(9));
  await installation.storageAdd(9, null, 'tenant-b');
  const list = bucket.list.bind(bucket);
  bucket.list = async (o) => { await new Promise((r) => setTimeout(r, 20)); return list(o); };
  const statuses = await Promise.all([r2.fetch(put('a.jpg', '12')), r2.fetch(put('b.jpg', '12')), r2.fetch(put('c.jpg', '12'))]);
  assert.deepEqual(statuses.map((r) => r.status), [507, 507, 507]);
  assert.equal(bucket.lists, 1);
});

test('E5: the recount before a 507 is saved, even when the write is still refused (N-2203 M45)', async () => {
  const { r2, bucket, installation, storage } = storageWorld(10);
  bucket.objects.set('installations/tenant-b/x.bin', new Uint8Array(9));
  await installation.storageAdd(9, null, 'tenant-b');
  await installation.alarm();
  assert.equal(storage.kv.get('storage_bytes').bytes, 9);
  bucket.objects.set('installations/tenant-b/y.bin', new Uint8Array(1));
  (await installation.stored.get()).counted -= RECOUNT_BEFORE_REFUSAL_MS;
  storage.alarm = null;
  assert.equal((await r2.fetch(put('a.jpg', '12'))).status, 507);
  assert.notEqual(storage.alarm, null, 'the recount is a change');
  await installation.alarm();
  assert.equal(storage.kv.get('storage_bytes').bytes, 10);
});

test('E5: a failed recount waits like a successful one: an hour before a refusal, a day in the alarm (L-2202)', async () => {
  const { r2, bucket, installation, storage } = storageWorld(10);
  await installation.storageAdd(9, null, 'tenant-b');
  let tries = 0;
  bucket.list = async () => { tries += 1; throw new Error('list failed'); };
  for (let i = 0; i < 3; i++) assert.equal((await r2.fetch(put(`f${i}.jpg`, '12'))).status, 507);
  assert.equal(tries, 1, 'failed once, then backed off');
  const value = await installation.stored.get();
  assert.equal(value.counted, undefined);
  assert.ok(value.failed > 0);
  await installation.alarm();
  assert.equal(tries, 1, 'the alarm waits a day after a failure');
  assert.equal(storage.kv.get('storage_bytes').failed, value.failed, 'the failure is saved');
  value.failed -= RECOUNT_BEFORE_REFUSAL_MS;
  assert.equal((await r2.fetch(put('g.jpg', '12'))).status, 507);
  assert.equal(tries, 2, 'retried after an hour');
  value.failed -= RECOUNT_MS;
  await installation.alarm();
  assert.equal(tries, 3, 'retried in the alarm after a day');
  // Once a listing works again, the counter is reset and the write goes through.
  bucket.list = Bucket.prototype.list.bind(bucket);
  value.failed -= RECOUNT_BEFORE_REFUSAL_MS;
  assert.equal((await r2.fetch(put('h.jpg', '12'))).status, 200);
  assert.equal(value.bytes, 2);
});

// Follow-ups to #322 (QA 2: N-2209).

test('E5: a failed recount is a change, saved after a 507 and by the alarm (N-2209 M11)', async () => {
  const { r2, bucket, installation, storage } = storageWorld(10);
  bucket.objects.set('installations/tenant-b/x.bin', new Uint8Array(9));
  await installation.storageAdd(9, null, 'tenant-b');
  await installation.alarm();
  assert.equal(storage.kv.get('storage_bytes').bytes, 9);
  bucket.list = async () => { throw new Error('list failed'); };
  const value = await installation.stored.get();
  value.counted -= RECOUNT_BEFORE_REFUSAL_MS;
  storage.alarm = null;
  assert.equal((await r2.fetch(put('a.jpg', '12'))).status, 507);
  assert.notEqual(storage.alarm, null, 'the failure before a 507 is a change');
  assert.ok(value.failed > 0);
  await installation.alarm();
  assert.equal(storage.kv.get('storage_bytes').failed, value.failed, 'saved');
  // In the alarm, with nothing else changed.
  value.counted -= RECOUNT_MS;
  value.failed -= RECOUNT_MS;
  const before = value.failed;
  const puts = storage.puts;
  await installation.alarm();
  assert.ok(value.failed > before, 'tried again');
  assert.equal(storage.puts, puts + 1, 'the failure in the alarm is written');
  assert.equal(storage.kv.get('storage_bytes').failed, value.failed);
});

test('E5: an answer the app sends before a Content-Length mismatch is seen is passed on, not a 400 (N-2209 M19)', async () => {
  for (const length of ['3', '10']) {
    clearRouteCache();
    const early = { DB: controlDb(), ...ON, APOTHEKE: { getByName: () => ({ fetch: async () => new Response('early', { status: 401 }) }) } };
    const res = await publicWorker.fetch(new Request('https://b.example.test/api/v1/import', { method: 'POST', body: '12345', headers: { 'content-length': length } }), early);
    assert.equal(res.status, 401, length);
    assert.equal(await res.text(), 'early');
  }
  // An app that reads the body, sees it fail and still answers: the mismatch was seen, so the 400 wins.
  for (const length of ['3', '10']) {
    clearRouteCache();
    const reads = { DB: controlDb(), ...ON, APOTHEKE: { getByName: () => ({ fetch: async (req) => { await new Response(req.body).text().catch(() => {}); return new Response('late'); } }) } };
    const res = await publicWorker.fetch(new Request('https://b.example.test/api/v1/import', { method: 'POST', body: '12345', headers: { 'content-length': length } }), reads);
    assert.equal(res.status, 400, length);
  }
});
