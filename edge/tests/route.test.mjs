// Host routing (E2): installation_routes in the control-plane D1, with primary as the fallback.
// Loaded by r2.test.mjs, which registers the cloudflare:workers stub, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';

const { default: publicWorker } = await import('../src/index.ts');
const { clearRouteCache, normalizeHost, routeFor } = await import('../src/route.ts');

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

test('E2: a failed D1 lookup or no DB binding falls back to primary and is not cached', async () => {
  clearRouteCache();
  for (const DB of [failingDb, undefined]) {
    const w = worker(DB);
    assert.equal(await (await w.go('https://api.services.getapotheke.com/')).text(), 'app');
    assert.deepEqual(w.seen, ['primary']);
  }
  const db = controlDb();
  await routeFor('api.services.getapotheke.com', db);
  assert.equal(db.queries.length, 1);
});

test('E2: a non-active installation never wakes a container; another DO name waits for its own storage', async () => {
  clearRouteCache();
  const db = controlDb();
  db.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'b', 'installations/b/', 'suspended');
    INSERT INTO installation_routes (host, installation_id) VALUES ('b.example.test', 'b');`);
  const w = worker(db);
  assert.equal((await w.go('https://b.example.test/')).status, 402);
  for (const [status, code] of [['provisioning', 503], ['deleting', 410], ['deleted', 410], ['active', 503]]) {
    clearRouteCache();
    db.raw.exec(`UPDATE installations SET status = '${status}' WHERE id = 'b'`);
    const res = await w.go('https://b.example.test/');
    assert.equal(res.status, code, status);
    assert.equal(res.headers.get('cache-control'), 'no-store');
  }
  assert.deepEqual(w.seen, []);
});

test('E2: hosts are normalised before the lookup', () => {
  assert.equal(normalizeHost('API.Services.getapotheke.com:8443'), 'api.services.getapotheke.com');
  assert.equal(normalizeHost('a.example.test.'), 'a.example.test');
});
