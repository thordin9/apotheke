import assert from 'node:assert/strict';
import test from 'node:test';
import { CSRF_HEADER, OpsError, createClient, csrfHeaders, installationPath, readApiBase } from './client.ts';

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

test('installationPath keeps only status, plan and q', () => {
  assert.equal(installationPath(), '/installations');
  assert.equal(installationPath({ status: 'active', plan: 'shelf', q: 'north example' }), '/installations?status=active&plan=shelf&q=north+example');
  assert.equal(installationPath({ status: '', plan: '', q: '' }), '/installations');
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

test('a read is same-origin JSON and does not send the CSRF header', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, { installations_by_status: [] }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await client.overview();
  const { input, init } = callsOf(fetchImpl)[0];
  assert.equal(String(input), '/api/ops/v1/overview');
  assert.equal(init?.method, 'GET');
  assert.equal(init?.credentials, 'same-origin');
  const headers = init?.headers as Record<string, string>;
  assert.equal(headers.Accept, 'application/json');
  assert.equal(headers[CSRF_HEADER], undefined);
  assert.equal(init?.body, undefined);
});

test('POST and PUT send X-CSRF: 1 and a JSON body', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, { ok: true }));
  const client = createClient({ fetchImpl, base: 'https://ops.example/api/ops/v1/' });
  await client.write('POST', '/jobs', { kind: 'sleep', idempotency_key: 'once' });
  await client.write('PUT', '/plans/shelf/limits', { limits: [] });
  const calls = callsOf(fetchImpl);
  assert.equal(String(calls[0].input), 'https://ops.example/api/ops/v1/jobs');
  assert.equal(String(calls[1].input), 'https://ops.example/api/ops/v1/plans/shelf/limits');
  for (const call of calls) {
    assert.equal(call.init?.credentials, 'same-origin');
    const headers = call.init?.headers as Record<string, string>;
    assert.equal(headers[CSRF_HEADER], '1');
    assert.equal(headers['Content-Type'], 'application/json');
  }
  assert.equal(calls[0].init?.body, '{"kind":"sleep","idempotency_key":"once"}');
});

test('config is a read of /config', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, { provisioning_enabled: false, limits_enabled: false }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  const config = await client.config();
  assert.deepEqual(config, { provisioning_enabled: false, limits_enabled: false });
  const { input, init } = callsOf(fetchImpl)[0];
  assert.equal(String(input), '/api/ops/v1/config');
  assert.equal(init?.method, 'GET');
  assert.equal((init?.headers as Record<string, string>)[CSRF_HEADER], undefined);
});

test('list and detail paths are encoded', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(200, {}));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await client.installations({ status: 'suspended', q: 'south.example.test' });
  await client.installation('a/b');
  const calls = callsOf(fetchImpl);
  assert.equal(String(calls[0].input), '/api/ops/v1/installations?status=suspended&q=south.example.test');
  assert.equal(String(calls[1].input), '/api/ops/v1/installations/a%2Fb');
});

test('401, 403, 404, 409, 410, 422 and 503 are typed errors and keep the JSON body', async () => {
  const cases = [
    [401, 'unauthorized', 'Your Access session ended, reload to sign in'],
    [403, 'forbidden', 'The request was refused: CSRF, Origin, or a disallowed identity.'],
    [404, 'not_found', 'That record was not found.'],
    [409, 'conflict', 'The request conflicts with the current record.'],
    [410, 'gone', 'This installation has been deleted.'],
    [422, 'invalid', 'The console could not accept that.'],
    [503, 'unavailable', 'The operator API is not configured, or the container cannot be reached.'],
  ] as const;
  for (const [status, kind, message] of cases) {
    const fetchImpl = fakeFetch(() => jsonResponse(status, { error: kind }));
    const client = createClient({ fetchImpl, base: '/api/ops/v1' });
    await assert.rejects(client.overview(), (error: unknown) => {
      assert.ok(error instanceof OpsError);
      assert.equal(error.status, status);
      assert.equal(error.kind, kind);
      assert.equal(error.message, message);
      assert.deepEqual(error.body, { error: kind });
      return true;
    });
  }
});

test('a non-JSON error body still uses the status', async () => {
  const fetchImpl = fakeFetch(() => new Response('nope', { status: 422 }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await assert.rejects(client.overview(), (error: unknown) => {
    assert.ok(error instanceof OpsError);
    assert.equal(error.kind, 'invalid');
    assert.equal(error.body, null);
    return true;
  });
});

test('any other status is unexpected', async () => {
  const fetchImpl = fakeFetch(() => jsonResponse(500, { error: 'down' }));
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await assert.rejects(client.overview(), (error: unknown) => {
    assert.ok(error instanceof OpsError);
    assert.equal(error.status, 500);
    assert.equal(error.kind, 'unexpected');
    return true;
  });
});

test('a failed fetch is a network error', async () => {
  const fetchImpl = fakeFetch(() => {
    throw new TypeError('offline');
  });
  const client = createClient({ fetchImpl, base: '/api/ops/v1' });
  await assert.rejects(client.overview(), (error: unknown) => {
    assert.ok(error instanceof OpsError);
    assert.equal(error.status, 0);
    assert.equal(error.kind, 'network');
    assert.equal(error.message, 'The console could not reach the API.');
    return true;
  });
});
