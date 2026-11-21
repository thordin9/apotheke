import assert from 'node:assert/strict';
import test from 'node:test';
import { CSRF_HEADER, OpsError, createClient, csrfHeaders, readApiBase, withQuery } from './client.ts';

function callsOf(impl: typeof fetch): { input: RequestInfo | URL; init: RequestInit | undefined }[] {
  return (impl as typeof fetch & { calls: { input: RequestInfo | URL; init: RequestInit | undefined }[] }).calls;
}

function fakeFetch(handler: (input: RequestInfo | URL, init: RequestInit | undefined) => Response | Promise<Response>): typeof fetch {
  const calls: { input: RequestInfo | URL; init: RequestInit | undefined }[] = [];
  const impl = (async (input: RequestInfo | URL, init?: RequestInit) => {
    calls.push({ input, init });
    return handler(input, init);
  }) as typeof fetch;
  (impl as typeof fetch & { calls: typeof calls }).calls = calls;
  return impl;
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}

test('withQuery drops empty values', () => {
  assert.equal(withQuery('/installations', {}), '/installations');
  assert.equal(withQuery('/installations', { status: 'active', plan: '', q: 'north' }), '/installations?status=active&q=north');
});

test('readApiBase defaults to same-origin /api/ops/v1', () => {
  assert.equal(readApiBase({ querySelector: () => null }), '/api/ops/v1');
  assert.equal(readApiBase({ querySelector: () => ({ getAttribute: () => '  ' }) }), '/api/ops/v1');
  assert.equal(readApiBase({ querySelector: () => ({ getAttribute: () => 'https://ops.example/api/ops/v1/' }) }), 'https://ops.example/api/ops/v1');
});

test('csrfHeaders is the fixed header and the only write credential', () => {
  assert.deepEqual(csrfHeaders(), { 'X-CSRF': '1' });
  assert.equal(CSRF_HEADER, 'X-CSRF');
});

test('a read is same-origin JSON, unwraps ok, and does not send X-CSRF', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, { ok: true, revenue: null, as_of: null }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  const overview = await client.overview();
  assert.equal(overview.revenue, null);
  assert.equal(overview.as_of, null);
  const { input, init } = callsOf(fetchImpl)[0];
  assert.equal(String(input), '/api/ops/v1/overview');
  assert.equal(init?.method, 'GET');
  assert.equal(init?.credentials, 'same-origin');
  const headers = init?.headers as Record<string, string>;
  assert.equal(headers.Accept, 'application/json');
  assert.equal(headers[CSRF_HEADER], undefined);
});

test('POST, PUT and DELETE send X-CSRF: 1', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, { ok: true }));
  const client = createClient({ fetchImpl, base: 'https://ops.example/api/ops/v1/' });
  await client.write('POST', '/jobs', { kind: 'sleep' });
  await client.write('PUT', '/plans/shelf/limits', { limits: [] });
  await client.deleteOverride('north', 'items');
  const calls = callsOf(fetchImpl);
  assert.equal(String(calls[0].input), 'https://ops.example/api/ops/v1/jobs');
  assert.equal(calls[0].init?.method, 'POST');
  assert.equal(calls[1].init?.method, 'PUT');
  assert.equal(calls[2].init?.method, 'DELETE');
  assert.equal(calls[2].init?.body, undefined);
  assert.equal(String(calls[2].input), 'https://ops.example/api/ops/v1/installations/north/limits/items');
  for (const call of calls) {
    const headers = call.init?.headers as Record<string, string>;
    assert.equal(headers[CSRF_HEADER], '1');
    assert.equal(call.init?.credentials, 'same-origin');
  }
});

test('config is read once from /config', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, { ok: true, provisioning_enabled: false, limits_enabled: false }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  assert.deepEqual(await client.config(), { ok: true, provisioning_enabled: false, limits_enabled: false });
  assert.equal(String(callsOf(fetchImpl)[0].input), '/api/ops/v1/config');
});

test('list paths keep limit and cursor', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, { ok: true, installations: [], next_cursor: null }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await client.installations({ status: 'suspended', q: 'north.example.test', limit: '2', cursor: 'abc' });
  assert.equal(String(callsOf(fetchImpl)[0].input), '/api/ops/v1/installations?status=suspended&q=north.example.test&limit=2&cursor=abc');
});

