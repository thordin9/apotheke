// Plan limits in the Worker (operator console E5): requests, container active time, request body size and stored
// bytes, from plan_limits through the installation's subscription. Off unless LIMITS_ENABLED is "1"; primary never.
// Loaded by r2.test.mjs, which registers the cloudflare:workers stub, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';

const { default: publicWorker, ApothekeContainer, R2Outbound, Installation } = await import('../src/index.ts');
const { clearRouteCache, routeFor } = await import('../src/route.ts');
const { FLUSH_MS, LIMITS_HEADER, WARNING_HEADER, UsageMeter, parseLimits, period } = await import('../src/limits.ts');
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
  const env = { DB, ...vars, APOTHEKE: { getByName: (n) => ({ fetch: async (req) => { seen.push({ n, req, body: req.body ? await new Response(req.body).text() : null }); return new Response('app'); } }) } };
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
function readyBox(name, vars, storage = kvStorage()) {
  const calls = [];
  const wired = [];
  const container = {
    running: true,
    start() { throw new Error('already running'); },
    setInactivityTimeout: async () => {},
    interceptOutboundHttp: async () => {},
    getTcpPort: () => ({ fetch: async (input) => { calls.push(input); return new Response('ok'); } }),
  };
  const ctx = { id: { name }, container, storage, blockConcurrencyWhile: (f) => f(), waitUntil() {},
    exports: { D1Outbound: () => ({}), R2Outbound: (o) => { wired.push(o.props); return {}; } } };
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

test('E5: off, the container object passes a request through untouched and counts nothing', async () => {
  const { box, calls, wired, storage } = readyBox('tenant-b', OFF);
  for (let i = 0; i < 3; i++) assert.equal((await box.fetch(limited({ requests_month: { soft: 0, hard: 0 } }))).status, 200);
  assert.equal(calls[0].headers.get(LIMITS_HEADER), '{"requests_month":{"soft":0,"hard":0}}');
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
  return { r2: new R2Outbound({ props }, env), installation, bucket: env.STORAGE, used: async () => (await installation.stored?.get())?.bytes ?? 0 };
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
