// Host routing (E2): installation_routes in the control-plane D1, with primary as the fallback.
// Loaded by r2.test.mjs, which registers the cloudflare:workers stub, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';

const { default: publicWorker } = await import('../src/index.ts');
const { cachedHosts, clearRouteCache, FAILURE_TTL_MS, normalizeHost, ROUTE_CACHE_MAX, routeFor } = await import('../src/route.ts');

/** The control migrations on a real SQLite behind D1's prepare().bind().first(). */
function controlDb() {
  const db = new DatabaseSync(':memory:');
  db.exec('PRAGMA foreign_keys = ON');
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  const queries = [];
  return {
    raw: db, queries,
    prepare: (sql) => ({ bind: (...v) => ({ first: async () => { queries.push(v); const r = db.prepare(sql).get(...v); return r ? { ...r } : null; } }) }),
  };
}
const failingDb = { prepare: () => { throw new Error('D1_ERROR: unavailable'); } };

function worker(DB) {
  const seen = [];
  const env = { DB, APOTHEKE: { getByName: (n) => ({ fetch: async () => { seen.push(n); return new Response('app'); } }) } };
  return { seen, go: async (url) => publicWorker.fetch(new Request(url), env) };
}

test('E2: a route hit forwards to the row\'s DO name; primary stays primary', async () => {
  clearRouteCache();
  const db = controlDb();
  const w = worker(db);
  const res = await w.go('https://API.services.getapotheke.com:443/api/v1/items');
  assert.equal(await res.text(), 'app');
  assert.deepEqual(w.seen, ['primary']);
  assert.deepEqual(db.queries, [['api.services.getapotheke.com']]);
});

test('E2: a route miss falls back to primary, and the miss is cached', async () => {
  clearRouteCache();
  const db = controlDb();
  const w = worker(db);
  for (let i = 0; i < 3; i++) assert.equal(await (await w.go('https://unknown.example.test/')).text(), 'app');
  assert.deepEqual(w.seen, ['primary', 'primary', 'primary']);
  assert.equal(db.queries.length, 1);
  assert.deepEqual(await routeFor('unknown.example.test', db, Date.now() + 31_000), { doName: 'primary', status: 'active' });
  assert.equal(db.queries.length, 2);
});

test('E2: a failed D1 lookup or no DB binding falls back to primary', async () => {
  clearRouteCache();
  for (const DB of [failingDb, undefined]) {
    const w = worker(DB);
    assert.equal(await (await w.go('https://api.services.getapotheke.com/')).text(), 'app');
    assert.deepEqual(w.seen, ['primary']);
  }
});

test('E3a: missing control tables fall back to primary, log once, and are retried after FAILURE_TTL_MS', async (t) => {
  const logged = t.mock.method(console, 'error', () => {});
  for (const table of ['installation_routes', 'installations']) {
    clearRouteCache();
    logged.mock.resetCalls();
    const db = new DatabaseSync(':memory:');
    if (table === 'installations') db.exec('CREATE TABLE installation_routes (host TEXT, installation_id TEXT)');
    let calls = 0;
    // D1 rejects first() with the SQLite error, like a database the control migrations never ran on.
    const DB = { prepare: (sql) => ({ bind: (...v) => ({ first: async () => { calls += 1; return db.prepare(sql).get(...v) ?? null; } }) }) };
    const w = worker(DB);
    for (let i = 0; i < 20; i++) assert.equal((await w.go('https://b.example.test/')).status, 200);
    assert.deepEqual([...new Set(w.seen)], ['primary']);
    assert.equal(calls, 1, `${table}: one D1 query, not one per request`);
    assert.equal(logged.mock.callCount(), 1);
    const line = logged.mock.calls[0].arguments.join(' ');
    assert.match(line, new RegExp(`no such table: ${table}`));
    assert.doesNotMatch(line, /example\.test/);
    const t0 = 1e15;
    assert.deepEqual(await routeFor('c.example.test', DB, t0), { doName: 'primary', status: 'active' });
    assert.deepEqual(await routeFor('c.example.test', DB, t0 + FAILURE_TTL_MS - 1), { doName: 'primary', status: 'active' });
    assert.equal(calls, 2);
    await routeFor('c.example.test', DB, t0 + FAILURE_TTL_MS);
    assert.equal(calls, 3, 'retried once the failure expires');
  }
  // Once the migrations are applied, the host routes as soon as the failure expires.
  clearRouteCache();
  const db = controlDb();
  assert.deepEqual(await routeFor('api.services.getapotheke.com', db), { doName: 'primary', status: 'active' });
  assert.equal(db.queries.length, 1);
});