test('401, 403, 404, 405, 409, 410, 422 and 503 are typed', async () => {
  const cases = [
    [401, { ok: false, code: 'unauthorized' }, 'unauthorized', 'Your Access session ended, reload to sign in'],
    [403, { ok: false, code: 'csrf' }, 'forbidden', 'The request was refused: CSRF.'],
    [403, { ok: false, code: 'forbidden' }, 'forbidden', 'The request was refused: a disallowed identity.'],
    [403, { ok: false, code: 'primary' }, 'forbidden', 'The request was refused: primary.'],
    [403, { ok: false, code: 'writes_disabled' }, 'forbidden', 'The request was refused: writes are disabled.'],
    [404, { ok: false, code: 'not_found' }, 'not_found', 'That record was not found.'],
    [405, { ok: false, code: 'method_not_allowed' }, 'method', 'That method is not allowed.'],
    [409, { ok: false, code: 'active_admin_exists' }, 'conflict', 'The request conflicts with the current record.'],
    [410, { ok: false, code: 'gone' }, 'gone', 'This installation has been deleted.'],
    [422, { ok: false, code: 'invalid_query', detail: 'limit' }, 'invalid', 'The query is not valid: limit.'],
    [503, { ok: false, code: 'not_configured' }, 'unavailable', 'The operator API is not configured.'],
    [503, { ok: false, code: 'access_unavailable' }, 'unavailable', 'Access is unavailable.'],
    [503, { ok: false, code: 'unavailable', detail: 'backup_failed' }, 'unavailable', 'The container cannot be reached: backup_failed.'],
  ] as const;
  for (const [status, body, kind, message] of cases) {
    const fetchImpl = fakeFetch(() => jsonResponse(status, body));
    const client = createClient({ fetchImpl, base: '/api/ops/v1' });
    await assert.rejects(client.overview(), (error: unknown) => {
      assert.ok(error instanceof OpsError);
      assert.equal(error.status, status);
      assert.equal(error.kind, kind);
      assert.equal(error.code, body.code);
      assert.equal(error.message, message);
      return true;
    });
  }
});

test('a failed fetch is a network error', async () => {
  const fetchImpl = fakeFetch(() => { throw new TypeError('offline'); });
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await assert.rejects(client.overview(), (error: unknown) => {
    assert.ok(error instanceof OpsError);
    assert.equal(error.kind, 'network');
    assert.equal(error.message, 'The console could not reach the API.');
    return true;
  });
});

test('the audit export is a same-origin CSV URL with the filters', () => {
  const client = createClient({ fetchImpl: fakeFetch(() => jsonResponse(200, {})), base: '/api/ops/v1' });
  const path = client.auditExportUrl({ installation: 'north', from: '2026-11-01' });
  assert.equal(path, '/api/ops/v1/audit.csv?installation=north&from=2026-11-01');
  assert.equal(path.startsWith('/'), true);
  assert.equal(/^https?:/i.test(path), false);
  const hosted = createClient({ fetchImpl: fakeFetch(() => jsonResponse(200, {})), base: 'https://ops.example/api/ops/v1' });
  const url = new URL(hosted.auditExportUrl({ installation: 'a b' }));
  assert.equal(url.origin, 'https://ops.example');
  assert.equal(url.pathname, '/api/ops/v1/audit.csv');
  assert.equal(url.searchParams.get('installation'), 'a b');
});

test('ok false on a 2xx is an error', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, { ok: false, code: 'unavailable', detail: 'bad_answer' }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await assert.rejects(client.users('north'), (error: unknown) => {
    assert.ok(error instanceof OpsError);
    assert.equal(error.status, 200);
    assert.equal(error.kind, 'unexpected');
    assert.equal(error.code, 'unavailable');
    assert.equal(error.detail, 'bad_answer');
    return true;
  });
});

test('installation and user paths encode the id', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, { ok: true }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await client.installation('a/b c');
  await client.users('a/b c');
  const calls = callsOf(fetchImpl);
  assert.equal(String(calls[0].input), '/api/ops/v1/installations/a%2Fb%20c');
  assert.equal(String(calls[1].input), '/api/ops/v1/installations/a%2Fb%20c/users');
});

test('a users conflict names the installation state', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(409, { ok: false, code: 'no_host' }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await assert.rejects(client.users('north'), (error: unknown) => {
    assert.ok(error instanceof OpsError);
    assert.equal(error.code, 'no_host');
    assert.equal(error.message, "This installation isn't running or has no host, so its users can't be read.");
    return true;
  });
});
