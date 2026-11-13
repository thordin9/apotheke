// Operator console C1: the Installation object's read-only GET /stats, /users and /limits (edge/src/bridge.ts).
// Numbers and account metadata only, from the object's own database; /limits from the control plane for the object's
// own name. Loaded by r2.test.mjs, which registers the cloudflare:workers stub, so CI's existing node --test runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
const { doStorage } = await import(new URL('./do-storage.mjs', import.meta.url).href);
const { D1Outbound, Installation } = await import('../src/index.ts');

const bucket = () => ({ head: async () => null });

/** The control migrations on SQLite behind D1's prepare().bind().all(), with two tenants on a test plan. */
function controlDb() {
  const db = new DatabaseSync(':memory:');
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  db.exec(`INSERT INTO plans (id, name) VALUES ('test-plan', 'Test plan');
    INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES ('test-plan', 'items', 40, 50), ('test-plan', 'users', NULL, 3);
    INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('a', 'tenant-a', 'installations/tenant-a/', 'active'),
      ('b', 'tenant-b', 'installations/tenant-b/', 'active'), ('c', 'tenant-c', 'installations/tenant-c/', 'active');
    INSERT INTO subscriptions (installation_id, plan_id, status) VALUES ('a', 'test-plan', 'active'), ('b', 'internal', 'trial');`);
  return { raw: db, prepare: (sql) => ({ bind: (...v) => ({ all: async () => ({ results: db.prepare(sql).all(...v).map((r) => ({ ...r })) }) }) }) };
}

/** An installation database with the three tables the reads count. */
function tenantStorage(items, photos, users) {
  const s = doStorage();
  s.db.exec(`CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT);
    CREATE TABLE item_photos (id INTEGER PRIMARY KEY, item_id INTEGER, file TEXT);
    CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, username_key TEXT, password_hash TEXT, role TEXT, status TEXT,
      created_at TEXT, last_login_at TEXT, session_epoch TEXT)`);
  for (let i = 1; i <= items; i++) s.db.prepare('INSERT INTO items (name) VALUES (?)').run(`item ${i}`);
  for (let i = 1; i <= photos; i++) s.db.prepare('INSERT INTO item_photos (item_id, file) VALUES (1, ?)').run(`p${i}.jpg`);
  for (const [name, last] of users) {
    s.db.prepare("INSERT INTO users (username, username_key, password_hash, role, status, created_at, last_login_at, session_epoch) VALUES (?, ?, ?, 'editor', 'active', '2026-01-01 00:00:00', ?, ?)")
      .run(name, name, `hash-of-${name}`, last, `epoch-of-${name}`);
  }
  return s;
}

const recent = () => new Date(Date.now() - 86_400_000).toISOString().slice(0, 19).replace('T', ' ');
const env = (control) => ({ STORAGE: bucket(), DB: control, INSTALLATION: {} });
const get = (path) => new Request(`http://d1.internal${path}`);
async function read(target, path) { const r = await target.fetch(get(path)); return [r.status, await r.json()]; }

function setup() {
  const control = controlDb();
  const stores = {
    'tenant-a': tenantStorage(3, 2, [['ann', recent()], ['old', '2020-01-01 00:00:00'], ['new', null]]),
    'tenant-b': tenantStorage(7, 0, [['bob', recent()]]),
  };
  const objects = new Map();
  const eu = {
    idFromName: (name) => ({ name }),
    get: (id) => {
      if (!objects.has(id.name)) objects.set(id.name, new Installation({ storage: stores[id.name] ?? tenantStorage(0, 0, []), id }, env(control)));
      return { fetch: (req) => objects.get(id.name).fetch(req) };
    },
  };
  const ns = { jurisdiction: () => eu };
  const outbound = (doName) => new D1Outbound({ props: { doName, prefix: `installations/${doName}/` } }, { ...env(control), INSTALLATION: ns });
  return { control, stores, outbound };
}

test('GET /stats answers fixed counts of the installation\'s own rows', async () => {
  const { outbound } = setup();
  assert.deepEqual(await read(outbound('tenant-a'), '/stats'), [200, { ok: true, stats: { items: 3, photos: 2, users: 3, users_active_30d: 1 } }]);
  assert.deepEqual(await read(outbound('tenant-b'), '/stats'), [200, { ok: true, stats: { items: 7, photos: 0, users: 1, users_active_30d: 1 } }]);
});

