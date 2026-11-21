// Operator console E6-1: the apotheke-ops Worker (edge/ops). Cloudflare Access fails closed, writes need the CSRF
// header and their own Origin, GET /config reports the vars, and the claim and admin reset go through apotheke-api's
// Ops entrypoint with every refusal, one audit row in one batch, and no token in D1 or a log. E6-2: the reads
// (overview, installations, plans, subscriptions due, jobs, audit and its CSV) with paging, filters and 422s, the key
// fetch timeout and failure window, and the streamed body cap. GET /installations/:id/users: the field allowlist,
// the guards and Access. The Access keys are generated here; tokens and hosts are fakes. Loaded by r2.test.mjs, which registers the cloudflare:workers stub and
// the extensionless import hook, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
const { handle, csrfOk, writesEnabled } = await import('../ops/src/index.ts');
const { accessConfig, accessToken, allowedEmails, clearJwksCache, JWKS_TTL_MS } = await import('../ops/src/access.ts');
const { auditStatement } = await import('../ops/src/audit.ts');

const TEAM = 'ops-team.cloudflareaccess.com';
const AUD = 'fake-aud-0123456789abcdef';
const ORIGIN = 'https://console.example.test';
const TOKEN = 'fake-claim-token-0123456789abcdef';
const EXPIRES = '2026-11-21 12:00:00 UTC';
const NOW = Date.UTC(2026, 10, 20, 12, 0, 0);
// Writes on here, so the E6-1 write tests reach the CSRF check and the actions; the shipped "0" is tested in D1.
const VARS = { ACCESS_TEAM_DOMAIN: TEAM, ACCESS_AUD: AUD, OPS_ALLOWED_EMAILS: 'ops-a@example.test, operator@example.test', OPS_PROVISIONING: '0', LIMITS_ENABLED: '0', OPS_WRITES_ENABLED: '1' };