test('E2: a non-active installation never wakes a container; an active one is served by its own DO name', async () => {
  clearRouteCache();
  const db = controlDb();
  db.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'b', 'installations/b/', 'suspended');
    INSERT INTO installation_routes (host, installation_id) VALUES ('b.example.test', 'b');`);
  const w = worker(db);
  assert.equal((await w.go('https://b.example.test/')).status, 402);
  for (const [status, code] of [['provisioning', 503], ['deleting', 410], ['deleted', 410]]) {
    clearRouteCache();
    db.raw.exec(`UPDATE installations SET status = '${status}' WHERE id = 'b'`);
    const res = await w.go('https://b.example.test/');
    assert.equal(res.status, code, status);
    assert.equal(res.headers.get('cache-control'), 'no-store');
  }
  assert.deepEqual(w.seen, []);
  clearRouteCache();
  db.raw.exec(`UPDATE installations SET status = 'active' WHERE id = 'b'`);
  assert.equal(await (await w.go('https://b.example.test/')).text(), 'app');
  assert.deepEqual(w.seen, ['b']);
});

test('E2: primary is never status-gated, whatever its row says', async () => {
  const db = controlDb();
  const w = worker(db);
  for (const status of ['suspended', 'deleting', 'deleted', 'provisioning']) {
    clearRouteCache();
    db.raw.exec(`UPDATE installations SET status = '${status}' WHERE id = 'primary'`);
    const res = await w.go('https://api.services.getapotheke.com/');
    assert.equal(res.status, 200, status);
    assert.equal(await res.text(), 'app');
  }
  assert.deepEqual(w.seen, ['primary', 'primary', 'primary', 'primary']);
});

test('E2: hosts are normalised before the lookup', () => {
  assert.equal(normalizeHost('API.Services.getapotheke.com:8443'), 'api.services.getapotheke.com');
  assert.equal(normalizeHost('a.example.test.'), 'a.example.test');
  assert.equal(normalizeHost('b.example.test..'), 'b.example.test');
  assert.equal(normalizeHost('B.Example.Test..:443'), 'b.example.test');
});

test('E2: the route cache is capped: expired entries go first, then the oldest', async () => {
  clearRouteCache();
  const db = controlDb();
  const t0 = 1_000_000;
  for (let i = 0; i < ROUTE_CACHE_MAX; i++) await routeFor(`h${i}.example.test`, db, t0 + i);
  assert.equal(cachedHosts().length, ROUTE_CACHE_MAX);
  // Full and nothing expired: the oldest goes.
  await routeFor('new1.example.test', db, t0 + ROUTE_CACHE_MAX);
  assert.equal(cachedHosts().length, ROUTE_CACHE_MAX);
  assert.equal(cachedHosts()[0], 'h1.example.test');
  assert.equal(cachedHosts().at(-1), 'new1.example.test');
  // 30 s on, the first 10 entries have expired: all of them go, and nothing newer.
  await routeFor('new2.example.test', db, t0 + 30_000 + 10);
  assert.equal(cachedHosts().length, ROUTE_CACHE_MAX - 9);
  assert.equal(cachedHosts()[0], 'h11.example.test');
  clearRouteCache();
});

test('E2: status pages are plain, fixed text that names nothing about the installation', async () => {
  clearRouteCache();
  const db = controlDb();
  db.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status, owner_contact, notes)
      VALUES ('inst-hidden-id', 'tenant-hidden', 'installations/tenant-hidden/', 'suspended', 'owner@example.test', 'operator note');
    INSERT INTO installation_routes (host, installation_id) VALUES ('hidden.example.test', 'inst-hidden-id');`);
  const w = worker(db);
  for (const [status, code, retry] of [['provisioning', 503, '30'], ['suspended', 402, null], ['deleting', 410, null], ['deleted', 410, null]]) {
    clearRouteCache();
    db.raw.exec(`UPDATE installations SET status = '${status}' WHERE id = 'inst-hidden-id'`);
    const res = await w.go('https://hidden.example.test..:8443/items/1?q=x');
    assert.equal(res.status, code, status);
    assert.equal(res.headers.get('retry-after'), retry, status);
    assert.equal(res.headers.get('content-type'), 'text/plain; charset=utf-8');
    const body = await res.text();
    for (const leak of ['inst-hidden-id', 'tenant-hidden', 'installations/', 'owner@', 'operator note', 'hidden.example', 'items', status]) {
      assert.ok(!body.toLowerCase().includes(leak), `${status} body names ${leak}`);
    }
  }
  assert.deepEqual(w.seen, []);
});

test('E2: X-Forwarded-Host is ignored; only the request URL host is routed', async () => {
  clearRouteCache();
  const db = controlDb();
  db.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'tenant-b', 'installations/tenant-b/', 'suspended');
    INSERT INTO installation_routes (host, installation_id) VALUES ('b.example.test', 'b');`);
  const seen = [];
  const env = { DB: db, APOTHEKE: { getByName: (n) => ({ fetch: async () => { seen.push(n); return new Response('app'); } }) } };
  const spoofed = new Request('https://api.services.getapotheke.com/', { headers: { 'X-Forwarded-Host': 'b.example.test', Host: 'b.example.test' } });
  assert.equal(await (await publicWorker.fetch(spoofed, env)).text(), 'app');
  assert.deepEqual(seen, ['primary']);
  assert.deepEqual(db.queries, [['api.services.getapotheke.com']]);
});
