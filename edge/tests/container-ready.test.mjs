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
    destroys: 0,
    start() { this.starts += 1; this.running = true; },
    destroy: async () => { container.destroys += 1; container.running = false; },
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
  const ctx = { id: { name: 'primary' }, container, waitUntil() {}, blockConcurrencyWhile: (f) => f(), exports: { D1Outbound: () => ({}), R2Outbound: () => ({}) } };
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

// A virtual clock: Date.now() reads it and each setTimeout moves it forward by its delay, so a 90 s wait
// runs in a moment and ends where the real one would (100 ms per probe).
async function virtualClock(fn) {
  const [realNow, realTimeout] = [Date.now, globalThis.setTimeout];
  let t = realNow();
  const t0 = t;
  Date.now = () => t;
  globalThis.setTimeout = (f, ms = 0) => { t += ms; return setImmediate(f); };
  try { return await fn(() => t - t0); } finally { Date.now = realNow; globalThis.setTimeout = realTimeout; }
}

test('concurrent requests during a start share one readiness wait', async () => {
  let probes = 0;
  const { box: b, container, timeouts, calls } = box({ running: false, listening: () => true, healthy: () => ++probes > 2 });
  const res = await Promise.all([b.fetch(req()), b.fetch(req()), b.fetch(req())]);
  assert.deepEqual(res.map((r) => r.status), [200, 200, 200]);
  assert.equal(container.starts, 1);
  assert.deepEqual(timeouts, [120000]);
  assert.equal(calls.filter((p) => p === '/healthz').length, 3, 'one probe loop: two misses, then 200');
});

test('a shared wait that times out answers 503 to every waiter and is not reused', async () => {
  let up = false;
  const { box: b, calls } = box({ running: false, listening: () => up });
  const res = await virtualClock(() => Promise.all([b.fetch(req()), b.fetch(req())]));
  assert.deepEqual(res.map((r) => r.status), [503, 503]);
  up = true;
  calls.length = 0;
  assert.equal((await b.fetch(req())).status, 200);
  assert.deepEqual(calls, ['/healthz', '/items']);
});

test('a running container that never becomes healthy is destroyed after a second timed-out wait', async () => {
  let healthy = false;
  const { box: b, container } = box({ running: false, listening: () => true, healthy: () => healthy });
  assert.equal((await virtualClock(() => b.fetch(req()))).status, 503);
  assert.equal(container.destroys, 0, 'the first timed-out wait keeps the container (a slow first start)');
  assert.equal((await virtualClock(() => b.fetch(req()))).status, 503);
  assert.equal(container.destroys, 1, 'the second one in a row destroys it');
  assert.equal(container.running, false);
  healthy = true;
  assert.equal((await b.fetch(req())).status, 200);
  assert.equal(container.starts, 2, 'the next request starts a fresh container');
});

test('a failed forward answers the fixed 503 body, not the error text', async () => {
  const { box: b } = box({ running: true, listening: () => false });
  const res = await b.fetch(req());
  assert.equal(res.status, 503);
  assert.equal(await res.text(), 'Apotheke is not available right now.\n');
});

test('the readiness wait is 90 s', async () => {
  for (const [healthyAt, status] of [[89_900, 200], [90_100, 503]]) {
    const { box: b } = box({ running: false, listening: () => true, healthy: () => Date.now() - start >= healthyAt });
    let start;
    const res = await virtualClock((elapsed) => { start = Date.now(); return b.fetch(req()).then((r) => [r, elapsed()]); });
    assert.equal(res[0].status, status, `healthy after ${healthyAt} ms`);
    if (status === 503) assert.ok(res[1] >= 90_000 && res[1] < 90_200, `gave up after ${res[1]} ms`);
  }
});