test('GET /users answers account metadata only, never hashes or session epochs', async () => {
  const { outbound } = setup();
  const [status, body] = await read(outbound('tenant-a'), '/users');
  assert.equal(status, 200);
  assert.deepEqual(body.users.map((u) => Object.keys(u).sort()), Array(3).fill(['created_at', 'id', 'last_login_at', 'role', 'status', 'username']));
  assert.deepEqual(body.users.map((u) => u.username), ['ann', 'old', 'new']);
  assert.equal(body.users[2].last_login_at, null);
  const text = JSON.stringify(body);
  assert.doesNotMatch(text, /hash-of-|epoch-of-/);
  assert.deepEqual((await read(outbound('tenant-b'), '/users'))[1].users.map((u) => u.username), ['bob']);
});

test('GET /limits answers the plan of the object\'s own installation from the control plane', async () => {
  const { outbound } = setup();
  assert.deepEqual(await read(outbound('tenant-a'), '/limits'), [200, { ok: true, plan: 'test-plan', limits: { items: { soft: 40, hard: 50 }, users: { soft: null, hard: 3 } } }]);
  // Plan internal has no limit rows: no limits.
  assert.deepEqual(await read(outbound('tenant-b'), '/limits'), [200, { ok: true, plan: 'internal', limits: {} }]);
  // No subscription row, or no installation row: refused, so the app treats its limits as unavailable.
  assert.equal((await read(outbound('tenant-c'), '/limits'))[0], 404);
  assert.equal((await read(outbound('tenant-x'), '/limits'))[0], 404);
});

test('a query string or another host on d1.internal cannot pick another installation', async () => {
  const { outbound } = setup();
  const r = await outbound('tenant-b').fetch(new Request('http://d1.internal/limits?doName=tenant-a&installation=a'));
  assert.deepEqual(await r.json(), { ok: true, plan: 'internal', limits: {} });
  const s = await outbound('tenant-b').fetch(new Request('http://d1.internal/stats', { headers: { 'x-installation': 'tenant-a' } }));
  assert.equal((await s.json()).stats.items, 7);
});

test('/limits without the control plane or without an object name is unavailable', async () => {
  const noDb = new Installation({ storage: tenantStorage(1, 0, []), id: { name: 'tenant-a' } }, { STORAGE: bucket(), DB: undefined, INSTALLATION: {} });
  // storageSelected needs DB too: without it the object answers unbound for every path, as before.
  assert.equal((await noDb.fetch(get('/limits'))).status, 503);
  const { control } = setup();
  const unnamed = new Installation({ storage: tenantStorage(1, 0, []) }, env(control));
  const [status, body] = await read(unnamed, '/limits');
  assert.equal(status, 503);
  assert.equal(body.ok, false);
  // The counts need no name: they are the object's own database.
  assert.equal((await read(unnamed, '/stats'))[1].stats.items, 1);
});

test('reads stay closed outside storage mode, and other methods and paths answer as before', async () => {
  const { control } = setup();
  const off = new Installation({ storage: tenantStorage(1, 0, []), id: { name: 'tenant-a' } }, { DB: control, INSTALLATION: {} });
  for (const p of ['/stats', '/users', '/limits']) assert.equal((await off.fetch(get(p))).status, 503, p);
  const on = new Installation({ storage: tenantStorage(1, 0, []), id: { name: 'tenant-a' } }, env(control));
  assert.equal((await on.fetch(get('/query'))).status, 405);
  assert.equal((await on.fetch(get('/stats/'))).status, 405);
  const post = await on.fetch(new Request('http://d1.internal/stats', { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{}' }));
  assert.deepEqual([post.status, (await post.json()).code], [404, 'not_found']);
  const q = await on.fetch(new Request('http://d1.internal/query', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ sql: 'SELECT COUNT(*) AS n FROM items' }) }));
  assert.deepEqual((await q.json()).rows, [{ n: 1 }]);
});

test('a failed read answers 503 with no SQL text or values', async () => {
  const { control } = setup();
  const bare = doStorage();
  const o = new Installation({ storage: bare, id: { name: 'tenant-a' } }, env(control));
  const r = await o.fetch(get('/stats'));
  assert.equal(r.status, 503);
  const body = await r.json();
  assert.equal(body.ok, false);
  assert.doesNotMatch(JSON.stringify(body), /SELECT|COUNT|strftime/);
});
