// The Worker forwards to the container over http: with redirect:manual, overwrites
// X-Forwarded-Proto/Host, and passes the method, path, query and body through.
import assert from 'node:assert/strict';
import test from 'node:test';

const { ApothekeContainer } = await import('../src/index.ts');

function containerEnv(over = {}) {
  return { APP_PASSWORD: 'p', APP_KEY: 'k', STORAGE: { head: async () => null }, DB: {}, INSTALLATION: {}, ...over };
}

function readyContainer(handler) {
  const calls = [];
  const container = {
    running: true,
    start() { throw new Error('already running'); },
    setInactivityTimeout: async () => {},
    monitor: () => new Promise(() => {}),
    interceptOutboundHttp: async () => {},
    getTcpPort: (port) => {
      assert.equal(port, 9091);
      return {
        fetch: async (input, init) => {
          calls.push({ input, init, url: String(input?.url ?? input), method: input?.method, headers: input?.headers, body: input?.body ?? null });
          return handler(input, init, calls[calls.length - 1]);
        },
      };
    },
  };
  const ctx = { container, waitUntil() {}, blockConcurrencyWhile: (f) => f(), exports: { D1Outbound: () => ({}), R2Outbound: () => ({}) } };
  return { ctx, calls, box: new ApothekeContainer(ctx, containerEnv()) };
}

test('the container fetch is http, keeps path and query, and uses redirect:manual', async () => {
  const { box, calls } = readyContainer(() => new Response('ok', { status: 200 }));
  const res = await box.fetch(new Request('https://api.example.test/items?q=drill#ignored'));
  assert.equal(res.status, 200);
  assert.equal(await res.text(), 'ok');
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://api.example.test/items?q=drill');
  assert.match(calls[0].url, /^http:/);
  assert.equal(calls[0].init?.redirect, 'manual');
  assert.equal(calls[0].input.redirect, 'manual');
});

test('a 303 from the container is returned with its Location and is not followed', async () => {
  const { box, calls } = readyContainer((input, init) => {
    const redirect = init?.redirect ?? input?.redirect ?? 'follow';
    if (redirect === 'manual') {
      return new Response(null, { status: 303, headers: { Location: 'https://api.example.test/login' } });
    }
    return new Response('followed', { status: 200 });
  });
  const res = await box.fetch(new Request('https://api.example.test/'));
  assert.equal(res.status, 303);
  assert.equal(res.headers.get('Location'), 'https://api.example.test/login');
  assert.equal(await res.text(), '');
  assert.equal(calls[0].init?.redirect, 'manual');
});

test('a spoofed X-Forwarded-Proto and Host are overwritten from the original request', async () => {
  const { box, calls } = readyContainer(() => new Response('ok'));
  await box.fetch(new Request('https://api.example.test/login', {
    headers: { 'X-Forwarded-Proto': 'http', 'X-Forwarded-Host': 'evil.example' },
  }));
  const h = calls[0].headers;
  assert.equal(h.get('X-Forwarded-Proto'), 'https');
  assert.equal(h.get('X-Forwarded-Host'), 'api.example.test');
  assert.equal(calls[0].url, 'http://api.example.test/login');
});

test('the method and body pass through to the container', async () => {
  const { box, calls } = readyContainer(async (input) => {
    const text = await new Response(input.body).text();
    return new Response(text, { status: 201, headers: { 'Content-Type': 'application/json' } });
  });
  const res = await box.fetch(new Request('https://api.example.test/api/v1/session', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: '{"username":"ada"}',
  }));
  assert.equal(res.status, 201);
  assert.equal(await res.text(), '{"username":"ada"}');
  assert.equal(calls[0].method, 'POST');
  assert.equal(calls[0].headers.get('Content-Type'), 'application/json');
  assert.equal(calls[0].url, 'http://api.example.test/api/v1/session');
});

test('an http Worker request forwards http as the proto', async () => {
  const { box, calls } = readyContainer(() => new Response('ok'));
  await box.fetch(new Request('http://localhost:8787/healthz'));
  assert.equal(calls[0].url, 'http://localhost:8787/healthz');
  assert.equal(calls[0].headers.get('X-Forwarded-Proto'), 'http');
  assert.equal(calls[0].headers.get('X-Forwarded-Host'), 'localhost:8787');
});
