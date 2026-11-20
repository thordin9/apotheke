// Operator console PR0b: ApothekeContainer.opsAccounts and the Ops entrypoint ask an installation's container for a
// first-admin claim or an admin reset (POST http://ops.internal/internal/accounts/...), signed like the backup request.
// primary and unknown installations are refused before any call, and the claim token never reaches a log or an error.
// All key values and tokens are fakes. Loaded by r2.test.mjs, which registers the cloudflare:workers stub.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
const { ApothekeContainer, Ops } = await import('../src/index.ts');
const { installationAppKey } = await import('../src/installation.ts');
const { opsSignature, OPS_SIGNATURE_HEADER } = await import('../src/ops-signature.ts');
const { opsAccountsRequest, opsAccountsResult, resolveOpsInstallation, OPS_CLAIM_PATH, OPS_RESET_ADMINS_PATH } = await import('../src/ops-accounts.ts');

const vector = JSON.parse(readFileSync(new URL('../../tests/Modules/_support/ops-signature-vector.json', import.meta.url), 'utf8'));
const vectorKey = 'base64:' + Buffer.from(Array.from({ length: 32 }, (_, i) => i + 1)).toString('base64');
const MASTER = Buffer.alloc(32, 0x5a).toString('base64');
const TOKEN = 'fake-claim-token-0123456789abcdef';
const EXPIRES = '2026-11-21 12:00:00 UTC';

/** Runs fn with console output captured; returns [result or error, captured text]. */
async function captured(fn) {
  const saved = { log: console.log, info: console.info, warn: console.warn, error: console.error, debug: console.debug };
  const out = [];
  for (const k of Object.keys(saved)) console[k] = (...a) => out.push(a.map(String).join(' '));
  try {
    return [await fn(), out.join('\n')];
  } catch (e) {
    return [e, out.join('\n')];
  } finally {
    Object.assign(console, saved);
  }
}

function controlDb(rows = [{ id: 'b', do_name: 'tenant-b', status: 'active' }]) {
  const db = new DatabaseSync(':memory:');
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  for (const r of rows) {
    db.prepare('INSERT INTO installations (id, do_name, r2_prefix, status) VALUES (?, ?, ?, ?)').run(r.id, r.do_name, `installations/${r.do_name}/`, r.status);
    db.prepare('INSERT INTO installation_routes (host, installation_id) VALUES (?, ?)').run(`${r.do_name}.example.test`, r.id);
  }
  const queries = [];
  const bound = (sql, v) => ({
    all: async () => { queries.push(sql); return { results: db.prepare(sql).all(...v).map((r) => ({ ...r })) }; },
    first: async () => { queries.push(sql); const r = db.prepare(sql).get(...v); return r ? { ...r } : null; },
  });
  return { raw: db, queries, prepare: (sql) => ({ bind: (...v) => bound(sql, v) }) };
}

/** A container object with a fake container. answer(req) is the accounts endpoint's response. */
function box(name, { status = 'active', running = true, answer, db } = {}) {
  const requests = [];
  const container = {
    running, starts: 0,
    start() { container.starts += 1; container.running = true; },
    destroy: async () => { container.running = false; },
    setInactivityTimeout: async () => {}, interceptOutboundHttp: async () => {},
    getTcpPort: () => ({ fetch: async (req) => {
      requests.push(req);
      const path = new URL(req.url).pathname;
      if (path === '/healthz') return new Response('ok');
      if (path === OPS_CLAIM_PATH || path === OPS_RESET_ADMINS_PATH) {
        if (answer) return answer(req);
        return Response.json(path === OPS_CLAIM_PATH ? { ok: true, token: TOKEN, expires_at: EXPIRES } : { ok: true });
      }
      return new Response('app');
    } }),
  };
  const ctx = { id: { name }, container, waitUntil() {}, blockConcurrencyWhile: (f) => f(), exports: { D1Outbound: () => ({}), R2Outbound: () => ({}) } };
  const doName = typeof name === 'string' && /^[a-z0-9]+(-[a-z0-9]+)*$/.test(name) && name !== 'primary' ? name : 'other';
  const env = { APP_PASSWORD: 'placeholder', APP_KEY: MASTER, ALLOWED_HOSTS: 'api.example.test', STORAGE: {}, INSTALLATION: {}, DB: db ?? controlDb([{ id: 'b', do_name: doName, status }]) };
  const b = new ApothekeContainer(ctx, env);
  if (running) b.ready = true;
  return { b, container, db: env.DB, requests: () => requests.filter((q) => new URL(q.url).pathname !== '/healthz') };
}

