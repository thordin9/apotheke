// Operator console E6-1: the apotheke-ops Worker (edge/ops). Cloudflare Access fails closed, writes need the CSRF
// header and their own Origin, GET /config reports the vars, and the claim and admin reset go through apotheke-api's
// Ops entrypoint with every refusal, one audit row in one batch, and no token in D1 or a log. The Access keys are
// generated here; tokens and hosts are fakes. Loaded by r2.test.mjs, which registers the cloudflare:workers stub and
// the extensionless import hook, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
const { handle, csrfOk } = await import('../ops/src/index.ts');
const { accessConfig, clearJwksCache, JWKS_TTL_MS } = await import('../ops/src/access.ts');
const { auditStatement } = await import('../ops/src/audit.ts');

const TEAM = 'ops-team.cloudflareaccess.com';
const AUD = 'fake-aud-0123456789abcdef';
const ORIGIN = 'https://console.example.test';
const TOKEN = 'fake-claim-token-0123456789abcdef';
const EXPIRES = '2026-11-21 12:00:00 UTC';
const NOW = Date.UTC(2026, 10, 20, 12, 0, 0);
const VARS = { ACCESS_TEAM_DOMAIN: TEAM, ACCESS_AUD: AUD, OPS_PROVISIONING: '0', LIMITS_ENABLED: '0' };

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
  for (const vars of [{ ...VARS, ACCESS_TEAM_DOMAIN: '' }, { ...VARS, ACCESS_AUD: '' }, { OPS_PROVISIONING: '0' }, { ...VARS, ACCESS_TEAM_DOMAIN: 'evil.example.test' }]) {
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
  assert.deepEqual(accessConfig({ ACCESS_TEAM_DOMAIN: `https://${TEAM.toUpperCase()}/`, ACCESS_AUD: AUD }), { team: TEAM, aud: AUD });
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
  assert.deepEqual([r.status, r.json], [200, { ok: true, provisioning_enabled: false, limits_enabled: false }]);
  assertNoStore(r.res);
  assert.match(r.res.headers.get('x-request-id'), /^[0-9a-f-]{36}$/);
  const on = setup({ vars: { ...VARS, OPS_PROVISIONING: '1', LIMITS_ENABLED: '1' } });
  assert.deepEqual((await on.go('/api/ops/v1/config')).json, { ok: true, provisioning_enabled: true, limits_enabled: true });
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

test('E6-1 ops: wrangler.jsonc ships with no routes, no workers.dev or preview URLs, Access unset, and both flags off', () => {
  const text = readFileSync(new URL('../ops/wrangler.jsonc', import.meta.url), 'utf8');
  const config = JSON.parse(text.replace(/^\s*\/\/.*$/gm, '').replace(/,(\s*[}\]])/g, '$1'));
  assert.equal(config.name, 'apotheke-ops');
  assert.equal(config.workers_dev, false);
  assert.equal(config.preview_urls, false);
  for (const k of ['route', 'routes', 'account_id', 'secrets']) assert.equal(config[k], undefined, k);
  assert.deepEqual(config.vars, { ACCESS_TEAM_DOMAIN: '', ACCESS_AUD: '', OPS_PROVISIONING: '0', LIMITS_ENABLED: '0' });
  assert.deepEqual(config.services, [{ binding: 'OPS', service: 'apotheke-api', entrypoint: 'Ops' }]);
  const storage = readFileSync(new URL('../storage.jsonc', import.meta.url), 'utf8');
  assert.equal(config.d1_databases.length, 1);
  assert.equal(config.d1_databases[0].binding, 'DB');
  assert.ok(storage.includes(`"database_name": "${config.d1_databases[0].database_name}"`), 'the same D1 as apotheke-api');
  assert.equal(config.d1_databases[0].database_id, undefined);
  assert.equal(config.assets.run_worker_first, true, 'the console files go through the Access check');
});
