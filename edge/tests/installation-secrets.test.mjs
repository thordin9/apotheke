// E3b: per-installation APP_KEY (HKDF from the Worker secret, installation name as info) and ALLOWED_HOSTS (from
// installation_routes). primary gets the Worker's own APP_KEY and ALLOWED_HOSTS unchanged. All key values are fakes.
// Loaded by r2.test.mjs, which registers the cloudflare:workers stub, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { hkdfSync } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
const { ApothekeContainer } = await import('../src/index.ts');
const { installationAppKey } = await import('../src/installation.ts');

const FAKE = Buffer.alloc(32, 0x5a);
const FAKE_KEY = FAKE.toString('base64');
const untouchable = new Proxy({}, { get() { throw new Error('must not be used'); } });

/** PHP's SecretKey::bytes(): trim, optional "base64:", strict base64 of exactly 32 bytes. */
function phpKeyBytes(raw) {
  let text = raw.trim();
  if (text.startsWith('base64:')) text = text.slice(7).trim();
  if (!/^[A-Za-z0-9+/]*={0,2}$/.test(text)) return null;
  const bytes = Buffer.from(text, 'base64');
  return bytes.length === 32 && bytes.toString('base64') === text ? bytes : null;
}

/** The control migrations on SQLite behind D1's prepare().bind().all(). */
function controlDb() {
  const db = new DatabaseSync(':memory:');
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  return { raw: db, prepare: (sql) => ({ bind: (...v) => ({ all: async () => ({ results: db.prepare(sql).all(...v).map((r) => ({ ...r })) }) }) }) };
}

async function start(name, env, init) {
  let started;
  const probes = [];
  const container = {
    running: false, start: (o) => { started = o; container.running = true; }, setInactivityTimeout: async () => {},
    getTcpPort: () => ({ fetch: async (req) => { probes.push(new URL(req.url).host); return new Response('ok'); } }), interceptOutboundHttp: async () => {},
  };
  const ctx = { id: { name }, container, waitUntil() {}, blockConcurrencyWhile: (f) => f(), exports: { D1Outbound: () => ({}), R2Outbound: () => ({}) } };
  const res = await new ApothekeContainer(ctx, { APP_PASSWORD: 'placeholder', STORAGE: {}, INSTALLATION: {}, ...env }).fetch(new Request('https://x.example.test/', init));
  return { status: res.status, env: started?.env, probes };
}

test('E3b: primary gets the Worker APP_KEY byte for byte, never derived', async () => {
  for (const raw of [FAKE_KEY, `base64:${FAKE_KEY}`, ` ${FAKE_KEY}\n`, 'not-a-key', '']) {
    assert.equal(await installationAppKey('primary', raw), raw);
  }
  assert.equal(await installationAppKey('primary', undefined), '');
});

test('E3b: primary\'s container gets the raw APP_KEY and the Worker ALLOWED_HOSTS, without reading the control plane', async () => {
  const raw = `base64:${FAKE_KEY}`;
  const { status, env, probes } = await start('primary', { APP_KEY: raw, ALLOWED_HOSTS: 'api.example.test', DB: untouchable });
  assert.equal(status, 200);
  assert.equal(env.APP_KEY, raw);
  assert.equal(env.ALLOWED_HOSTS, 'api.example.test');
  assert.deepEqual(probes, ['api.example.test', 'x.example.test']);
});

test('E3b: another installation\'s APP_KEY is HKDF-SHA256 of the key bytes with its name as info, in the format PHP reads', async () => {
  const salt = 'apotheke-installation-app-key-v1';
  const b = await installationAppKey('tenant-b', FAKE_KEY);
  assert.match(b, /^base64:[A-Za-z0-9+/]{43}=$/);
  const bytes = phpKeyBytes(b);
  assert.ok(bytes, 'PHP accepts it as a 32-byte key');
  assert.deepEqual(bytes, Buffer.from(hkdfSync('sha256', FAKE, salt, 'tenant-b', 32)));
  assert.equal(await installationAppKey('tenant-b', ` base64:${FAKE_KEY}\n`), b, 'the same master in any accepted form');
  assert.equal(await installationAppKey('tenant-b', FAKE_KEY.slice(0, 43)), b, 'unpadded, as PHP accepts');
  assert.notEqual(await installationAppKey('tenant-c', FAKE_KEY), b);
  assert.notEqual(bytes.toString('base64'), FAKE_KEY);
  for (const bad of ['', 'not-a-key', Buffer.alloc(31).toString('base64'), undefined]) {
    await assert.rejects(installationAppKey('tenant-b', bad), /not a 32-byte key/);
  }
});

