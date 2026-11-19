// Autofill for installations other than primary (L-2190): the container reaches the service only at
// http://autofill.internal, the Worker alone sets the installation and its monthly limit, and no autofill token
// reaches the container. Loaded by r2.test.mjs, which registers the cloudflare:workers stub. All values are fakes.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';

const { ApothekeContainer, AutofillOutbound } = await import('../src/index.ts');
const { AUTOFILL_HOST, handleAutofill } = await import('../src/autofill.ts');
const { LIMITS_HEADER } = await import('../src/limits.ts');

const ON = { LIMITS_ENABLED: '1' };
const FAKE_TOKEN = 'fake-autofill-token-never-forwarded';
const SPOOFED = {
  authorization: `Bearer ${FAKE_TOKEN}`,
  'x-apotheke-installation': 'tenant-c',
  'x-apotheke-autofill-limit': '999999',
  'x-apotheke-autofill-soft': '999999',
  cookie: 'a=b',
  'cf-connecting-ip': '192.0.2.7',
  'x-forwarded-for': '192.0.2.7',
  'content-type': 'multipart/form-data; boundary=x',
};

/** A fake AUTOFILL service binding that records what the Worker sends. */
function service() {
  const seen = [];
  return { seen, fetch: async (req) => { seen.push({ url: req.url, method: req.method, headers: Object.fromEntries(req.headers), body: req.body ? await new Response(req.body).text() : null }); return new Response('{}'); } };
}

const suggest = (headers = SPOOFED) => new Request(`http://${AUTOFILL_HOST}/v1/suggest?x=1`, { method: 'POST', headers, body: 'photo-bytes' });

test('L-2190: spoofed installation, limit and Authorization headers from the container are dropped and set by the Worker', async () => {
  const AUTOFILL = service();
  const res = await handleAutofill(suggest(), { ...ON, AUTOFILL }, { doName: 'tenant-b', autofill: { soft: 4.8, hard: 6 } });
  assert.equal(res.status, 200);
  assert.equal(AUTOFILL.seen.length, 1);
  const [sent] = AUTOFILL.seen;
  assert.equal(sent.url, `https://${AUTOFILL_HOST}/v1/suggest`);
  assert.equal(sent.method, 'POST');
  assert.equal(sent.body, 'photo-bytes');
  assert.deepEqual(sent.headers, {
    'content-type': 'multipart/form-data; boundary=x',
    'x-apotheke-installation': 'tenant-b',
    'x-apotheke-autofill-limit': '6',
    'x-apotheke-autofill-soft': '4',
  });
  assert.ok(!JSON.stringify(sent).includes(FAKE_TOKEN));
});

test('L-2190: with no autofill limit in the plan, the installation is set and spoofed limit headers are still dropped', async () => {
  const AUTOFILL = service();
  await handleAutofill(new Request(`http://${AUTOFILL_HOST}/v1/info`, { headers: SPOOFED }), { ...ON, AUTOFILL }, { doName: 'tenant-b', autofill: null });
  assert.equal(AUTOFILL.seen[0].method, 'GET');
  assert.equal(AUTOFILL.seen[0].headers['x-apotheke-installation'], 'tenant-b');
  for (const h of ['authorization', 'x-apotheke-autofill-limit', 'x-apotheke-autofill-soft', 'cookie', 'cf-connecting-ip']) assert.equal(AUTOFILL.seen[0].headers[h], undefined, h);
});

test('L-2190: fails closed without limits, for primary, without props or without the AUTOFILL binding', async () => {
  const AUTOFILL = service();
  const cases = [
    [{ LIMITS_ENABLED: '0', AUTOFILL }, { doName: 'tenant-b', autofill: null }],
    [{ AUTOFILL }, { doName: 'tenant-b', autofill: null }],
    [{ ...ON, AUTOFILL }, { doName: 'primary', autofill: null }],
    [{ ...ON, AUTOFILL }, { doName: 'Bad_Name', autofill: null }],
    [{ ...ON, AUTOFILL }, { doName: 'tenant-b' }],
    [{ ...ON, AUTOFILL }, undefined],
    [{ ...ON }, { doName: 'tenant-b', autofill: null }],
    [{ ...ON, AUTOFILL_TOKEN: FAKE_TOKEN }, { doName: 'tenant-b', autofill: null }],
  ];
  for (const [env, props] of cases) {
    const res = await handleAutofill(suggest(), env, props);
    assert.equal(res.status, 503, JSON.stringify(props));
  }
  assert.equal((await handleAutofill(new Request(`http://${AUTOFILL_HOST}/healthz`), { ...ON, AUTOFILL }, { doName: 'tenant-b', autofill: null })).status, 404);
  assert.equal(AUTOFILL.seen.length, 0);
  // The outbound entrypoint reads only its props, never the request.
  assert.equal((await new AutofillOutbound({ props: undefined }, { ...ON, AUTOFILL }).fetch(suggest())).status, 503);
  assert.equal(AUTOFILL.seen.length, 0);
});