test('PR0b: the accounts request uses the shared ops signature (vector) over its own path and body', async () => {
  assert.equal(await opsSignature(vectorKey, vector.method, vector.path, vector.body, vector.t), vector.header);
  for (const [action, path] of [['claim', OPS_CLAIM_PATH], ['reset-admins', OPS_RESET_ADMINS_PATH]]) {
    const r = await opsAccountsRequest(vectorKey, action, vector.t * 1000);
    assert.equal(r.method, 'POST');
    assert.equal(r.url, `http://ops.internal${path}`);
    const body = await r.text();
    assert.equal(body, '{}');
    assert.equal(r.headers.get(OPS_SIGNATURE_HEADER), await opsSignature(vectorKey, 'POST', path, body, vector.t));
    assert.match(r.headers.get(OPS_SIGNATURE_HEADER), new RegExp(`^t=${vector.t},v1=[0-9a-f]{64}$`));
    for (const h of ['x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-for', 'x-apotheke-client-ip']) assert.equal(r.headers.get(h), null, h);
  }
  const claim = (await opsAccountsRequest(vectorKey, 'claim', vector.t * 1000)).headers.get(OPS_SIGNATURE_HEADER);
  const reset = (await opsAccountsRequest(vectorKey, 'reset-admins', vector.t * 1000)).headers.get(OPS_SIGNATURE_HEADER);
  assert.notEqual(claim, reset, 'a signature for one path is not one for the other');
});

test('PR0b: opsAccounts signs with the installation key and sends to ops.internal on the port', async () => {
  const { b, requests } = box('tenant-b');
  assert.deepEqual(await b.opsAccounts('claim'), { ok: true, token: TOKEN, expires_at: EXPIRES });
  assert.deepEqual(await b.opsAccounts('reset-admins'), { ok: true });
  const [claim, reset] = requests();
  assert.equal(claim.url, 'http://ops.internal/internal/accounts/claim');
  assert.equal(reset.url, 'http://ops.internal/internal/accounts/reset-admins');
  const sig = claim.headers.get(OPS_SIGNATURE_HEADER);
  const t = Number(/^t=(\d+),/.exec(sig)[1]);
  assert.ok(Math.abs(t - Date.now() / 1000) < 5);
  assert.equal(sig, await opsSignature(await installationAppKey('tenant-b', MASTER), 'POST', OPS_CLAIM_PATH, '{}', t));
  assert.notEqual(sig, await opsSignature(MASTER, 'POST', OPS_CLAIM_PATH, '{}', t), 'not the Worker master key');
});

test('PR0b: primary and an unnamed or invalid object are refused before any network call', async () => {
  for (const name of ['primary', undefined, 'Bad_Name', '']) {
    const { b, container, requests } = box(name, { running: false });
    for (const action of ['claim', 'reset-admins']) assert.deepEqual(await b.opsAccounts(action), { ok: false, code: 'refused' }, String(name));
    assert.equal(requests().length, 0);
    assert.equal(container.starts, 0);
  }
  const { b, requests } = box('tenant-b');
  assert.deepEqual(await b.opsAccounts('drop-all'), { ok: false, code: 'bad_action' });
  assert.equal(requests().length, 0);
});