const b64url = (v) => Buffer.from(typeof v === 'string' ? v : JSON.stringify(v)).toString('base64url');
async function keyPair(kid) {
  const pair = await crypto.subtle.generateKey({ name: 'RSASSA-PKCS1-v1_5', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['sign', 'verify']);
  const jwk = await crypto.subtle.exportKey('jwk', pair.publicKey);
  return { kid, privateKey: pair.privateKey, jwk: { kty: 'RSA', n: jwk.n, e: jwk.e, kid, alg: 'RS256', use: 'sig' } };
}
const KEY = await keyPair('kid-1');
const OTHER = await keyPair('kid-1');

async function jwt(claims = {}, { key = KEY, header = {} } = {}) {
  const now = Math.floor(NOW / 1000);
  const payload = { iss: `https://${TEAM}`, aud: [AUD], email: 'Operator@Example.test', iat: now - 10, nbf: now - 10, exp: now + 600, ...claims };
  for (const k of Object.keys(payload)) if (payload[k] === undefined) delete payload[k];
  const head = b64url({ alg: 'RS256', kid: key.kid, typ: 'JWT', ...header });
  const body = b64url(payload);
  const sig = await crypto.subtle.sign('RSASSA-PKCS1-v1_5', key.privateKey, new TextEncoder().encode(`${head}.${body}`));
  return `${head}.${body}.${Buffer.from(sig).toString('base64url')}`;
}

function certsFetch(keys = [KEY.jwk]) {
  const calls = [];
  const f = async (url) => { calls.push(url); return Response.json({ keys }); };
  f.calls = calls;
  return f;
}

/** The control migrations on a real SQLite, with tenant-b (active), its host, primary, and installations in every
 *  other state. batch() runs its statements in one transaction and records each batch. */
function controlDb() {
  const db = new DatabaseSync(':memory:');
  db.exec('PRAGMA foreign_keys = ON');
  const dir = new URL('../migrations/control/', import.meta.url);
  for (const f of readdirSync(dir).filter((f) => f.endsWith('.sql')).sort()) db.exec(readFileSync(new URL(f, dir), 'utf8'));
  const add = (id, doName, status, host) => {
    db.prepare('INSERT INTO installations (id, do_name, r2_prefix, status) VALUES (?, ?, ?, ?)').run(id, doName, `installations/${doName}/`, status);
    if (host) db.prepare('INSERT INTO installation_routes (host, installation_id) VALUES (?, ?)').run(host, id);
  };
  add('b', 'tenant-b', 'active', 'b.example.test');
  db.prepare('INSERT INTO installation_routes (host, installation_id) VALUES (?, ?)').run('b-alt.example.test', 'b');
  add('p', 'tenant-p', 'provisioning', 'p.example.test');
  add('s', 'tenant-s', 'suspended', 's.example.test');
  add('x', 'tenant-x', 'deleting', 'x.example.test');
  add('d', 'tenant-d', 'deleted', 'd.example.test');
  add('n', 'tenant-n', 'active', null);
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

function opsStub(answers = {}) {
  const calls = [];
  return {
    calls,
    claimCreate: async (id) => { calls.push(['claim', id]); return answers.claim ?? { ok: true, token: TOKEN, expires_at: EXPIRES }; },
    resetAdmins: async (id) => { calls.push(['reset', id]); return answers.reset ?? { ok: true }; },
    listUsers: async (id) => { calls.push(['users', id]); if (answers.users instanceof Error) throw answers.users; return 'users' in answers ? answers.users : { ok: true, users: [] }; },
  };
}

function setup({ vars = VARS, ops = opsStub(), db = controlDb(), fetch = certsFetch(), assets } = {}) {
  clearJwksCache();
  const env = { ...vars, DB: db, OPS: ops, ASSETS: assets ?? { fetch: async (req) => new Response(`asset ${new URL(req.url).pathname}`, { headers: { 'Content-Type': 'text/html', 'Cache-Control': 'public, max-age=60', 'Access-Control-Allow-Origin': '*' } }) } };
  let now = NOW;
  const deps = { fetch, now: () => now };
  const go = async (path, { method = 'GET', token, headers = {}, body } = {}) => {
    const h = { ...headers };
    if (token !== null) h['Cf-Access-Jwt-Assertion'] = token ?? await jwt();
    const res = await handle(new Request(ORIGIN + path, { method, headers: h, body }), env, deps);
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch {}
    return { res, status: res.status, json, text };
  };
  const write = (path, body, extra = {}) => go(path, { method: 'POST', headers: { 'X-CSRF': '1', Origin: ORIGIN, 'Content-Type': 'application/json' }, body: JSON.stringify(body), ...extra });
  return { env, db, ops, fetch, go, write, tick: (ms) => { now += ms; } };
}

/** Runs fn with console output captured; returns [result, captured text]. */
async function captured(fn) {
  const saved = { log: console.log, info: console.info, warn: console.warn, error: console.error, debug: console.debug };
  const out = [];
  for (const k of Object.keys(saved)) console[k] = (...a) => out.push(a.map((x) => (x instanceof Error ? `${x.message} ${x.stack}` : typeof x === 'string' ? x : JSON.stringify(x))).join(' '));
  try { return [await fn(), out.join('\n')]; } finally { Object.assign(console, saved); }
}

const audits = (db) => db.raw.prepare('SELECT * FROM operator_audit ORDER BY at, id').all().map((r) => ({ ...r }));
const assertNoStore = (res) => {
  assert.equal(res.headers.get('cache-control'), 'no-store');
  for (const [k] of res.headers) assert.ok(!k.toLowerCase().startsWith('access-control-'), `no CORS header: ${k}`);
};

test('E6-1 ops: without ACCESS_TEAM_DOMAIN or ACCESS_AUD every request is 503 not_configured, before any key fetch', async () => {
  for (const vars of [{ ...VARS, ACCESS_TEAM_DOMAIN: '' }, { ...VARS, ACCESS_AUD: '' }, { OPS_PROVISIONING: '0' }, { ...VARS, ACCESS_TEAM_DOMAIN: 'evil.example.test' }, { ...VARS, OPS_ALLOWED_EMAILS: '' }, { ...VARS, OPS_ALLOWED_EMAILS: ' , ' }, { ...VARS, OPS_ALLOWED_EMAILS: undefined }]) {
    const t = setup({ vars });
    for (const [path, method] of [['/api/ops/v1/config', 'GET'], ['/', 'GET'], ['/api/ops/v1/installations/b/claim', 'POST'], ['/api/ops/v1/config', 'OPTIONS']]) {
      const r = await t.go(path, { method });
      assert.equal(r.status, 503, `${method} ${path}`);
      assert.deepEqual(r.json, { ok: false, code: 'not_configured' });
      assertNoStore(r.res);
    }
    assert.equal(t.fetch.calls.length, 0);
    assert.equal(t.ops.calls.length, 0);
  }
  assert.deepEqual(accessConfig({ ACCESS_TEAM_DOMAIN: `https://${TEAM.toUpperCase()}/`, ACCESS_AUD: AUD, OPS_ALLOWED_EMAILS: ' A@x.test ,b@x.test,' }), { team: TEAM, aud: AUD, emails: new Set(['a@x.test', 'b@x.test']) });
  assert.equal(accessConfig({ ACCESS_TEAM_DOMAIN: TEAM, ACCESS_AUD: AUD }), null, 'no OPS_ALLOWED_EMAILS');
});

test('E6-1 ops: Access fails closed (missing, malformed, bad signature, unknown key, wrong aud or iss, expired, not yet valid, alg)', async () => {
  const t = setup();
  const cases = {
    missing: null,
    malformed: 'not-a-jwt',
    'bad signature': await jwt({}, { key: OTHER }),
    'unknown kid': await jwt({}, { key: { ...KEY, kid: 'kid-unknown' } }),
    'wrong aud': await jwt({ aud: ['another-aud'] }),
    'wrong iss': await jwt({ iss: 'https://other.cloudflareaccess.com' }),
    expired: await jwt({ exp: Math.floor(NOW / 1000) - 1 }),
    'no exp': await jwt({ exp: undefined }),
    'not yet valid': await jwt({ nbf: Math.floor(NOW / 1000) + 3600 }),
    'alg none': (await jwt({}, { header: { alg: 'none' } })),
    'tampered payload': (await jwt()).replace(/\.[^.]+\./, '.' + b64url({ iss: `https://${TEAM}`, aud: [AUD], email: 'x@example.test', exp: 9e9 }) + '.'),
  };
  for (const [name, token] of Object.entries(cases)) {
    for (const path of ['/api/ops/v1/config', '/']) {
      const r = await t.go(path, { token });
      assert.equal(r.status, 401, `${name} ${path}`);
      assert.deepEqual(r.json, { ok: false, code: 'unauthorized' }, name);
      assertNoStore(r.res);
    }
  }
  const noEmail = await t.go('/api/ops/v1/config', { token: await jwt({ email: undefined, common_name: 'svc' }) });
  assert.deepEqual([noEmail.status, noEmail.json], [403, { ok: false, code: 'forbidden' }]);
  const ok = await t.go('/api/ops/v1/config', { token: await jwt({ aud: AUD }) });
  assert.equal(ok.status, 200, 'aud as a string');
});

test('E6-1 ops: the team keys are cached, refetched after the TTL or for an unknown key, and a failed fetch is 503', async () => {
  const t = setup();
  await t.go('/api/ops/v1/config');
  await t.go('/api/ops/v1/config');
  assert.deepEqual(t.fetch.calls, [`https://${TEAM}/cdn-cgi/access/certs`]);
  t.tick(JWKS_TTL_MS);
  assert.equal((await t.go('/api/ops/v1/config')).status, 200);
  assert.equal(t.fetch.calls.length, 2);
  await t.go('/api/ops/v1/config', { token: await jwt({}, { key: { ...KEY, kid: 'kid-new' } }) });
  assert.equal(t.fetch.calls.length, 2, 'an unknown kid right after a fetch does not fetch again');
  t.tick(31_000);
  await t.go('/api/ops/v1/config', { token: await jwt({ exp: Math.floor((NOW + 31_000 + JWKS_TTL_MS) / 1000) + 600 }, { key: { ...KEY, kid: 'kid-new' } }) });
  assert.equal(t.fetch.calls.length, 3);

  const down = setup({ fetch: async () => new Response('no', { status: 500 }) });
  const r = await down.go('/api/ops/v1/config');
  assert.deepEqual([r.status, r.json], [503, { ok: false, code: 'access_unavailable' }]);
});

test('E6-1 ops: GET /config reports provisioning and limits from the vars; no-store, no CORS, OPTIONS 405', async () => {
  const off = setup();
  const r = await off.go('/api/ops/v1/config', { headers: { Origin: 'https://evil.example.test' } });
  assert.deepEqual([r.status, r.json], [200, { ok: true, provisioning_enabled: false, limits_enabled: false, writes_enabled: true }]);
  assertNoStore(r.res);
  assert.match(r.res.headers.get('x-request-id'), /^[0-9a-f-]{36}$/);
  const on = setup({ vars: { ...VARS, OPS_PROVISIONING: '1', LIMITS_ENABLED: '1', OPS_WRITES_ENABLED: '0' } });
  assert.deepEqual((await on.go('/api/ops/v1/config')).json, { ok: true, provisioning_enabled: true, limits_enabled: true, writes_enabled: false });
  const pre = await off.go('/api/ops/v1/config', { method: 'OPTIONS', headers: { Origin: 'https://evil.example.test', 'Access-Control-Request-Method': 'POST' } });
  assert.equal(pre.status, 405);
  assertNoStore(pre.res);
  for (const path of ['/api/ops/v1/nope', '/api/ops/v2/config', '/api/v1/items', '/api/ops']) {
    assert.deepEqual((await off.go(path)).json, { ok: false, code: 'not_found' }, path);
  }
  const wrong = await off.write('/api/ops/v1/config', {});
  assert.deepEqual([wrong.status, wrong.res.headers.get('allow')], [405, 'GET, HEAD']);
});

test('E6-1 ops: the console files go through Access and get no-store, with their CORS header dropped', async () => {
  const t = setup();
  const r = await t.go('/installations');
  assert.deepEqual([r.status, r.text], [200, 'asset /installations']);
  assertNoStore(r.res);
  assert.match(r.res.headers.get('content-security-policy'), /frame-ancestors 'none'/);
  assert.equal((await t.go('/', { token: null })).status, 401);
});

test('E6-1 ops: a write needs X-CSRF: 1 and its own Origin, else 403 csrf, before the body or the binding is read', async () => {
  const t = setup();
  const path = '/api/ops/v1/installations/b/claim';
  const body = JSON.stringify({ confirm_host: 'b.example.test' });
  const cases = [
    {},
    { 'X-CSRF': '1' },
    { Origin: ORIGIN },
    { 'X-CSRF': '0', Origin: ORIGIN },
    { 'X-CSRF': '1', Origin: 'https://evil.example.test' },
    { 'X-CSRF': '1', Origin: 'http://console.example.test' },
    { 'X-CSRF': '1', Origin: 'null' },
  ];
  for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
    for (const headers of cases) {
      const r = await t.go(path, { method, headers, body });
      assert.deepEqual([r.status, r.json], [403, { ok: false, code: 'csrf' }], `${method} ${JSON.stringify(headers)}`);
    }
  }
  assert.equal(t.ops.calls.length, 0);
  assert.equal(audits(t.db).length, 0);
  assert.equal(csrfOk(new Request(ORIGIN + '/x', { method: 'POST', headers: { 'X-CSRF': '1', Origin: ORIGIN } })), true);
});

test('E6-1 ops: claim returns the link built from the confirmed host, writes one audit row in one batch, no token anywhere', async () => {
  const t = setup();
  const [r, logs] = await captured(() => t.write('/api/ops/v1/installations/b/claim', { confirm_host: ' B-ALT.example.test ' }));
  assert.deepEqual([r.status, r.json], [200, { ok: true, link: `https://b-alt.example.test/claim#${TOKEN}`, expires_at: EXPIRES }]);
  assertNoStore(r.res);
  assert.deepEqual(t.ops.calls, [['claim', 'b']]);
  assert.equal(t.db.batches.length, 1);
  assert.equal(t.db.batches[0].length, 1);
  const rows = audits(t.db);
  assert.equal(rows.length, 1);
  assert.deepEqual({ ...rows[0], id: undefined, at: undefined }, {
    id: undefined, at: undefined, operator: 'operator@example.test', action: 'installation.claim', installation_id: 'b',
    before_json: null, after_json: JSON.stringify({ host: 'b-alt.example.test', expires_at: EXPIRES }), request_id: r.res.headers.get('x-request-id'),
  });
  const dump = JSON.stringify(t.db.raw.prepare("SELECT * FROM operator_audit").all()) + JSON.stringify(t.db.batches);
  assert.ok(!dump.includes(TOKEN) && !dump.includes('/claim#'), 'no token or link in D1');
  assert.ok(!logs.includes(TOKEN), 'no token in a log');
});

test('E6-1 ops: admin reset calls resetAdmins and writes one audit row; provisioning installations are allowed', async () => {
  const t = setup();
  const r = await t.write('/api/ops/v1/installations/p/admin-reset', { confirm_host: 'p.example.test' });
  assert.deepEqual([r.status, r.json], [200, { ok: true }]);
  assert.deepEqual(t.ops.calls, [['reset', 'p']]);
  assert.equal(t.db.batches.length, 1);
  const rows = audits(t.db);
  assert.deepEqual(rows.map((x) => [x.operator, x.action, x.installation_id, x.after_json]), [['operator@example.test', 'installation.admin_reset', 'p', '{"host":"p.example.test"}']]);
});

test('E6-1 ops: claim and admin reset refusals (primary, unknown, deleted, not startable, no host, confirm_host, body)', async () => {
  for (const action of ['claim', 'admin-reset']) {
    const t = setup();
    t.db.raw.prepare("INSERT INTO installation_routes (host, installation_id) VALUES ('main.example.test', 'primary')").run();
    const cases = [
      ['primary', { confirm_host: 'main.example.test' }, 403, 'primary'],
      ['nope', { confirm_host: 'b.example.test' }, 404, 'not_found'],
      ['%E0%A4%A', { confirm_host: 'b.example.test' }, 404, 'not_found'],
      ['d', { confirm_host: 'd.example.test' }, 410, 'gone'],
      ['s', { confirm_host: 's.example.test' }, 409, 'not_startable'],
      ['x', { confirm_host: 'x.example.test' }, 409, 'not_startable'],
      ['n', { confirm_host: 'n.example.test' }, 409, 'no_host'],
      ['b', { confirm_host: 'p.example.test' }, 422, 'confirm_host'],
      ['b', { confirm_host: 'b.example.test.' }, 422, 'confirm_host'],
      ['b', {}, 422, 'confirm_host'],
      ['b', { confirm_host: ['b.example.test'] }, 422, 'confirm_host'],
    ];
    for (const [id, body, status, code] of cases) {
      const r = await t.write(`/api/ops/v1/installations/${id}/${action}`, body);
      assert.deepEqual([r.status, r.json], [status, { ok: false, code }], `${action} ${id} ${JSON.stringify(body)}`);
      assertNoStore(r.res);
    }
    for (const raw of ['not json', '[1]', 'null', JSON.stringify({ confirm_host: 'b.example.test', pad: 'x'.repeat(5000) })]) {
      const r = await t.go(`/api/ops/v1/installations/b/${action}`, { method: 'POST', headers: { 'X-CSRF': '1', Origin: ORIGIN }, body: raw });
      assert.deepEqual([r.status, r.json], [422, { ok: false, code: 'invalid_body' }], raw.slice(0, 20));
    }
    const get = await t.go(`/api/ops/v1/installations/b/${action}`);
    assert.deepEqual([get.status, get.res.headers.get('allow')], [405, 'POST']);
    assert.equal(t.ops.calls.length, 0, 'the binding is never called for a refusal');
    assert.equal(t.db.batches.length, 0, 'nothing is audited for a refusal');
  }
});

test('E6-1 ops: the Ops entrypoint\'s refusals map to console codes; nothing is audited', async () => {
  const cases = [
    [{ ok: false, code: 'active_admin_exists', status: 409 }, 409, { ok: false, code: 'active_admin_exists' }],
    [{ ok: false, code: 'refused' }, 403, { ok: false, code: 'primary' }],
    [{ ok: false, code: 'unknown_installation' }, 404, { ok: false, code: 'not_found' }],
    [{ ok: false, code: 'ambiguous_installation' }, 409, { ok: false, code: 'ambiguous_installation' }],
    [{ ok: false, code: 'not_startable' }, 409, { ok: false, code: 'not_startable' }],
    [{ ok: false, code: 'unreachable' }, 503, { ok: false, code: 'unavailable', detail: 'unreachable' }],
    [{ ok: false, code: 'not_hosted', status: 404 }, 503, { ok: false, code: 'unavailable', detail: 'not_hosted' }],
    [{ ok: false, code: 'http_502', status: 502 }, 503, { ok: false, code: 'unavailable', detail: 'http_502' }],
    [{ ok: true, token: '', expires_at: EXPIRES }, 503, { ok: false, code: 'unavailable', detail: 'bad_answer' }],
    [null, 503, { ok: false, code: 'unavailable', detail: 'bad_answer' }],
  ];
  for (const [answer, status, body] of cases) {
    const ops = { calls: [], claimCreate: async () => answer, resetAdmins: async () => answer };
    const t = setup({ ops });
    const r = await t.write('/api/ops/v1/installations/b/claim', { confirm_host: 'b.example.test' });
    assert.deepEqual([r.status, r.json], [status, body], JSON.stringify(answer));
    assert.equal(t.db.batches.length, 0);
  }
  const thrower = setup({ ops: { claimCreate: async () => { throw new Error(`boom ${TOKEN}`); }, resetAdmins: async () => ({ ok: true }) } });
  const [r, logs] = await captured(() => thrower.write('/api/ops/v1/installations/b/claim', { confirm_host: 'b.example.test' }));
  assert.deepEqual([r.status, r.json], [503, { ok: false, code: 'unavailable' }]);
  assert.ok(!logs.includes(TOKEN), 'an error message is not logged');
  const unbound = setup();
  delete unbound.env.OPS;
  assert.deepEqual((await unbound.write('/api/ops/v1/installations/b/claim', { confirm_host: 'b.example.test' })).json, { ok: false, code: 'not_configured' });
});

test('E6-1 ops: when the audit batch fails the claim link is withheld (503 audit_failed) and nothing is kept', async () => {
  const t = setup();
  t.db.failBatch = true;
  const [r, logs] = await captured(() => t.write('/api/ops/v1/installations/b/claim', { confirm_host: 'b.example.test' }));
  assert.deepEqual([r.status, r.json], [503, { ok: false, code: 'audit_failed' }]);
  assert.ok(!r.text.includes(TOKEN) && !logs.includes(TOKEN));
  assert.equal(audits(t.db).length, 0);
});

test('E6-1 ops: the audit helper refuses a token or a link in before or after', () => {
  const db = controlDb();
  const base = { operator: 'o@example.test', action: 'a', target: 'b', requestId: 'r' };
  assert.throws(() => auditStatement(db, { ...base, after: { token: 'x' } }), /credential/);
  assert.throws(() => auditStatement(db, { ...base, before: { nested: { link: 'https://h/claim#x' } } }), /credential/);
  assert.doesNotThrow(() => auditStatement(db, { ...base, after: { host: 'b.example.test' } }));
});

test('E6-1 ops: wrangler.jsonc ships with no routes, no workers.dev or preview URLs, no cron, Access unset, and every flag off', () => {
  const text = readFileSync(new URL('../ops/wrangler.jsonc', import.meta.url), 'utf8');
  const config = JSON.parse(text.replace(/^\s*\/\/.*$/gm, '').replace(/,(\s*[}\]])/g, '$1'));
  assert.equal(config.name, 'apotheke-ops');
  assert.equal(config.workers_dev, false);
  assert.equal(config.preview_urls, false);
  for (const k of ['route', 'routes', 'account_id', 'secrets']) assert.equal(config[k], undefined, k);
  assert.deepEqual(config.vars, { ACCESS_TEAM_DOMAIN: '', ACCESS_AUD: '', OPS_ALLOWED_EMAILS: '', OPS_WRITES_ENABLED: '0', OPS_PROVISIONING: '0', LIMITS_ENABLED: '0' });
  assert.deepEqual(config.services, [
    { binding: 'OPS', service: 'apotheke-api', entrypoint: 'Ops' },
    { binding: 'AUTOFILL', service: 'apotheke-autofill', entrypoint: 'HostedAutofill' },
  ]);
  assert.deepEqual(config.triggers, { crons: [] }, 'no Cron Trigger');
  for (const k of ['queues', 'custom_domains', 'r2_buckets', 'durable_objects', 'migrations']) assert.equal(config[k], undefined, k);
  // The lifecycle Workflow is apotheke-api's: only its binding here.
  assert.deepEqual(config.workflows, [{ name: 'apotheke-lifecycle', binding: 'LIFECYCLE', class_name: 'LifecycleWorkflow', script_name: 'apotheke-api' }]);
  assert.equal(config.assets.directory, '../../ops/console/dist');
  assert.equal(config.assets.binding, 'ASSETS');
  const storage = readFileSync(new URL('../storage.jsonc', import.meta.url), 'utf8');
  assert.equal(config.d1_databases.length, 1);
  assert.equal(config.d1_databases[0].binding, 'DB');
  assert.ok(storage.includes(`"database_name": "${config.d1_databases[0].database_name}"`), 'the same D1 as apotheke-api');
  assert.equal(config.d1_databases[0].database_id, undefined);
  assert.equal(config.assets.run_worker_first, true, 'the console files go through the Access check');
});