/** The control migrations plus a plan with autofill_calls_month for tenant-b. */
function controlDb() {
  const db = new DatabaseSync(':memory:');
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  db.exec(`INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('b', 'tenant-b', 'installations/tenant-b/', 'active');
    INSERT INTO installation_routes (host, installation_id) VALUES ('b.example.test', 'b');`);
  return { prepare: (sql) => ({ bind: (...v) => ({ all: async () => ({ results: db.prepare(sql).all(...v).map((r) => ({ ...r })) }) }) }) };
}

/** Starts a container object named name and returns the env PHP got and the outbound handlers it wired. */
async function start(name, vars, limits) {
  let started;
  const handlers = new Map();
  const kv = new Map();
  const container = {
    running: false, start: (o) => { started = o; container.running = true; }, setInactivityTimeout: async () => {},
    getTcpPort: () => ({ fetch: async () => new Response('ok') }),
    interceptOutboundHttp: async (host, handler) => { handlers.set(host, handler); },
  };
  const env = { APP_PASSWORD: 'placeholder', APP_KEY: Buffer.alloc(32, 9).toString('base64'), ALLOWED_HOSTS: 'api.example.test', STORAGE: {}, INSTALLATION: {}, DB: controlDb(), AUTOFILL_TOKEN: FAKE_TOKEN, ...vars };
  const storage = { get: async (k) => kv.get(k), put: async (k, v) => { kv.set(k, v); }, getAlarm: async () => null, setAlarm: async () => {} };
  const ctx = { id: { name }, container, storage, waitUntil() {}, blockConcurrencyWhile: (f) => f(),
    exports: { D1Outbound: () => ({}), R2Outbound: () => ({}), AutofillOutbound: (o) => new AutofillOutbound(o, env) } };
  const headers = limits ? { [LIMITS_HEADER]: JSON.stringify(limits) } : {};
  const res = await new ApothekeContainer(ctx, env).fetch(new Request(`https://${name === 'primary' ? 'api' : 'b'}.example.test/`, { headers }));
  return { status: res.status, env: started?.env, handlers };
}

test('L-2190: the container object wires autofill.internal with its own name and plan limit, only with limits on', async () => {
  const AUTOFILL = service();
  const b = await start('tenant-b', { ...ON, AUTOFILL }, { autofill_calls_month: { soft: 8, hard: 10 } });
  assert.equal(b.status, 200);
  await b.handlers.get(AUTOFILL_HOST).fetch(suggest());
  assert.equal(AUTOFILL.seen[0].headers['x-apotheke-installation'], 'tenant-b');
  assert.equal(AUTOFILL.seen[0].headers['x-apotheke-autofill-limit'], '10');
  assert.equal(AUTOFILL.seen[0].headers['x-apotheke-autofill-soft'], '8');
  assert.equal(AUTOFILL.seen[0].headers.authorization, undefined);
  const noLimit = await start('tenant-b', { ...ON, AUTOFILL }, {});
  await noLimit.handlers.get(AUTOFILL_HOST).fetch(suggest());
  assert.equal(AUTOFILL.seen[1].headers['x-apotheke-autofill-limit'], undefined);
  // Off, or primary: autofill.internal is not wired at all.
  assert.equal((await start('tenant-b', { LIMITS_ENABLED: '0', AUTOFILL }, { autofill_calls_month: { soft: 8, hard: 10 } })).handlers.has(AUTOFILL_HOST), false);
  assert.equal((await start('primary', { ...ON, AUTOFILL }, { autofill_calls_month: { soft: 8, hard: 10 } })).handlers.has(AUTOFILL_HOST), false);
});

test('L-2190: no autofill token or binding ever reaches the container env, and none is configured as a var or a D1 row', async () => {
  for (const [name, vars] of [['tenant-b', ON], ['primary', ON], ['tenant-b', { LIMITS_ENABLED: '0' }]]) {
    const { env } = await start(name, { ...vars, AUTOFILL: service() }, name === 'primary' ? undefined : { autofill_calls_month: { soft: 1, hard: 2 } });
    assert.ok(env, `${name} started`);
    for (const [k, v] of Object.entries(env)) {
      assert.doesNotMatch(k, /autofill/i, `${name}: ${k}`);
      assert.ok(!String(v).includes(FAKE_TOKEN), `${name}: ${k}`);
    }
  }
  const config = readFileSync(new URL('../wrangler.jsonc', import.meta.url), 'utf8') + readFileSync(new URL('../storage.jsonc', import.meta.url), 'utf8');
  assert.doesNotMatch(config, /AUTOFILL_TOKEN/);
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir)) assert.doesNotMatch(readFileSync(new URL(f, dir), 'utf8'), /token/i, f);
});
