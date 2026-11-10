// P4-5 piece 2: the Installation Durable Object holds the installation's database; d1.internal reaches it only
// while the storage mode is selected (STORAGE, DB and INSTALLATION all bound). Loaded by r2.test.mjs, which
// registers the cloudflare:workers stub, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';

const { D1Outbound, Installation, ApothekeContainer, default: publicWorker } = await import('../src/index.ts');
const { installationStub, INSTALLATION_NAME } = await import('../src/installation.ts');

/** Durable Object storage over a real SQLite: exec refuses transaction statements, transactionSync rolls back. */
function doStorage() {
  const db = new DatabaseSync(':memory:');
  const storage = {
    db,
    sql: {
      exec(sql, ...params) {
        if (/^\s*(BEGIN|COMMIT|END|ROLLBACK|SAVEPOINT|RELEASE)\b/i.test(sql)) throw new Error('transaction statements are not allowed');
        if (params.length === 0 && /;\s*\S/.test(sql.trim().replace(/;\s*$/, ''))) { db.exec(sql); return { toArray: () => [] }; }
        const rows = db.prepare(sql).all(...params).map((r) => ({ ...r }));
        return { toArray: () => rows };
      },
    },
    transactionSync(fn) {
      db.exec('BEGIN');
      try { const r = fn(); db.exec('COMMIT'); return r; } catch (e) { db.exec('ROLLBACK'); throw e; }
    },
  };
  return storage;
}

/** A namespace whose root refuses id creation: ids may only come from jurisdiction("eu"). */
function namespace(storage, calls = []) {
  const objects = new Map();
  const eu = {
    idFromName: (name) => { calls.push(['eu.idFromName', name]); return { name }; },
    get: (id) => {
      calls.push(['eu.get', id.name]);
      if (!objects.has(id.name)) objects.set(id.name, new Installation({ storage }, fullEnv()));
      return { fetch: (req) => objects.get(id.name).fetch(req) };
    },
  };
  const refuse = (what) => () => { throw new Error(`${what} on the root namespace`); };
  return {
    jurisdiction: (j) => { calls.push(['jurisdiction', j]); if (j !== 'eu') throw new Error('not eu'); return eu; },
    idFromName: refuse('idFromName'), newUniqueId: refuse('newUniqueId'), idFromString: refuse('idFromString'), get: refuse('get'), getByName: refuse('getByName'),
  };
}
const bucket = () => ({ head: async () => null });
function fullEnv(over = {}) { return { STORAGE: bucket(), DB: {}, INSTALLATION: {}, ...over }; }
const untouchable = new Proxy({}, { get() { throw new Error('must not be used'); } });

function d1(path, body) {
  return new Request(`http://d1.internal${path}`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) });
}
async function post(target, path, body) { const r = await target.fetch(d1(path, body)); return [r.status, await r.json()]; }

function setup() {
  const storage = doStorage();
  const calls = [];
  const env = fullEnv({ INSTALLATION: namespace(storage, calls) });
  return { storage, calls, env, outbound: new D1Outbound({}, env) };
}
const SCHEMA = 'CREATE TABLE uow_guard (ok INTEGER NOT NULL); CREATE TABLE item (id INTEGER PRIMARY KEY, qty INTEGER NOT NULL, version INTEGER NOT NULL);';

test('P4-5b: every Installation id comes from the EU jurisdiction, by the Worker-chosen name', async () => {
  const calls = [];
  installationStub(namespace(doStorage(), calls));
  assert.deepEqual(calls, [['jurisdiction', 'eu'], ['eu.idFromName', INSTALLATION_NAME], ['eu.get', INSTALLATION_NAME]]);
  assert.equal(INSTALLATION_NAME, 'primary', 'matches the container object and the R2 prefix installations/primary/');
});