test('E3b: another installation gets its own hosts from installation_routes and its derived key', async () => {
  const db = controlDb();
  db.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'tenant-b', 'installations/tenant-b/', 'active');
    INSERT INTO installation_routes (host, installation_id) VALUES ('b2.example.test', 'b'), ('b.example.test', 'b');`);
  const { status, env, probes } = await start('tenant-b', { APP_KEY: FAKE_KEY, ALLOWED_HOSTS: 'api.example.test', DB: db });
  assert.equal(status, 200);
  assert.equal(env.ALLOWED_HOSTS, 'b.example.test,b2.example.test');
  assert.equal(env.APP_KEY, await installationAppKey('tenant-b', FAKE_KEY));
  assert.equal(probes[0], 'b.example.test');
});

test('hosted env: primary keeps APP_PASSWORD and exactly today\'s env, with nothing new', async () => {
  const { status, env } = await start('primary', { APP_KEY: FAKE_KEY, ALLOWED_HOSTS: 'api.example.test', DB: untouchable });
  assert.equal(status, 200);
  assert.deepEqual(env, {
    APP_PASSWORD: 'placeholder', APP_KEY: FAKE_KEY, ALLOWED_HOSTS: 'api.example.test', COOKIE_DOMAIN: '', CORS_ALLOWED_ORIGINS: '',
    APOTHEKE_JOBS_WORKER: '0', APOTHEKE_PHP_WORKER: '0', PORT: '9091', TZ: 'Europe/Athens', TRUST_PROXY: '1', APOTHEKE_EDGE_CLIENT_IP: '1',
    STORAGE_BACKEND: 'r2', DB_DRIVER: 'd1',
  });
});

test('hosted env: another installation gets no APP_PASSWORD, and APOTHEKE_HOSTED=1 with its own do_name', async () => {
  const db = controlDb();
  db.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'tenant-b', 'installations/tenant-b/', 'active');
    INSERT INTO installation_routes (host, installation_id) VALUES ('b.example.test', 'b');`);
  const { status, env } = await start('tenant-b', { APP_KEY: FAKE_KEY, DB: db });
  assert.equal(status, 200);
  assert.equal('APP_PASSWORD' in env, false);
  assert.equal(env.APOTHEKE_HOSTED, '1');
  assert.equal(env.APOTHEKE_INSTALLATION, 'tenant-b');
  const { APOTHEKE_HOSTED, APOTHEKE_INSTALLATION, ...rest } = env;
  const primary = (await start('primary', { APP_KEY: FAKE_KEY, ALLOWED_HOSTS: 'api.example.test', DB: untouchable })).env;
  assert.deepEqual(Object.keys(rest).sort(), Object.keys(primary).filter((k) => k !== 'APP_PASSWORD').sort(), 'no other difference in keys');
});

test('hosted env: nothing in the request sets APP_PASSWORD, APOTHEKE_HOSTED or APOTHEKE_INSTALLATION', async () => {
  const headers = { 'X-Apotheke-Installation': 'primary', 'Apotheke-Installation': 'primary', 'X-Apotheke-Hosted': '0', 'X-App-Password': 'x', 'X-Forwarded-Host': 'api.example.test' };
  const init = { method: 'POST', headers, body: JSON.stringify({ APOTHEKE_HOSTED: '0', APOTHEKE_INSTALLATION: 'primary', APP_PASSWORD: 'x' }) };
  const db = controlDb();
  db.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'tenant-b', 'installations/tenant-b/', 'active');
    INSERT INTO installation_routes (host, installation_id) VALUES ('b.example.test', 'b');`);
  const b = (await start('tenant-b', { APP_KEY: FAKE_KEY, DB: db }, init)).env;
  assert.equal('APP_PASSWORD' in b, false);
  assert.equal(b.APOTHEKE_HOSTED, '1');
  assert.equal(b.APOTHEKE_INSTALLATION, 'tenant-b');
  const p = (await start('primary', { APP_KEY: FAKE_KEY, ALLOWED_HOSTS: 'api.example.test', DB: untouchable }, init)).env;
  assert.equal(p.APP_PASSWORD, 'placeholder');
  assert.equal('APOTHEKE_HOSTED' in p, false);
  assert.equal('APOTHEKE_INSTALLATION' in p, false);
});

test('E3b: another installation without hosts, control plane or a usable master key does not start', async () => {
  const db = controlDb();
  db.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'tenant-b', 'installations/tenant-b/', 'active')`);
  const failing = { prepare: () => { throw new Error('D1_ERROR'); } };
  const errors = console.error;
  console.error = () => {};
  try {
    for (const env of [{ APP_KEY: FAKE_KEY, DB: db }, { APP_KEY: FAKE_KEY }, { APP_KEY: FAKE_KEY, DB: failing }, { APP_KEY: 'not-a-key', DB: db }, { DB: db }]) {
      const r = await start('tenant-b', env);
      assert.equal(r.status, 503);
      assert.equal(r.env, undefined, 'never started');
    }
  } finally {
    console.error = errors;
  }
});

test('E4: another installation starts only while provisioning or active, read fresh at start; primary is never gated', async () => {
  const errors = console.error;
  console.error = () => {};
  try {
    for (const [st, code] of [['provisioning', 200], ['active', 200], ['suspended', 503], ['deleting', 503], ['deleted', 503]]) {
      const db = controlDb();
      db.raw.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'tenant-b', 'installations/tenant-b/', '${st}');
        INSERT INTO installation_routes (host, installation_id) VALUES ('b.example.test', 'b');`);
      const r = await start('tenant-b', { APP_KEY: FAKE_KEY, DB: db });
      assert.equal(r.status, code, st);
      if (code === 503) assert.equal(r.env, undefined, `${st}: never started`);
      db.raw.exec(`UPDATE installations SET status = '${st}' WHERE id = 'primary'`);
      assert.equal((await start('primary', { APP_KEY: FAKE_KEY, ALLOWED_HOSTS: 'api.example.test', DB: untouchable })).status, 200);
    }
  } finally {
    console.error = errors;
  }
});