// E6-2: the reads. seedReads adds plans, limits, subscriptions, usage, jobs and audit rows to controlDb's
// installations (b, d, n, p, primary, s, x).
function seedReads(db) {
  const r = db.raw;
  r.exec(`INSERT INTO plans (id, name, active, created_at) VALUES ('shelf', 'Shelf', 1, '2026-10-01T00:00:00Z'), ('loft', 'Loft', 0, '2026-10-02T00:00:00Z')`);
  r.exec(`INSERT INTO plan_limits (plan_id, limit_key, soft_value, hard_value) VALUES ('shelf', 'items', NULL, 500), ('shelf', 'photos_per_item', 20, 40), ('loft', 'storage_bytes', 1000, 5000)`);
  const sub = r.prepare('INSERT INTO subscriptions (installation_id, plan_id, status, trial_end, period_end, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
  sub.run('b', 'shelf', 'active', null, '2026-11-25', '2026-11-01T00:00:00Z');
  sub.run('p', 'shelf', 'trial', '2026-12-10T00:00:00Z', null, '2026-11-01T00:00:00Z');
  sub.run('s', 'loft', 'past_due', null, '2026-11-17', '2026-11-01T00:00:00Z');
  sub.run('d', 'loft', 'cancelled', null, '2026-11-21', '2026-11-01T00:00:00Z');
  sub.run('x', 'shelf', 'active', null, null, '2026-11-01T00:00:00Z');
  const use = r.prepare('INSERT INTO usage_counters (installation_id, period, metric, value) VALUES (?, ?, ?, ?)');
  use.run('b', '2026-11', 'items', 12); use.run('p', '2026-11', 'items', 4); use.run('b', '2026-10', 'items', 9); use.run('primary', '2026-11', 'photos', 1);
  const job = r.prepare('INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, state, confirmed_by, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
  for (const [id, inst, kind, state, day] of [['j1', 'b', 'export', 'done', '01'], ['j2', 'b', 'suspend', 'paused', '02'], ['j3', 's', 'suspend', 'running', '03'], ['j4', 'p', 'provision', 'queued', '04'], ['j5', 'x', 'delete', 'failed', '05'], ['j6', 'x', 'delete', 'paused', '05']]) {
    const at = `2026-11-${day}T00:00:00Z`;
    job.run(id, inst, kind, `${kind}:${inst}:${id}`, state, state === 'paused' ? null : 'ops-a@example.test', 'ops-a@example.test', at, at);
  }
  const audit = r.prepare('INSERT INTO operator_audit (id, at, operator, action, installation_id, before_json, after_json, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
  audit.run('a1', '2026-11-01T10:00:00Z', 'ops-a@example.test', 'installation.claim', 'b', null, '{"host":"b.example.test","expires_at":"2026-11-02 10:00:00 UTC"}', 'r1');
  audit.run('a2', '2026-11-02T10:00:00Z', 'ops-b@example.test', 'installation.admin_reset', 'p', null, '{"host":"p.example.test"}', 'r2');
  audit.run('a3', '2026-11-03T10:00:00Z', 'ops-a@example.test', 'subscription.update', 'b', '{"plan_id":"loft"}', '{"plan_id":"shelf"}', 'r3');
  audit.run('a4', '2026-11-03T10:00:00Z', 'ops-b@example.test', 'installation.claim', 's', null, '{"host":"s.example.test","expires_at":"2026-11-04 10:00:00 UTC"}', 'r4');
  return db;
}

const API = '/api/ops/v1';
const READS = ['/overview', '/installations', '/installations/b', '/plans', '/subscriptions?due=14', '/jobs', '/audit', '/audit.csv'];
const readSetup = () => setup({ db: seedReads(controlDb()) });
/** Every page of a list, following next_cursor. */
async function walk(t, path, key) {
  const rows = [];
  let next = null;
  let pages = 0;
  do {
    const r = await t.go(path + (path.includes('?') ? '&' : '?') + (next ? `cursor=${next}` : ''));
    assert.equal(r.status, 200, JSON.stringify(r.json));
    rows.push(...r.json[key]);
    next = r.json.next_cursor;
    pages++;
  } while (next);
  return { rows, pages };
}

test('E6-2 ops: every read needs Access, is GET or HEAD only, no-store, and OPTIONS is 405 before Access', async () => {
  for (const path of READS) {
    const t = readSetup();
    const none = await t.go(API + path, { token: null });
    assert.deepEqual([none.status, none.json], [401, { ok: false, code: 'unauthorized' }], path);
    assertNoStore(none.res);
    const bad = await t.go(API + path, { token: await jwt({ aud: ['another-aud'] }) });
    assert.equal(bad.status, 401, path);
    const ok = await t.go(API + path);
    assert.equal(ok.status, 200, path);
    assertNoStore(ok.res);
    assert.match(ok.res.headers.get('x-request-id'), /^[0-9a-f-]{36}$/);
    const head = await t.go(API + path, { method: 'HEAD' });
    assert.equal(head.status, 200, `HEAD ${path}`);
    for (const token of [undefined, null]) {
      const opt = await t.go(API + path, { method: 'OPTIONS', token, headers: { Origin: 'https://evil.example.test', 'Access-Control-Request-Method': 'GET' } });
      assert.deepEqual([opt.status, opt.json], [405, { ok: false, code: 'method_not_allowed' }], `OPTIONS ${path}`);
      assertNoStore(opt.res);
    }
    const csrf = await t.go(API + path, { method: 'POST', body: '{}' });
    assert.deepEqual([csrf.status, csrf.json], [403, { ok: false, code: 'csrf' }], `POST ${path}`);
    const post = await t.write(API + path, {});
    // POST /jobs creates a job (E6-3); an empty body is 422 and writes nothing.
    if (path === '/jobs') assert.deepEqual([post.status, post.json.code], [422, 'invalid_body']);
    else assert.deepEqual([post.status, post.json, post.res.headers.get('allow')], [405, { ok: false, code: 'method_not_allowed' }, 'GET, HEAD'], `POST ${path}`);
    assert.equal(t.db.batches.length, 0, 'a read writes nothing');
  }
  const unbound = readSetup();
  delete unbound.env.DB;
  assert.deepEqual((await unbound.go(API + '/overview')).json, { ok: false, code: 'not_configured' });
});

test('E6-2 ops: GET /overview counts installations, plans, subscriptions and open jobs; revenue and as_of are null', async () => {
  const t = readSetup();
  const r = await t.go(API + '/overview');
  assert.deepEqual(r.json, {
    ok: true, revenue: null, as_of: null,
    installations_by_status: [{ status: 'provisioning', count: 1 }, { status: 'active', count: 3 }, { status: 'suspended', count: 1 }, { status: 'deleting', count: 1 }, { status: 'deleted', count: 1 }],
    installations_by_plan: [{ plan_id: 'internal', plan_name: 'Internal', count: 1 }, { plan_id: 'loft', plan_name: 'Loft', count: 2 }, { plan_id: 'shelf', plan_name: 'Shelf', count: 3 }],
    subscriptions_by_status: [{ status: 'trial', count: 1 }, { status: 'active', count: 3 }, { status: 'past_due', count: 1 }, { status: 'cancelled', count: 1 }],
    open_jobs_by_state: [{ state: 'paused', count: 2 }, { state: 'queued', count: 1 }, { state: 'running', count: 1 }],
    usage_period: '2026-11',
    usage_totals: [{ metric: 'items', value: 16 }, { metric: 'photos', value: 1 }],
  });
  const empty = setup();
  const e = await empty.go(API + '/overview');
  assert.deepEqual(e.json.open_jobs_by_state.map((x) => x.count), [0, 0, 0]);
  assert.deepEqual(e.json.usage_totals, []);
});

test('E6-2 ops: GET /installations lists every installation with routes, subscription and plan; primary is marked; paging and filters', async () => {
  const t = readSetup();
  const r = await t.go(API + '/installations');
  assert.deepEqual(r.json.installations.map((x) => [x.installation.id, x.primary]), [['b', false], ['d', false], ['n', false], ['p', false], ['primary', true], ['s', false], ['x', false]]);
  assert.equal(r.json.next_cursor, null);
  const b = r.json.installations[0];
  assert.deepEqual(Object.keys(b.installation).sort(), ['created_at', 'do_name', 'id', 'notes', 'owner_contact', 'r2_prefix', 'status', 'updated_at']);
  assert.deepEqual([b.installation.do_name, b.installation.status], ['tenant-b', 'active']);
  assert.deepEqual(b.routes.map((x) => x.host), ['b-alt.example.test', 'b.example.test']);
  assert.deepEqual(b.subscription, { installation_id: 'b', plan_id: 'shelf', status: 'active', trial_end: null, period_end: '2026-11-25', notes: null, updated_at: '2026-11-01T00:00:00Z' });
  assert.deepEqual(b.plan, { id: 'shelf', name: 'Shelf', active: 1, created_at: '2026-10-01T00:00:00Z' });
  const n = r.json.installations[2];
  assert.deepEqual([n.routes, n.subscription, n.plan], [[], null, null], 'no host and no subscription');

  const paged = await walk(t, API + '/installations?limit=3', 'installations');
  assert.deepEqual([paged.pages, paged.rows.map((x) => x.installation.id)], [3, ['b', 'd', 'n', 'p', 'primary', 's', 'x']]);
  const ids = async (q) => (await t.go(API + '/installations?' + q)).json.installations.map((x) => x.installation.id);
  assert.deepEqual(await ids('status=active'), ['b', 'n', 'primary']);
  assert.deepEqual(await ids('plan=shelf'), ['b', 'p', 'x']);
  assert.deepEqual(await ids('plan=shelf&status=active'), ['b']);
  assert.deepEqual(await ids('plan=nope'), []);
  assert.deepEqual(await ids('q=B-ALT'), ['b']);
  assert.deepEqual(await ids('q=%25'), [], 'q is a plain substring, not a LIKE pattern');
  assert.deepEqual(await ids('status=&plan=&q=&cursor='), ['b', 'd', 'n', 'p', 'primary', 's', 'x'], 'empty values are no filter');
  const mainHost = (await t.go(API + '/installations?status=active')).json.installations.find((x) => x.primary);
  assert.deepEqual(mainHost.routes.map((x) => x.host), ['api.services.getapotheke.com']);
});

test('E6-2 ops: bad query values are 422 invalid_query with the parameter', async () => {
  const t = readSetup();
  const cases = [
    ['/installations?status=gone', 'status'], ['/installations?status=Active', 'status'], ['/installations?limit=0', 'limit'],
    ['/installations?limit=101', 'limit'], ['/installations?limit=1.5', 'limit'], ['/installations?limit=-1', 'limit'],
    ['/installations?cursor=***', 'cursor'], ['/installations?cursor=' + Buffer.from('["a","b"]').toString('base64url'), 'cursor'],
    ['/installations?cursor=' + Buffer.from('{"a":1}').toString('base64url'), 'cursor'], ['/installations?plan=' + 'p'.repeat(65), 'plan'],
    ['/installations?q=%00', 'q'], ['/jobs?kind=reboot', 'kind'], ['/jobs?state=stuck', 'state'], ['/jobs?status=stuck', 'status'],
    ['/jobs?state=done&status=failed', 'status'], ['/jobs?cursor=' + Buffer.from('["x"]').toString('base64url'), 'cursor'], ['/jobs?limit=500', 'limit'],
    ['/audit?from=yesterday', 'from'], ['/audit?to=2026-13-01', 'to'], ['/audit?from=2026-02-30', 'from'], ['/audit?to=2026-11-20T25:00Z', 'to'],
    ['/audit?operator=' + 'o'.repeat(255), 'operator'], ['/audit?limit=x', 'limit'], ['/audit.csv?from=x', 'from'],
    ['/subscriptions?due=366', 'due'], ['/subscriptions?due=-1', 'due'], ['/subscriptions?due=abc', 'due'], ['/subscriptions?due=14days', 'due'], ['/subscriptions?due=1e2', 'due'],
  ];
  for (const [path, param] of cases) {
    const r = await t.go(API + path);
    assert.deepEqual([r.status, r.json], [422, { ok: false, code: 'invalid_query', detail: param }], path);
    assertNoStore(r.res);
  }
});

test('E6-2 ops: GET /installations/:id has routes, subscription, plan limits, usage, the last jobs and audit rows; primary is readable', async () => {
  const t = readSetup();
  const r = await t.go(API + '/installations/b');
  assert.equal(r.status, 200);
  assert.deepEqual(Object.keys(r.json).sort(), ['audit', 'installation', 'jobs', 'limits', 'ok', 'plan', 'primary', 'routes', 'subscription', 'usage']);
  assert.deepEqual([r.json.installation.id, r.json.primary, r.json.plan.id], ['b', false, 'shelf']);
  assert.deepEqual(r.json.limits, [{ plan_id: 'shelf', limit_key: 'items', soft_value: null, hard_value: 500 }, { plan_id: 'shelf', limit_key: 'photos_per_item', soft_value: 20, hard_value: 40 }]);
  assert.deepEqual(r.json.usage, [{ installation_id: 'b', period: '2026-11', metric: 'items', value: 12 }, { installation_id: 'b', period: '2026-10', metric: 'items', value: 9 }]);
  assert.deepEqual(r.json.jobs.map((j) => [j.id, j.kind, j.state]), [['j2', 'suspend', 'paused'], ['j1', 'export', 'done']]);
  assert.deepEqual(r.json.audit.map((a) => a.id), ['a3', 'a1']);
  assert.deepEqual(Object.keys(r.json.audit[0]).sort(), ['action', 'after_json', 'at', 'before_json', 'id', 'installation_id', 'operator', 'request_id']);

  const job = t.db.raw.prepare('INSERT INTO lifecycle_jobs (id, installation_id, kind, idempotency_key, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)');
  const audit = t.db.raw.prepare('INSERT INTO operator_audit (id, at, operator, action, installation_id) VALUES (?, ?, ?, ?, ?)');
  for (let i = 10; i < 40; i++) {
    job.run(`k${i}`, 'b', 'export', `export:b:${i}`, 'o@example.test', `2026-11-15T00:00:${i}Z`);
    audit.run(`z${i}`, `2026-11-15T00:00:${i}Z`, 'o@example.test', 'x', 'b');
  }
  const many = await t.go(API + '/installations/b');
  assert.equal(many.json.jobs.length, 20);
  assert.equal(many.json.audit.length, 20);
  assert.deepEqual([many.json.jobs[0].id, many.json.audit[0].id], ['k39', 'z39'], 'newest first');

  const primary = await t.go(API + '/installations/primary');
  assert.deepEqual([primary.status, primary.json.primary, primary.json.plan.id, primary.json.limits], [200, true, 'internal', []]);
  const none = await t.go(API + '/installations/n');
  assert.deepEqual([none.json.subscription, none.json.plan, none.json.limits, none.json.routes], [null, null, [], []]);
  for (const id of ['nope', '%E0%A4%A', 'b%00', 'x'.repeat(129)]) {
    const miss = await t.go(API + '/installations/' + id);
    assert.deepEqual([miss.status, miss.json], [404, { ok: false, code: 'not_found' }], id);
  }
  assert.equal((await t.go(API + '/installations/b/')).status, 404);
});

test('E6-2 ops: GET /plans returns every plan with its limits', async () => {
  const t = readSetup();
  const r = await t.go(API + '/plans');
  assert.deepEqual(r.json, {
    ok: true,
    plans: [
      { id: 'internal', name: 'Internal', active: 1, created_at: r.json.plans[0].created_at, limits: [] },
      { id: 'loft', name: 'Loft', active: 0, created_at: '2026-10-02T00:00:00Z', limits: [{ plan_id: 'loft', limit_key: 'storage_bytes', soft_value: 1000, hard_value: 5000 }] },
      { id: 'shelf', name: 'Shelf', active: 1, created_at: '2026-10-01T00:00:00Z', limits: [{ plan_id: 'shelf', limit_key: 'items', soft_value: null, hard_value: 500 }, { plan_id: 'shelf', limit_key: 'photos_per_item', soft_value: 20, hard_value: 40 }] },
    ],
  });
});

test('E6-2 ops: GET /subscriptions?due= lists subscriptions due within N days, overdue first, cancelled never', async () => {
  const t = readSetup();
  const due = async (q) => (await t.go(API + '/subscriptions' + q)).json;
  const d14 = await due('');
  assert.deepEqual([d14.due_days, d14.now, d14.truncated], [14, '2026-11-20T12:00:00Z', false]);
  assert.deepEqual(d14.subscriptions.map((s) => [s.installation_id, s.due_at, s.overdue]), [['s', '2026-11-17', true], ['b', '2026-11-25', false]]);
  assert.deepEqual(d14.subscriptions[1], { installation_id: 'b', plan_id: 'shelf', plan_name: 'Shelf', status: 'active', trial_end: null, period_end: '2026-11-25', notes: null, updated_at: '2026-11-01T00:00:00Z', due_at: '2026-11-25', hosts: ['b-alt.example.test', 'b.example.test'], overdue: false });
  assert.deepEqual((await due('?due=14d')).subscriptions.length, 2);
  assert.deepEqual((await due('?due=30')).subscriptions.map((s) => s.installation_id), ['s', 'b', 'p']);
  assert.deepEqual((await due('?due=0')).subscriptions.map((s) => s.installation_id), ['s']);
  assert.deepEqual((await due('?due=365')).due_days, 365);
});

test('E6-2 ops: GET /jobs filters by kind, state and installation, newest first, paged', async () => {
  const t = readSetup();
  const ids = async (q) => (await t.go(API + '/jobs' + q)).json.jobs.map((j) => j.id);
  assert.deepEqual(await ids(''), ['j6', 'j5', 'j4', 'j3', 'j2', 'j1']);
  assert.deepEqual(await ids('?kind=suspend'), ['j3', 'j2']);
  assert.deepEqual(await ids('?state=paused'), ['j6', 'j2']);
  assert.deepEqual(await ids('?status=paused'), ['j6', 'j2']);
  assert.deepEqual(await ids('?state=paused&status=paused&kind=delete'), ['j6']);
  assert.deepEqual(await ids('?installation=b'), ['j2', 'j1']);
  const paged = await walk(t, API + '/jobs?limit=4', 'jobs');
  assert.deepEqual([paged.pages, paged.rows.map((j) => j.id)], [2, ['j6', 'j5', 'j4', 'j3', 'j2', 'j1']]);
  const two = await walk(t, API + '/jobs?limit=1&kind=delete', 'jobs');
  assert.deepEqual(two.rows.map((j) => j.id), ['j6', 'j5'], 'the same created_at pages by id');
  const j = (await t.go(API + '/jobs?installation=b&kind=export')).json.jobs[0];
  assert.deepEqual(Object.keys(j).sort(), ['confirmed_at', 'confirmed_by', 'created_at', 'created_by', 'error_code', 'id', 'idempotency_key', 'installation_id', 'kind', 'params_json', 'result_json', 'state', 'step', 'updated_at']);
});

test('E6-2 ops: GET /audit filters by installation, operator, action and time, newest first, paged', async () => {
  const t = readSetup();
  const ids = async (q) => (await t.go(API + '/audit' + q)).json.audit.map((a) => a.id);
  assert.deepEqual(await ids(''), ['a4', 'a3', 'a2', 'a1']);
  assert.deepEqual(await ids('?installation=b'), ['a3', 'a1']);
  assert.deepEqual(await ids('?operator=OPS-B@example.test'), ['a4', 'a2']);
  assert.deepEqual(await ids('?action=installation.claim'), ['a4', 'a1']);
  assert.deepEqual(await ids('?action=installation.claim&operator=ops-a@example.test&installation=b'), ['a1']);
  assert.deepEqual(await ids('?from=2026-11-02'), ['a4', 'a3', 'a2']);
  assert.deepEqual(await ids('?to=2026-11-02T10:00:00Z'), ['a1'], 'to is exclusive');
  assert.deepEqual(await ids('?from=2026-11-02T10:00Z&to=2026-11-03'), ['a2']);
  const paged = await walk(t, API + '/audit?limit=1', 'audit');
  assert.deepEqual([paged.pages, paged.rows.map((a) => a.id)], [4, ['a4', 'a3', 'a2', 'a1']]);
});

test('E6-2 ops: GET /audit.csv has the same filters, escapes formula cells, quotes properly, and is capped', async () => {
  const { csvCell, CSV_MAX_ROWS } = await import('../ops/src/reads.ts');
  for (const [v, out] of [
    ['=1+1', "'=1+1"], ['+1', "'+1"], ['-1', "'-1"], ['@SUM(A1)', "'@SUM(A1)"], ['\tx', "'\tx"], ['\rx', `"'\rx"`],
    ['a,b', '"a,b"'], ['say "hi"', '"say ""hi"""'], ['a\nb', '"a\nb"'], ['=HYPERLINK("x","y")', `"'=HYPERLINK(""x"",""y"")"`],
    [null, ''], [undefined, ''], ['plain', 'plain'], [' =x', ' =x'], [12, '12'],
  ]) assert.equal(csvCell(v), out, JSON.stringify(v));

  const t = readSetup();
  t.db.raw.prepare('INSERT INTO operator_audit (id, at, operator, action, installation_id, before_json, after_json, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
    .run('a5', '2026-11-04T10:00:00Z', '=cmd@example.test', '+action', null, '{"note":"a,\\"b\\""}', '-x', '@r5');
  const r = await t.go(API + '/audit.csv');
  assert.equal(r.status, 200);
  assert.equal(r.res.headers.get('content-type'), 'text/csv; charset=utf-8');
  assert.equal(r.res.headers.get('content-disposition'), 'attachment; filename="operator-audit.csv"');
  assert.equal(r.res.headers.get('x-truncated'), '0');
  assertNoStore(r.res);
  const lines = r.text.split('\r\n');
  assert.equal(lines[0], 'id,at,operator,action,installation_id,before_json,after_json,request_id');
  assert.equal(lines[1], `a5,2026-11-04T10:00:00Z,'=cmd@example.test,'+action,,"{""note"":""a,\\""b\\""""}",'-x,'@r5`);
  assert.equal(lines[2], 'a4,2026-11-03T10:00:00Z,ops-b@example.test,installation.claim,s,,"{""host"":""s.example.test"",""expires_at"":""2026-11-04 10:00:00 UTC""}",r4');
  assert.equal(lines.length, 7, 'header, five rows and the final CRLF');
  const filtered = await t.go(API + '/audit.csv?installation=b&operator=OPS-A@example.test');
  assert.deepEqual(filtered.text.split('\r\n').slice(1, -1).map((l) => l.split(',')[0]), ['a3', 'a1']);

  const big = setup();
  const ins = big.db.raw.prepare('INSERT INTO operator_audit (id, at, operator, action) VALUES (?, ?, ?, ?)');
  big.db.raw.exec('BEGIN');
  for (let i = 0; i <= CSV_MAX_ROWS; i++) ins.run(`r${String(i).padStart(6, '0')}`, '2026-11-01T00:00:00Z', 'o@example.test', 'x');
  big.db.raw.exec('COMMIT');
  const capped = await big.go(API + '/audit.csv');
  assert.equal(capped.res.headers.get('x-truncated'), '1');
  assert.equal(capped.text.split('\r\n').length, CSV_MAX_ROWS + 2);
});

test('E6-2 ops: no read returns a claim token or link, after a claim and an admin reset', async () => {
  const t = readSetup();
  assert.equal((await t.write(API + '/installations/b/claim', { confirm_host: 'b.example.test' })).status, 200);
  assert.equal((await t.write(API + '/installations/b/admin-reset', { confirm_host: 'b.example.test' })).status, 200);
  for (const path of [...READS, '/installations/b']) {
    const r = await t.go(API + path);
    assert.equal(r.status, 200, path);
    assert.ok(!r.text.includes(TOKEN) && !r.text.includes('/claim#'), path);
  }
  const audit = (await t.go(API + '/audit?installation=b&action=installation.claim')).json.audit;
  assert.deepEqual(JSON.parse(audit[0].after_json), { host: 'b.example.test', expires_at: EXPIRES });
});

test('E6-2 ops: a failed key fetch has a timeout and is not retried for JWKS_FAILURE_MS; still 503 access_unavailable', async () => {
  const { JWKS_FAILURE_MS, JWKS_TIMEOUT_MS } = await import('../ops/src/access.ts');
  assert.equal(JWKS_TIMEOUT_MS, 5000);
  const calls = [];
  let fail = true;
  const fetch = async (url, init) => {
    calls.push(init);
    assert.ok(init.signal instanceof AbortSignal, 'the fetch has a timeout signal');
    if (fail) throw new DOMException('The operation was aborted due to timeout', 'TimeoutError');
    return Response.json({ keys: [KEY.jwk] });
  };
  const t = setup({ fetch });
  const down = { status: 503, json: { ok: false, code: 'access_unavailable' } };
  for (let i = 0; i < 3; i++) {
    const r = await t.go(API + '/config');
    assert.deepEqual({ status: r.status, json: r.json }, down);
  }
  assert.equal(calls.length, 1, 'failures within the window do not fetch again');
  t.tick(JWKS_FAILURE_MS);
  assert.equal((await t.go(API + '/config')).status, 503);
  assert.equal(calls.length, 2, 'after the window it tries again');
  fail = false;
  t.tick(JWKS_FAILURE_MS);
  assert.equal((await t.go(API + '/config')).status, 200);
  assert.equal(calls.length, 3);
  const status = setup({ fetch: async () => new Response('no', { status: 502 }) });
  assert.equal((await status.go(API + '/config')).status, 503);
});

test('E6-2 ops: a body without Content-Length is read only up to the 4 KiB cap; check order is Access, CSRF, route, body', async () => {
  const { MAX_BODY_BYTES } = await import('../ops/src/api.ts');
  const t = setup();
  let pulled = 0;
  const endless = new ReadableStream({ pull(c) { pulled += 1024; c.enqueue(new Uint8Array(1024).fill(32)); } });
  const req = new Request(ORIGIN + API + '/installations/b/claim', {
    method: 'POST', body: endless, duplex: 'half',
    headers: { 'Cf-Access-Jwt-Assertion': await jwt(), 'X-CSRF': '1', Origin: ORIGIN },
  });
  assert.equal(req.headers.get('content-length'), null);
  const r = await handle(req, t.env, { fetch: t.fetch, now: () => NOW });
  assert.deepEqual([r.status, await r.json()], [422, { ok: false, code: 'invalid_body' }]);
  assert.ok(pulled <= MAX_BODY_BYTES + 2048, `read ${pulled} bytes`);
  assert.equal(t.ops.calls.length, 0);

  const exact = await t.go(API + '/installations/b/claim', { method: 'POST', headers: { 'X-CSRF': '1', Origin: ORIGIN }, body: JSON.stringify({ confirm_host: 'b.example.test', pad: '' }).padEnd(MAX_BODY_BYTES, ' ') });
  assert.equal(exact.status, 200, 'a body of exactly 4 KiB is read');

  // Order: no Access token is 401 before CSRF, no CSRF is 403 before the route, an unknown route is 404 before the body.
  const body = 'x'.repeat(MAX_BODY_BYTES + 1);
  assert.equal((await t.go(API + '/installations/b/claim', { method: 'POST', token: null, body })).status, 401);
  assert.equal((await t.go(API + '/nope', { method: 'POST', body })).status, 403);
  assert.equal((await t.go(API + '/nope', { method: 'POST', headers: { 'X-CSRF': '1', Origin: ORIGIN }, body })).status, 404);
});

test('E6-2 ops: an installation whose row has do_name primary is refused by id other than primary, and listed as primary', async () => {
  const db = controlDb();
  db.raw.exec("DELETE FROM subscriptions WHERE installation_id = 'primary'; DELETE FROM installation_routes WHERE installation_id = 'primary'; DELETE FROM installations WHERE id = 'primary'");
  db.raw.exec("INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('main', 'primary', 'installations/primary/', 'active'); INSERT INTO installation_routes (host, installation_id) VALUES ('main.example.test', 'main')");
  const t = setup({ db });
  for (const action of ['claim', 'admin-reset']) {
    const r = await t.write(`${API}/installations/main/${action}`, { confirm_host: 'main.example.test' });
    assert.deepEqual([r.status, r.json], [403, { ok: false, code: 'primary' }], action);
  }
  assert.equal(t.ops.calls.length, 0);
  assert.equal(t.db.batches.length, 0);
  assert.equal((await t.go(API + '/installations/main')).json.primary, true);
  assert.equal((await t.go(API + '/installations')).json.installations.find((x) => x.installation.id === 'main').primary, true);
});

// D1 (deploy wiring): the allowed-emails list, the CF_Authorization cookie, and read-only by default.
test('D1 ops: a valid token whose email is not on OPS_ALLOWED_EMAILS is 403 forbidden, for the API and the files', async () => {
  const t = setup();
  for (const email of ['someone@example.test', 'operator@example.test.evil', 'ops-a@example.test ']) {
    for (const path of ['/api/ops/v1/config', '/', '/api/ops/v1/installations']) {
      const r = await t.go(path, { token: await jwt({ email }) });
      assert.deepEqual([r.status, r.json], [403, { ok: false, code: 'forbidden' }], `${email} ${path}`);
      assertNoStore(r.res);
    }
  }
  const only = setup({ vars: { ...VARS, OPS_ALLOWED_EMAILS: 'ops@example.invalid' } });
  assert.equal((await only.go('/api/ops/v1/config')).status, 403, 'operator@example.test is not on the list');
  assert.equal((await only.go('/api/ops/v1/config', { token: await jwt({ email: 'Ops@Example.INVALID' }) })).status, 200, 'case-insensitive');
  assert.deepEqual([...allowedEmails(' a@x.test,,B@x.test ')], ['a@x.test', 'b@x.test']);
  assert.equal(allowedEmails(undefined).size, 0);
});

test('D1 ops: the CF_Authorization cookie is used when the header is missing or empty; a non-empty header wins', async () => {
  const t = setup();
  const good = await jwt();
  const viaCookie = await t.go('/api/ops/v1/config', { token: null, headers: { Cookie: `other=1; CF_Authorization=${good}; x=y` } });
  assert.equal(viaCookie.status, 200);
  const badCookie = await t.go('/api/ops/v1/config', { token: null, headers: { Cookie: `CF_Authorization=${await jwt({ aud: ['x'] })}` } });
  assert.deepEqual([badCookie.status, badCookie.json], [401, { ok: false, code: 'unauthorized' }]);
  const headerWins = await t.go('/api/ops/v1/config', { token: 'not-a-jwt', headers: { Cookie: `CF_Authorization=${good}` } });
  assert.equal(headerWins.status, 401);
  // N-2521: an empty (or blank) header falls through to the cookie; with no cookie it still fails closed.
  for (const blank of ['', '   ']) {
    assert.equal((await t.go('/api/ops/v1/config', { token: blank, headers: { Cookie: `CF_Authorization=${good}` } })).status, 200, `header ${JSON.stringify(blank)} + cookie`);
    const none = await t.go('/api/ops/v1/config', { token: blank });
    assert.deepEqual([none.status, none.json], [401, { ok: false, code: 'unauthorized' }], `header ${JSON.stringify(blank)} alone`);
    const badCookie2 = await t.go('/api/ops/v1/config', { token: blank, headers: { Cookie: `CF_Authorization=${await jwt({ iss: 'https://other.cloudflareaccess.com' })}` } });
    assert.equal(badCookie2.status, 401, `header ${JSON.stringify(blank)} + bad cookie`);
  }
  const lookalike = await t.go('/api/ops/v1/config', { token: null, headers: { Cookie: `XCF_Authorization=${good}` } });
  assert.equal(lookalike.status, 401);
  assert.equal(accessToken(new Request(ORIGIN)), '');
});

test('D1 ops: with OPS_WRITES_ENABLED other than "1" every write is 403 writes_disabled after Access, before CSRF or any binding', async () => {
  for (const value of ['0', '', undefined, 'true', ' 1']) {
    const t = setup({ vars: { ...VARS, OPS_WRITES_ENABLED: value } });
    assert.equal(writesEnabled(t.env), false, String(value));
    for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
      for (const path of ['/api/ops/v1/installations/b/claim', '/api/ops/v1/installations/b/admin-reset', '/api/ops/v1/config', '/']) {
        const r = await t.write(path, { confirm_host: 'b.example.test' }, { method });
        assert.deepEqual([r.status, r.json], [403, { ok: false, code: 'writes_disabled' }], `${value} ${method} ${path}`);
        assertNoStore(r.res);
      }
      const noCsrf = await t.go('/api/ops/v1/installations/b/claim', { method, body: '{}' });
      assert.equal(noCsrf.json.code, 'writes_disabled');
    }
    const anon = await t.write('/api/ops/v1/installations/b/claim', {}, { token: null });
    assert.equal(anon.status, 401, 'Access comes first');
    assert.equal((await t.go('/api/ops/v1/installations')).status, 200, 'reads still work');
    assert.equal(t.ops.calls.length, 0);
    assert.equal(audits(t.db).length, 0);
  }
});

test('D1 ops: /api/ops/v1 goes to the API, other paths to ASSETS, both behind Access', async () => {
  const t = setup();
  assert.equal((await t.go('/api/ops/v1/config')).json.ok, true);
  assert.equal((await t.go('/assets/index.js')).text, 'asset /assets/index.js');
  assert.equal((await t.go('/api/ops/v1/config', { token: null })).status, 401);
  assert.equal((await t.go('/assets/index.js', { token: null })).status, 401);
});

const USER = { id: 1, username: 'admin', role: 'admin', status: 'active', created_at: '2026-11-01 10:00:00', last_login_at: null };

test('ops users: GET /installations/:id/users answers allowlisted account metadata; HEAD too; no audit row', async () => {
  const extra = { ...USER, id: 2, status: 'disabled', password_hash: '$2y$fake', reset_token: 'fake-reset', session_epoch: 3, email: 'kim@example.test' };
  const t = setup({ ops: opsStub({ users: { ok: true, users: [USER, extra], token: 'fake-top-level' } }) });
  const r = await t.go('/api/ops/v1/installations/b/users');
  assert.equal(r.status, 200);
  const shaped = { id: 1, username: 'admin', display_name: null, roles: ['admin'], active: true, status: 'active', created_at: '2026-11-01T10:00:00Z', last_login_at: null };
  assert.deepEqual(r.json, { ok: true, installation: 'b', users: [shaped, { ...shaped, id: 2, active: false, status: 'disabled' }], next_cursor: null });
  assert.doesNotMatch(r.text, /fake|hash|token|epoch|email/);
  assertNoStore(r.res);
  assert.equal((await t.go('/api/ops/v1/installations/b/users', { method: 'HEAD' })).status, 200);
  assert.deepEqual(t.ops.calls, [['users', 'b'], ['users', 'b']]);
  assert.deepEqual(audits(t.db), []);
  const empty = setup();
  assert.deepEqual((await empty.go('/api/ops/v1/installations/p/users')).json, { ok: true, installation: 'p', users: [], next_cursor: null });
});

test('ops users: guards, primary, upstream failures and methods', async () => {
  const t = setup();
  const cases = [
    ['nope', 404, { ok: false, code: 'not_found' }],
    ['%E0%A4%A', 404, { ok: false, code: 'not_found' }],
    ['primary', 403, { ok: false, code: 'primary' }],
    ['d', 410, { ok: false, code: 'gone' }],
    ['s', 409, { ok: false, code: 'not_startable' }],
    ['x', 409, { ok: false, code: 'not_startable' }],
    ['n', 409, { ok: false, code: 'no_host' }],
  ];
  for (const [id, status, body] of cases) {
    const r = await t.go(`/api/ops/v1/installations/${id}/users`);
    assert.deepEqual([r.status, r.json], [status, body], id);
    assertNoStore(r.res);
  }
  assert.deepEqual(t.ops.calls, [], 'no refusal reaches apotheke-api');

  for (const method of ['POST', 'PUT', 'DELETE', 'PATCH']) {
    const r = await t.go('/api/ops/v1/installations/b/users', { method, headers: { 'X-CSRF': '1', Origin: ORIGIN } });
    assert.deepEqual([r.status, r.json, r.res.headers.get('allow')], [405, { ok: false, code: 'method_not_allowed' }, 'GET, HEAD'], method);
  }
  assert.deepEqual(t.ops.calls, []);

  const upstream = [
    [{ ok: false, code: 'refused' }, 403, { ok: false, code: 'primary' }],
    [{ ok: false, code: 'unknown_installation' }, 404, { ok: false, code: 'not_found' }],
    [{ ok: false, code: 'not_startable' }, 409, { ok: false, code: 'not_startable' }],
    [{ ok: false, code: 'read_failed', status: 503 }, 503, { ok: false, code: 'unavailable', detail: 'read_failed' }],
    [{ ok: false, code: 'unbound' }, 503, { ok: false, code: 'unavailable', detail: 'unbound' }],
    [{ ok: true, users: [{ ...USER, id: '1' }] }, 503, { ok: false, code: 'unavailable', detail: 'bad_answer' }],
    [{ ok: true, users: [{ ...USER, last_login_at: 5 }] }, 503, { ok: false, code: 'unavailable', detail: 'bad_answer' }],
    [{ ok: true }, 503, { ok: false, code: 'unavailable', detail: 'bad_answer' }],
    [null, 503, { ok: false, code: 'unavailable', detail: 'bad_answer' }],
  ];
  for (const [users, status, body] of upstream) {
    const u = setup({ ops: opsStub({ users }) });
    const r = await u.go('/api/ops/v1/installations/b/users');
    assert.deepEqual([r.status, r.json], [status, body], JSON.stringify(users));
  }
  const thrown = setup({ ops: opsStub({ users: new Error('binding down') }) });
  const [r, log] = await captured(() => thrown.go('/api/ops/v1/installations/b/users'));
  assert.deepEqual([r.status, r.json], [503, { ok: false, code: 'unavailable' }]);
  assert.doesNotMatch(log, /binding down/);
  const unbound = setup();
  delete unbound.env.OPS;
  assert.deepEqual((await unbound.go('/api/ops/v1/installations/b/users')).json, { ok: false, code: 'not_configured' });
});

test('ops users: Access is required before any lookup or call', async () => {
  const t = setup();
  for (const token of [null, 'not-a-jwt', await jwt({ aud: ['another-aud'] })]) {
    const r = await t.go('/api/ops/v1/installations/b/users', { token });
    assert.deepEqual([r.status, r.json], [401, { ok: false, code: 'unauthorized' }]);
    assertNoStore(r.res);
  }
  assert.deepEqual(t.ops.calls, []);
  const off = setup({ vars: { ...VARS, ACCESS_AUD: '' } });
  assert.deepEqual((await off.go('/api/ops/v1/installations/b/users')).json, { ok: false, code: 'not_configured' });
  assert.deepEqual(off.ops.calls, []);
});

test('ops users: roles, active and ISO dates from the app fields', async () => {
  const { isoUtc } = await import('../ops/src/api.ts');
  const rows = [
    { id: 3, username: 'p', role: 'viewer', status: 'pending', created_at: '2026-11-02T08:09:10.123Z', last_login_at: '2026-11-20 23:59:59' },
    { id: 4, username: 'q', role: '', status: 'active', created_at: 'yesterday', last_login_at: '2026-02-30 10:00:00' },
  ];
  const t = setup({ ops: opsStub({ users: { ok: true, users: rows } }) });
  const r = await t.go('/api/ops/v1/installations/b/users');
  assert.deepEqual(r.json.users, [
    { id: 3, username: 'p', display_name: null, roles: ['viewer'], active: false, status: 'pending', created_at: '2026-11-02T08:09:10Z', last_login_at: '2026-11-20T23:59:59Z' },
    { id: 4, username: 'q', display_name: null, roles: [], active: true, status: 'active', created_at: null, last_login_at: null },
  ]);
  assert.equal(isoUtc(null), null);
  assert.equal(isoUtc('2026-11-20T10:00:00Z'), '2026-11-20T10:00:00Z');
  assert.equal(isoUtc('2026-11-20 10:00:00+02:00'), null);
});