test('PR0b: 409 is active_admin_exists; other statuses and bad answers are typed failures', async () => {
  const run = async (action, answer) => box('tenant-b', { answer }).b.opsAccounts(action);
  assert.deepEqual(await run('claim', () => Response.json({ ok: false, code: 'active_admin_exists' }, { status: 409 })), { ok: false, code: 'active_admin_exists', status: 409 });
  assert.deepEqual(await run('reset-admins', () => new Response(null, { status: 409 })), { ok: false, code: 'http_409', status: 409 });
  for (const [s, code] of [[401, 'unauthorized'], [404, 'not_hosted'], [500, 'accounts_failed'], [503, 'not_configured'], [405, 'http_405'], [302, 'http_302']]) {
    for (const action of ['claim', 'reset-admins']) assert.deepEqual(await run(action, () => Response.json({ ok: false, code }, { status: s })), { ok: false, code, status: s });
  }
  for (const bad of ['{"ok":tr', '{"ok":false}', '{"ok":true}', `{"ok":true,"token":42,"expires_at":"${EXPIRES}"}`, `{"ok":true,"token":"${TOKEN}"}`]) {
    assert.deepEqual(await run('claim', () => new Response(bad)), { ok: false, code: 'bad_answer', status: 200 }, bad);
  }
  assert.deepEqual(await run('reset-admins', () => new Response('{"ok":false}')), { ok: false, code: 'bad_answer', status: 200 });
});

test('PR0b: the claim token is never in a log or a thrown message', async () => {
  // A good claim, a claim whose answer is malformed around the token, and a failed forward all stay quiet about it.
  const [ok, log1] = await captured(() => box('tenant-b').b.opsAccounts('claim'));
  assert.equal(ok.token, TOKEN);
  const [bad, log2] = await captured(() => box('tenant-b', { answer: () => new Response(`{"ok":true,"token":"${TOKEN}","expires_at":`) }).b.opsAccounts('claim'));
  assert.deepEqual(bad, { ok: false, code: 'bad_answer', status: 200 });
  const [err, log3] = await captured(() => box('tenant-b', { answer: () => Response.json({ ok: false, code: 'accounts_failed', token: TOKEN }, { status: 500 }) }).b.opsAccounts('claim'));
  assert.deepEqual(err, { ok: false, code: 'accounts_failed', status: 500 });
  const [lost, log4] = await captured(() => box('tenant-b', { answer: () => { throw new Error('network connection lost'); } }).b.opsAccounts('claim'));
  assert.deepEqual(lost, { ok: false, code: 'unreachable' });
  for (const text of [log1, log2, log3, log4, JSON.stringify(bad), JSON.stringify(err), JSON.stringify(lost)]) assert.ok(!text.includes(TOKEN), text);
  const res = await opsAccountsResult('claim', Response.json({ ok: true, token: TOKEN }));
  assert.ok(!JSON.stringify(res).includes(TOKEN));
});

test('PR0b: opsAccounts acts only on a provisioning or active installation, also when the container is up', async () => {
  for (const status of ['suspended', 'deleting', 'deleted']) {
    for (const running of [true, false]) {
      const { b, container, requests } = box('tenant-b', { status, running });
      assert.deepEqual(await b.opsAccounts('reset-admins'), { ok: false, code: 'not_startable' }, `${status} running=${running}`);
      assert.equal(requests().length, 0);
      assert.equal(container.starts, 0);
    }
  }
  for (const status of ['provisioning', 'active']) {
    const { b, container } = box('tenant-b', { status, running: false });
    assert.deepEqual(await b.opsAccounts('claim'), { ok: true, token: TOKEN, expires_at: EXPIRES });
    assert.equal(container.starts, 1);
  }
  const bad = box('tenant-b');
  bad.b.env.APP_KEY = 'not-a-key';
  assert.deepEqual(await bad.b.opsAccounts('claim'), { ok: false, code: 'bad_app_key' });
  assert.equal(bad.requests().length, 0);
});