test('P4-5b: no other edge/src code creates a Durable Object id', () => {
  const dir = new URL('../src/', import.meta.url);
  const found = [];
  for (const f of readdirSync(dir)) {
    const src = readFileSync(new URL(f, dir), 'utf8');
    for (const m of src.matchAll(/([\w.]*)\.(idFromName|newUniqueId|idFromString|getByName)\(/g)) found.push(`${f}:${m[1]}.${m[2]}`);
  }
  assert.deepEqual(found.sort(), ['index.ts:env.APOTHEKE.getByName', 'installation.ts:eu.idFromName']);
});

test('P4-5b: in storage mode d1.internal is the Installation SQLite, and its data outlives the container', async () => {
  const { storage, outbound, env } = setup();
  assert.deepEqual(await post(outbound, '/exec', { sql: SCHEMA }), [200, { ok: true, count: 1 }]);
  assert.equal((await post(outbound, '/query', { sql: 'INSERT INTO item (id, qty, version) VALUES (?, ?, ?)', params: [1, 5, 1] }))[0], 200);
  // A new outbound and a new object on the same storage (container slept, object evicted) still see the row.
  const again = new D1Outbound({}, fullEnv({ INSTALLATION: namespace(storage) }));
  assert.deepEqual(await post(again, '/query', { sql: 'SELECT qty FROM item WHERE id = ?', params: [1] }), [200, { ok: true, rows: [{ qty: 5 }] }]);
  const [status, body] = await post(outbound, '/batch', [{ sql: 'UPDATE item SET qty = qty + 1 WHERE id = ?', params: [1] }, { sql: 'SELECT qty FROM item' }]);
  assert.equal(status, 200);
  assert.deepEqual(body.results.map((r) => r.rows), [[], [{ qty: 6 }]]);
  assert.ok(env.INSTALLATION);
});

test('P4-5b: a batch is all or nothing, and a guard failure returns only the marker', async () => {
  const { storage, outbound } = setup();
  await post(outbound, '/exec', { sql: SCHEMA + 'INSERT INTO item VALUES (1, 5, 1);' });
  const original = console.error;
  console.error = () => {};
  try {
    const [s1, b1] = await post(outbound, '/batch', [{ sql: 'UPDATE item SET qty = 99 WHERE id = 1' }, { sql: 'INSERT INTO nope VALUES (1)' }]);
    assert.equal(s1, 500);
    assert.deepEqual(b1, { ok: false, code: 'write_failed', error: 'the batch' });
    const [s2, b2] = await post(outbound, '/batch', [
      { sql: 'UPDATE item SET qty = 7 WHERE id = 1 AND version = ?', params: [9] },
      { sql: 'INSERT INTO uow_guard (ok) SELECT NULL WHERE changes() <> ?', params: [1] },
    ]);
    assert.equal(s2, 500);
    assert.deepEqual(b2, { ok: false, code: 'guard_failed', error: 'NOT NULL constraint failed: uow_guard.ok' });
  } finally { console.error = original; }
  assert.deepEqual(storage.db.prepare('SELECT qty, version FROM item').all().map((r) => ({ ...r })), [{ qty: 5, version: 1 }]);
});

/** Requests whose bodies arrive only after the gate opens, so every caller is in flight before any batch runs. */
function gatedRequests(bodies) {
  let open;
  const gate = new Promise((r) => { open = r; });
  const requests = bodies.map((body) => new Request('http://d1.internal/batch', {
    method: 'POST', duplex: 'half',
    body: new ReadableStream({ async pull(c) { await gate; c.enqueue(new TextEncoder().encode(JSON.stringify(body))); c.close(); } }),
  }));
  return { requests, open: () => open() };
}

test('P4-5b: concurrent compare-and-swap batches on one version: exactly one wins, the rest fail their guard', async () => {
  const { storage, outbound } = setup();
  await post(outbound, '/exec', { sql: SCHEMA + 'INSERT INTO item VALUES (1, 5, 1);' });
  const cas = (qty) => [
    { sql: 'UPDATE item SET qty = ?, version = version + 1 WHERE id = 1 AND version = ?', params: [qty, 1] },
    { sql: 'INSERT INTO uow_guard (ok) SELECT NULL WHERE changes() <> ?', params: [1] },
  ];
  const { requests, open } = gatedRequests([10, 20, 30, 40, 50, 60, 70, 80].map(cas));
  const original = console.error;
  console.error = () => {};
  let answers;
  try {
    const pending = requests.map((r) => outbound.fetch(r));
    open();
    answers = await Promise.all(pending.map(async (p) => { const r = await p; return [r.status, (await r.json()).code ?? 'ok']; }));
  } finally { console.error = original; }
  assert.equal(answers.filter(([s]) => s === 200).length, 1, JSON.stringify(answers));
  assert.equal(answers.filter(([, c]) => c === 'guard_failed').length, 7);
  const [row] = storage.db.prepare('SELECT qty, version FROM item').all();
  assert.equal(row.version, 2);
  assert.ok([10, 20, 30, 40, 50, 60, 70, 80].includes(row.qty));
  assert.equal(storage.db.prepare('SELECT COUNT(*) n FROM uow_guard').get().n, 0, 'a failed guard leaves no row');
});

test('P4-5b: concurrent relative writes all land (no lost update)', async () => {
  const { storage, outbound } = setup();
  await post(outbound, '/exec', { sql: SCHEMA + 'INSERT INTO item VALUES (1, 0, 1);' });
  const { requests, open } = gatedRequests(Array.from({ length: 25 }, () => [{ sql: 'UPDATE item SET qty = qty + 1 WHERE id = 1' }]));
  const pending = requests.map((r) => outbound.fetch(r));
  open();
  assert.deepEqual((await Promise.all(pending)).map((r) => r.status), Array(25).fill(200));
  assert.equal(storage.db.prepare('SELECT qty FROM item').get().qty, 25);
});

test('P4-5b: d1.internal is closed unless STORAGE, DB and INSTALLATION are all bound', async () => {
  for (const env of [{}, { DB: untouchable }, { INSTALLATION: untouchable }, { DB: untouchable, INSTALLATION: untouchable }, { STORAGE: untouchable, INSTALLATION: untouchable }, { STORAGE: untouchable, DB: untouchable }, { DB: untouchable, STORAGE_BACKEND: 'r2' }]) {
    const res = await new D1Outbound({}, env).fetch(d1('/query', { sql: 'SELECT 1' }));
    assert.equal(res.status, 503, Object.keys(env).join());
    assert.deepEqual(await res.json(), { ok: false, code: 'unbound', error: 'D1 is not bound' });
  }
});

test('P4-5b: the Installation object itself refuses outside storage mode and leaves its SQLite alone', async () => {
  for (const env of [{}, { STORAGE: bucket() }, { STORAGE: bucket(), DB: {} }, { DB: {}, INSTALLATION: {} }]) {
    const storage = { sql: untouchable, transactionSync: () => { throw new Error('must not run'); } };
    const res = await new Installation({ storage }, env).fetch(d1('/query', { sql: 'SELECT 1' }));
    assert.equal(res.status, 503);
  }
});

test('P4-5b: the container starts only with the whole fragment, and PHP is not yet pointed at d1.internal', async () => {
  const start = async (env) => {
    let started;
    const container = { running: false, start(o) { started = o; this.running = true; }, setInactivityTimeout: async () => {}, monitor: () => new Promise(() => {}), getTcpPort: () => ({ fetch: async () => new Response('ok') }), interceptOutboundHttp: async () => {} };
    const ctx = { container, waitUntil() {}, blockConcurrencyWhile: (f) => f(), exports: { D1Outbound: () => ({}), R2Outbound: () => ({}) } };
    const original = console.error;
    console.error = () => {};
    try { return [(await new ApothekeContainer(ctx, { APP_PASSWORD: 'p', APP_KEY: 'k', ...env }).fetch(new Request('https://api.example.test/healthz'))).status, started]; } finally { console.error = original; }
  };
  const [ok, full] = await start(fullEnv());
  assert.equal(ok, 200);
  assert.equal(full.env.STORAGE_BACKEND, 'r2');
  assert.equal(full.env.DB_DRIVER, undefined, 'the PHP d1 driver is the next piece');
  for (const env of [{ STORAGE: bucket(), DB: {} }, { INSTALLATION: {} }, { STORAGE: bucket(), INSTALLATION: {} }]) {
    const [status, started] = await start(env);
    assert.equal(status, 503, Object.keys(env).join());
    assert.equal(started, undefined);
  }
});

test('P4-5b: the public Worker never reaches the Installation object', async () => {
  const seen = [];
  const env = { ...fullEnv({ INSTALLATION: untouchable, DB: untouchable, STORAGE: untouchable }), APOTHEKE: { getByName: (n) => ({ fetch: async () => { seen.push(n); return new Response('app'); } }) } };
  for (const url of ['http://d1.internal/query', 'https://api.example.test/query', 'https://api.example.test/batch']) {
    assert.equal(await (await publicWorker.fetch(new Request(url, { method: 'POST', body: '{}' }), env)).text(), 'app');
  }
  assert.deepEqual(seen, ['primary', 'primary', 'primary']);
});

test('P4-5b: every Durable Object class in edge/storage.jsonc is exported by the Worker entry', async () => {
  const text = readFileSync(new URL('../storage.jsonc', import.meta.url), 'utf8').replace(/^\s*\/\/.*$/gm, '');
  const storage = JSON.parse(text.replace(/,(\s*[}\]])/g, '$1'));
  const entry = await import('../src/index.ts');
  const classes = [...storage.durable_objects.bindings.map((b) => b.class_name), ...storage.migrations.flatMap((m) => m.new_sqlite_classes ?? [])];
  assert.deepEqual([...new Set(classes)], ['Installation']);
  for (const name of classes) assert.equal(typeof entry[name], 'function', name);
});
