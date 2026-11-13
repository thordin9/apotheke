// The Worker forwards to the container over http: with redirect:manual, overwrites
// X-Forwarded-Proto/Host, and passes the method, path, query and body through.
import assert from 'node:assert/strict';
import test from 'node:test';

const { ApothekeContainer, firstAllowedHost } = await import('../src/index.ts');

function containerEnv(over = {}) {
  return { APP_PASSWORD: 'p', APP_KEY: 'k', STORAGE: { head: async () => null }, DB: {}, INSTALLATION: {}, ...over };
}

function readyContainer(handler) {
  const calls = [];
  const waitUntil = [];
  const timeouts = [];
  let monitorCalls = 0;
  const container = {
    running: true,
    start() { throw new Error('already running'); },
    setInactivityTimeout: async (ms) => { timeouts.push(ms); },
    monitor: () => { monitorCalls += 1; return new Promise(() => {}); },
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
  const ctx = { id: { name: 'primary' }, container, waitUntil(p) { waitUntil.push(p); }, blockConcurrencyWhile: (f) => f(), exports: { D1Outbound: () => ({}), R2Outbound: () => ({}) } };
  return { ctx, calls, waitUntil, timeouts, monitor: () => monitorCalls, box: new ApothekeContainer(ctx, containerEnv()) };
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

test('a spoofed X-Apotheke-Client-IP is replaced from cf-connecting-ip', async () => {
  const { box, calls } = readyContainer(() => new Response('ok'));
  await box.fetch(new Request('https://api.example.test/login', {
    headers: { 'X-Apotheke-Client-IP': '203.0.113.1', 'cf-connecting-ip': '198.51.100.20' },
  }));
  assert.equal(calls[0].headers.get('X-Apotheke-Client-IP'), '198.51.100.20');
});

test('a missing cf-connecting-ip drops a client-supplied X-Apotheke-Client-IP', async () => {
  const { box, calls } = readyContainer(() => new Response('ok'));
  await box.fetch(new Request('https://api.example.test/login', {
    headers: { 'X-Apotheke-Client-IP': '203.0.113.1' },
  }));
  assert.equal(calls[0].headers.get('X-Apotheke-Client-IP'), null);
});

function coldContainer(handler, envOver = {}) {
  const calls = [];
  const waitUntil = [];
  const timeouts = [];
  let monitorCalls = 0;
  const container = {
    running: false,
    start(opts) { this.started = opts; this.running = true; },
    setInactivityTimeout: async (ms) => { timeouts.push(ms); },
    monitor: () => { monitorCalls += 1; return new Promise(() => {}); },
    interceptOutboundHttp: async () => {},
    getTcpPort: (port) => {
      assert.equal(port, 9091);
      return {
        fetch: async (input, init) => {
          const url = String(input?.url ?? input);
          const headers = input?.headers;
          calls.push({ input, init, url, headers });
          const host = new URL(url).host;
          // The app's Host allow-list rejects Host "container" with 421.
          // Stop the mock container so a reverted probe does not wait 30 s.
          if (host === 'container') {
            container.running = false;
            return new Response('Unknown host name.\n', { status: 421 });
          }
          return handler(input, init, { url, headers, host });
        },
      };
    },
  };
  const ctx = { id: { name: 'primary' }, container, waitUntil(p) { waitUntil.push(p); }, blockConcurrencyWhile: (f) => f(), exports: { D1Outbound: () => ({}), R2Outbound: () => ({}) } };
  return { ctx, calls, container, waitUntil, timeouts, monitor: () => monitorCalls, box: new ApothekeContainer(ctx, containerEnv(envOver)) };
}

test('the readiness probe uses the first ALLOWED_HOSTS entry, not Host container', async () => {
  const { box, calls } = coldContainer(() => new Response('ok', { status: 200 }), {
    ALLOWED_HOSTS: 'api.services.getapotheke.com, preview.example.test',
  });
  const res = await box.fetch(new Request('https://api.services.getapotheke.com/login'));
  assert.equal(res.status, 200);
  assert.ok(calls.length >= 1, 'the probe ran');
  const probe = calls[0];
  assert.equal(probe.url, 'http://api.services.getapotheke.com/healthz');
  assert.notEqual(new URL(probe.url).host, 'container');
  // Node's Request hides Host; the URL host is what the TCP fetch sends as Host.
  assert.equal(new URL(probe.url).host, 'api.services.getapotheke.com');
  assert.equal(probe.headers.get('X-Forwarded-Host'), 'api.services.getapotheke.com');
  assert.equal(probe.headers.get('X-Forwarded-Proto'), 'https');
  assert.equal(probe.init?.redirect, 'manual');
  assert.equal(calls[calls.length - 1].url, 'http://api.services.getapotheke.com/login');
});

test('an empty ALLOWED_HOSTS readiness probe uses 127.0.0.1 and logs the missing config', async () => {
  const warnings = [];
  const original = console.warn;
  console.warn = (...args) => { warnings.push(args.map(String).join(' ')); };
  try {
    for (const allowed of ['', undefined, '  ,  ', '*']) {
      const env = allowed === undefined ? {} : { ALLOWED_HOSTS: allowed };
      const { box, calls } = coldContainer(() => new Response('ok', { status: 200 }), env);
      const res = await box.fetch(new Request('https://api.example.test/healthz'));
      assert.equal(res.status, 200, String(allowed));
      assert.equal(calls[0].url, 'http://127.0.0.1/healthz', String(allowed));
      assert.notEqual(new URL(calls[0].url).host, 'container');
      assert.equal(calls[0].headers.get('X-Forwarded-Host'), '127.0.0.1');
      assert.equal(calls[0].headers.get('X-Forwarded-Proto'), 'https');
    }
  } finally {
    console.warn = original;
  }
  assert.ok(warnings.some((w) => w.includes('ALLOWED_HOSTS') && w.includes('127.0.0.1')));
});

test('firstAllowedHost takes the first configured name', () => {
  assert.equal(firstAllowedHost('api.services.getapotheke.com, preview.example.test'), 'api.services.getapotheke.com');
  assert.equal(firstAllowedHost('  preview.example.test   api.example.test'), 'preview.example.test');
  assert.equal(firstAllowedHost('*, api.example.test'), 'api.example.test');
  assert.equal(firstAllowedHost(''), undefined);
  assert.equal(firstAllowedHost('  , * '), undefined);
});

test('a cold start sets the inactivity timeout and does not hold monitor or waitUntil', async () => {
  const { box, waitUntil, timeouts, monitor } = coldContainer(() => new Response('ok', { status: 200 }), {
    SLEEP_AFTER_MS: '120000',
  });
  assert.equal((await box.fetch(new Request('https://api.example.test/items'))).status, 200);
  assert.deepEqual(timeouts, [120000]);
  assert.equal(monitor(), 0);
  assert.equal(waitUntil.length, 0);
});

test('an unset SLEEP_AFTER_MS uses a 15 minute inactivity timeout', async () => {
  const { box, timeouts, monitor, waitUntil } = coldContainer(() => new Response('ok', { status: 200 }));
  assert.equal((await box.fetch(new Request('https://api.example.test/items'))).status, 200);
  assert.deepEqual(timeouts, [15 * 60 * 1000]);
  assert.equal(monitor(), 0);
  assert.equal(waitUntil.length, 0);
});

test('the readiness probe and a later request do not call monitor or waitUntil', async () => {
  const { box, calls, waitUntil, timeouts, monitor } = coldContainer(() => new Response('ok', { status: 200 }), {
    ALLOWED_HOSTS: 'api.example.test',
    SLEEP_AFTER_MS: '120000',
  });
  assert.equal((await box.fetch(new Request('https://api.example.test/login'))).status, 200);
  assert.equal(calls[0].url, 'http://api.example.test/healthz');
  assert.equal((await box.fetch(new Request('https://api.example.test/items'))).status, 200);
  assert.equal(calls[calls.length - 1].url, 'http://api.example.test/items');
  // The timeout is set on start, not renewed by the probe or the second request.
  // A later user request is still forwarded; the platform resets the timeout when the DO is active.
  assert.deepEqual(timeouts, [120000]);
  assert.equal(monitor(), 0);
  assert.equal(waitUntil.length, 0);
});

test('a warm Durable Object sets the inactivity timeout in the constructor and does not hold monitor', async () => {
  const { box, waitUntil, timeouts, monitor } = readyContainer(() => new Response('ok', { status: 200 }));
  assert.deepEqual(timeouts, [15 * 60 * 1000]);
  assert.equal(monitor(), 0);
  assert.equal(waitUntil.length, 0);
  assert.equal((await box.fetch(new Request('https://api.example.test/items'))).status, 200);
  assert.deepEqual(timeouts, [15 * 60 * 1000]);
  assert.equal(monitor(), 0);
  assert.equal(waitUntil.length, 0);
});