test('PR0b: opsAccounts waits for a backup start in flight and re-checks the status before sending', async () => {
  /** A backup start in flight (opsStart set, readying pending); finish() completes it with the container up. */
  const inFlight = () => {
    const x = box('tenant-b', { status: 'active', running: false });
    let finish;
    x.b.readying = new Promise((resolve) => { finish = () => { x.container.running = true; x.b.ready = true; x.b.readying = null; x.b.opsStart = false; resolve(0); }; });
    x.b.opsStart = true;
    x.b.opsOnly = true;
    return { ...x, finish };
  };
  const tick = () => new Promise((r) => setImmediate(r));
  for (const status of ['suspended', 'deleting']) {
    const { b, container, db, requests, finish } = inFlight();
    const pending = b.opsAccounts('claim');
    await tick();
    assert.equal(requests().length, 0, `${status}: nothing is sent while the backup start is in flight`);
    db.raw.prepare('UPDATE installations SET status = ? WHERE do_name = ?').run(status, 'tenant-b');
    finish();
    assert.deepEqual(await pending, { ok: false, code: 'not_startable' }, status);
    assert.equal(requests().length, 0, `${status}: no request to ops.internal`);
    assert.equal(container.starts, 0, `${status}: the container is not started again`);
  }
  const { b, container, requests, finish } = inFlight();
  const pending = b.opsAccounts('claim');
  await tick();
  assert.equal(requests().length, 0, 'active: nothing is sent before the backup start finishes');
  finish();
  assert.deepEqual(await pending, { ok: true, token: TOKEN, expires_at: EXPIRES });
  assert.deepEqual(requests().map((q) => q.url), ['http://ops.internal/internal/accounts/claim']);
  assert.equal(container.starts, 0, 'active: the container the backup started is reused');
  assert.equal(b.opsOnly, false, 'active: the status check cleared the public start gate');
});

test('PR0b: the Ops entrypoint resolves the installation in the control plane and refuses primary and unknown ones', async () => {
  const rows = [
    { id: 'b', do_name: 'tenant-b', status: 'active' },
    { id: 'c', do_name: 'tenant-c', status: 'suspended' },
    { id: 'tenant-d', do_name: 'tenant-e', status: 'active' },
    { id: 'e', do_name: 'tenant-d', status: 'active' },
  ];
  const calls = [];
  const db = controlDb(rows);
  const env = { DB: db, APOTHEKE: { getByName: (name) => ({ opsAccounts: async (action) => { calls.push([name, action]); return action === 'claim' ? { ok: true, token: TOKEN, expires_at: EXPIRES } : { ok: true }; } }) } };
  const ops = new Ops({}, env);
  assert.deepEqual(await ops.claimCreate('b'), { ok: true, token: TOKEN, expires_at: EXPIRES });
  assert.deepEqual(await ops.resetAdmins('tenant-b'), { ok: true });
  assert.deepEqual(calls, [['tenant-b', 'claim'], ['tenant-b', 'reset-admins']]);
  calls.length = 0;
  const before = db.queries.length;
  assert.deepEqual(await ops.claimCreate('primary'), { ok: false, code: 'refused' });
  assert.equal(db.queries.length, before, 'primary is refused before the lookup');
  for (const ref of ['nope', '', 42, null, undefined, 'x'.repeat(64)]) {
    assert.deepEqual(await ops.claimCreate(ref), { ok: false, code: 'unknown_installation' }, String(ref));
    assert.deepEqual(await ops.resetAdmins(ref), { ok: false, code: 'unknown_installation' }, String(ref));
  }
  assert.deepEqual(await ops.resetAdmins('c'), { ok: false, code: 'not_startable' });
  assert.deepEqual(await ops.claimCreate('tenant-d'), { ok: false, code: 'ambiguous_installation' });
  assert.deepEqual(calls, [], 'no container object was called');
  // primary by its seeded row, whatever name reaches the lookup.
  assert.deepEqual(await resolveOpsInstallation({ prepare: () => ({ bind: () => ({ all: async () => ({ results: [{ id: 'p2', do_name: 'primary', status: 'active' }] }) }) }) }, 'p2'), { ok: false, code: 'refused' });
  assert.deepEqual(await new Ops({}, { APOTHEKE: env.APOTHEKE }).claimCreate('b'), { ok: false, code: 'not_configured' });
});
