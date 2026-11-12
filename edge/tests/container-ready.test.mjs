// A timed-out start is probed again on the next request, a failed forward answers 503, every start path
// sets the inactivity timeout, and the readiness wait leaves headroom for a first start with migrations.
// Runs through r2.test.mjs, which registers the cloudflare:workers stub.
import assert from 'node:assert/strict';
import test from 'node:test';

const { ApothekeContainer } = await import('../src/index.ts');

function box({ running, listening, healthy = () => true }) {
  const calls = [];
  const timeouts = [];
  const container = {
    running,
    starts: 0,
    start() { this.starts += 1; this.running = true; },
    setInactivityTimeout: async (ms) => { timeouts.push(ms); },
    monitor: () => new Promise(() => {}),
    interceptOutboundHttp: async () => {},
    getTcpPort: () => ({
      fetch: async (input) => {
        const path = new URL(input.url).pathname;
        calls.push(path);
        if (!listening()) throw new Error('The container is not listening');
        if (path === '/healthz') return new Response('ok', { status: healthy() ? 200 : 503 });
        return new Response('app');
      },
    }),
  };
  const ctx = { container, waitUntil() {}, blockConcurrencyWhile: (f) => f(), exports: { D1Outbound: () => ({}), R2Outbound: () => ({}) } };
  const env = { APP_PASSWORD: 'p', APP_KEY: 'k', STORAGE: { head: async () => null }, DB: {}, INSTALLATION: {}, SLEEP_AFTER_MS: '120000' };
  return { container, calls, timeouts, box: new ApothekeContainer(ctx, env) };
}

// Run fn with a clock that jumps `step` ms per Date.now() call, so the readiness wait ends at once.
async function fastClock(step, fn) {
  const real = Date.now;
  let t = real();
  Date.now = () => (t += step);
  try { return await fn(); } finally { Date.now = real; }
}

const req = () => new Request('https://api.example.test/items');

test('a timed-out start answers 503 and the next request probes again before forwarding', async () => {
  let up = false;
  const { box: b, calls, container } = box({ running: false, listening: () => up });
  const first = await fastClock(20_000, () => b.fetch(req()));
  assert.equal(first.status, 503);
  assert.equal(first.headers.get('Retry-After'), '5');
  assert.ok(container.running, 'the container keeps running after the timeout');
  up = true;
  calls.length = 0;
  const second = await b.fetch(req());
  assert.equal(second.status, 200);
  assert.deepEqual(calls, ['/healthz', '/items']);
  assert.equal(container.starts, 1, 'a running container is not started again');
});

test('a forward to a container that is not listening answers 503, then readiness is checked again', async () => {
  let up = false;
  const { box: b, calls } = box({ running: true, listening: () => up });
  const res = await b.fetch(req());
  assert.equal(res.status, 503);
  assert.equal(res.headers.get('Retry-After'), '5');
  assert.equal(res.headers.get('Cache-Control'), 'no-store');
  up = true;
  calls.length = 0;
  assert.equal((await b.fetch(req())).status, 200);
  assert.deepEqual(calls, ['/healthz', '/items']);
});

test('the readiness wait outlasts a first start with migrations (35 s, past the old 30 s)', async () => {
  const t0 = Date.now();
  const { box: b, container } = box({ running: false, listening: () => true, healthy: () => Date.now() - t0 >= 35_000 });
  const res = await fastClock(1_000, () => b.fetch(req()));
  assert.equal(res.status, 200);
  assert.equal(container.starts, 1);
});

test('a container already running when the Durable Object starts keeps the constructor timeout only', async () => {
  const { box: b, timeouts } = box({ running: true, listening: () => true });
  assert.deepEqual(timeouts, [120000]);
  assert.equal((await b.fetch(req())).status, 200);
  assert.equal((await b.fetch(req())).status, 200);
  assert.deepEqual(timeouts, [120000]);
});

test('a container started outside this Durable Object (deploy or restart) gets the inactivity timeout', async () => {
  const { box: b, timeouts, container, calls } = box({ running: false, listening: () => true });
  assert.deepEqual(timeouts, []);
  container.running = true;
  assert.equal((await b.fetch(req())).status, 200);
  assert.deepEqual(timeouts, [120000]);
  assert.equal(container.starts, 0);
  assert.deepEqual(calls, ['/healthz', '/items']);
});

test('a timed-out start and its retry both set the inactivity timeout', async () => {
  let up = false;
  const { box: b, timeouts } = box({ running: false, listening: () => up });
  await fastClock(20_000, () => b.fetch(req()));
  up = true;
  await b.fetch(req());
  await b.fetch(req());
  assert.deepEqual(timeouts, [120000, 120000]);
});
