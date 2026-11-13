// E3a: two installations side by side on one bucket and one Installation namespace. Each one's storage comes only
// from its own container object's name (ctx.id.name → props), and nothing crosses between them.
// Loaded by r2.test.mjs, which registers the cloudflare:workers stub, so CI's existing node --test command runs it.
import assert from 'node:assert/strict';
import test from 'node:test';
const { doStorage } = await import(new URL('./do-storage.mjs', import.meta.url).href);
const { D1Outbound, R2Outbound, Installation, ApothekeContainer } = await import('../src/index.ts');
const { markerBodyLimit, multipartMarkerKey, sweepExpiredMultipartUploads } = await import('../src/r2.ts');
const { installationPrefix } = await import('../src/installation.ts');

const A = { props: { doName: 'primary', prefix: 'installations/primary/' } };
const B = { props: { doName: 'tenant-b', prefix: 'installations/tenant-b/' } };

/** Just enough of R2 for these paths: objects, list by prefix, multipart begin and abort. */
class Bucket {
  objects = new Map(); uploaded = new Map(); aborted = [];
  async put(key, value, options = {}) {
    if (options.onlyIf?.etagDoesNotMatch === '*' && this.objects.has(key)) return null;
    this.objects.set(key, typeof value === 'string' ? new TextEncoder().encode(value) : new Uint8Array(value));
    return { key };
  }
  async get(key) {
    const b = this.objects.get(key);
    return b ? { size: b.byteLength, etag: 'e', text: async () => new TextDecoder().decode(b), arrayBuffer: async () => b.slice().buffer } : null;
  }
  async head(key) { return this.objects.has(key) ? { etag: 'e', uploaded: new Date(0) } : null; }
  async delete(key) { this.objects.delete(key); }
  async list({ prefix, limit }) {
    const keys = [...this.objects.keys()].filter((k) => k.startsWith(prefix)).sort().slice(0, limit);
    return { objects: keys.map((key) => ({ key, size: this.objects.get(key).byteLength, uploaded: this.uploaded.get(key) })), truncated: false };
  }
  async createMultipartUpload() { return { uploadId: `up-${crypto.randomUUID().replaceAll('-', '')}` }; }
  resumeMultipartUpload(key, id) { return { abort: async () => { this.aborted.push([key, id]); } }; }
}

/** One namespace: ids only from jurisdiction("eu"), one object (own SQLite) per name. */
function namespace(env, names) {
  const objects = new Map();
  const eu = {
    idFromName: (name) => { names.push(name); return { name }; },
    get: (id) => {
      if (!objects.has(id.name)) objects.set(id.name, new Installation({ storage: doStorage() }, env));
      return { fetch: (req) => objects.get(id.name).fetch(req) };
    },
  };
  return { jurisdiction: (j) => { assert.equal(j, 'eu'); return eu; } };
}

function world() {
  const names = [];
  const env = { STORAGE: new Bucket(), DB: {} };
  env.INSTALLATION = namespace(env, names);
  return { env, names, bucket: env.STORAGE };
}
const r2 = (method, path, query = {}, body) => new Request(`http://r2.internal/${path}?${new URLSearchParams(query)}`, { method, body, ...(body === undefined ? {} : { duplex: 'half' }) });
const d1 = (path, body) => new Request(`http://d1.internal${path}`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) });
const json = async (res) => [res.status, await res.json()];

test('E3a: files of one installation are invisible to the other, keys stay under each prefix', async () => {
  const { env, bucket } = world();
  const a = new R2Outbound(A, env), b = new R2Outbound(B, env);
  assert.equal((await a.fetch(r2('PUT', 'media/x.jpg', {}, 'from-a'))).status, 200);
  assert.equal((await b.fetch(r2('PUT', 'media/y.jpg', {}, 'from-b'))).status, 200);
  assert.deepEqual([...bucket.objects.keys()].sort(), ['installations/primary/media/x.jpg', 'installations/tenant-b/media/y.jpg']);
  assert.deepEqual(await json(await b.fetch(r2('GET', 'media/x.jpg'))), [200, { ok: true, found: false }]);
  assert.deepEqual(await json(await a.fetch(r2('GET', 'media/y.jpg', { action: 'head' }))), [200, { ok: true, found: false }]);
  assert.deepEqual((await json(await a.fetch(r2('GET', 'index', { action: 'list' }))))[1].objects, ['media/x.jpg']);
  assert.deepEqual((await json(await b.fetch(r2('GET', 'index', { action: 'list' }))))[1].objects, ['media/y.jpg']);
  await b.fetch(r2('DELETE', 'media/x.jpg'));
  assert.ok(bucket.objects.has('installations/primary/media/x.jpg'));
});

test('E3a: multipart markers and the sweep stay per installation', async () => {
  const { env, bucket } = world();
  const a = new R2Outbound(A, env), b = new R2Outbound(B, env);
  const { upload_id: id } = await (await b.fetch(r2('PUT', 'backups/big.zip', { action: 'begin' }))).json();
  const markers = [...bucket.objects.keys()].filter((k) => k.includes('/.mpu/'));
  assert.equal(markers.length, 1);
  assert.ok(markers[0].startsWith('installations/tenant-b/.mpu/'));
  // primary cannot touch tenant-b's upload by its id.
  assert.equal((await a.fetch(r2('DELETE', 'backups/big.zip', { action: 'abort', upload_id: id }))).status, 400);
  bucket.uploaded.set(markers[0], new Date(0));
  const later = 10 * 24 * 60 * 60 * 1000;
  assert.equal((await sweepExpiredMultipartUploads(bucket, A.props.prefix, later)).examined, 0);
  assert.deepEqual(bucket.aborted, []);
  const swept = await sweepExpiredMultipartUploads(bucket, B.props.prefix, later);
  assert.equal(swept.aborted, 1);
  assert.deepEqual(bucket.aborted, [['installations/tenant-b/backups/big.zip', id]]);
});

test('E3a: each installation\'s database is its own Installation object, named from the EU namespace', async () => {
  const { env, names } = world();
  const a = new D1Outbound(A, env), b = new D1Outbound(B, env);
  assert.equal((await a.fetch(d1('/exec', { sql: 'CREATE TABLE t (v TEXT); INSERT INTO t VALUES (\'a\');' }))).status, 200);
  assert.deepEqual(await json(await a.fetch(d1('/query', { sql: 'SELECT v FROM t' }))), [200, { ok: true, rows: [{ v: 'a' }] }]);
  const [status] = await json(await b.fetch(d1('/query', { sql: 'SELECT v FROM t' })));
  assert.notEqual(status, 200, 'tenant-b has no table t');
  assert.deepEqual([...new Set(names)], ['primary', 'tenant-b']);
});

test('E3a: storage is refused without consistent props', async () => {
  const { env } = world();
  for (const ctx of [{}, { props: {} }, { props: { doName: 'tenant-b', prefix: 'installations/primary/' } }, { props: { doName: '..', prefix: 'installations/../' } }, { props: { doName: 'Primary', prefix: 'installations/Primary/' } }, { props: { doName: '-b', prefix: 'installations/-b/' } }, { props: { doName: 'a--b', prefix: 'installations/a--b/' } }]) {
    assert.equal((await new R2Outbound(ctx, env).fetch(r2('GET', 'media/x.jpg'))).status, 503, JSON.stringify(ctx));
    assert.equal((await new D1Outbound(ctx, env).fetch(d1('/query', { sql: 'SELECT 1' }))).status, 503, JSON.stringify(ctx));
  }
});

test('E3a: the container object wires storage from its own name only, and will not start in storage mode without one', async () => {
  const wired = async (id) => {
    const props = [];
    let started = false;
    const container = {
      running: false, start: () => { started = true; container.running = true; }, setInactivityTimeout: async () => {},
      getTcpPort: () => ({ fetch: async () => new Response('ok') }), interceptOutboundHttp: async () => {},
    };
    const ctx = { id, container, waitUntil() {}, blockConcurrencyWhile: (f) => f(),
      exports: { D1Outbound: (o) => { props.push(['d1', o.props]); return {}; }, R2Outbound: (o) => { props.push(['r2', o.props]); return {}; } } };
    const res = await new ApothekeContainer(ctx, { APP_PASSWORD: 'placeholder', APP_KEY: 'placeholder', STORAGE: {}, DB: {}, INSTALLATION: {} }).fetch(new Request('https://b.example.test/'));
    return { status: res.status, props, started };
  };
  const b = await wired({ name: 'tenant-b' });
  assert.equal(b.status, 200);
  assert.deepEqual(b.props, [['d1', B.props], ['r2', B.props]]);
  const a = await wired({ name: 'primary' });
  assert.deepEqual(a.props, [['d1', A.props], ['r2', A.props]]);
  const none = await wired({});
  assert.equal(none.status, 503);
  assert.equal(none.started, false);
});

test('E3a: a restarted object with its container still running rebinds storage from its own name, or fails closed', async () => {
  const restarted = async (id) => {
    const { env, bucket, names } = world();
    Object.assign(env, { APP_PASSWORD: 'placeholder', APP_KEY: 'placeholder' });
    const handlers = new Map(), started = [], forwarded = [];
    let pending;
    const container = {
      running: true, start: () => started.push(1), setInactivityTimeout: async () => {},
      getTcpPort: () => ({ fetch: async () => { forwarded.push(1); return new Response('ok'); } }),
      interceptOutboundHttp: async (host, handler) => { handlers.set(host, handler); },
    };
    const ctx = { id, container, waitUntil() {}, blockConcurrencyWhile: (f) => (pending = f()),
      exports: { D1Outbound: (o) => new D1Outbound(o, env), R2Outbound: (o) => new R2Outbound(o, env) } };
    const object = new ApothekeContainer(ctx, env);
    await pending;
    const put = await handlers.get('r2.internal').fetch(r2('PUT', 'media/x.jpg', {}, 'x'));
    const query = await handlers.get('d1.internal').fetch(d1('/exec', { sql: 'CREATE TABLE t (v TEXT)' }));
    const res = await object.fetch(new Request('https://b.example.test/'));
    return { put: put.status, query: query.status, res: res.status, keys: [...bucket.objects.keys()], names, started, forwarded };
  };
  // No name (never expected: every object is reached by getByName): both handlers answer 503, nothing is read or
  // written, requests get the 503 and the running container is never forwarded to.
  for (const id of [{}, undefined]) {
    const none = await restarted(id);
    assert.deepEqual({ ...none, names: none.names.length }, { put: 503, query: 503, res: 503, keys: [], names: 0, started: [], forwarded: [] });
  }
  const b = await restarted({ name: 'tenant-b' });
  assert.deepEqual([b.put, b.query, b.res], [200, 200, 200]);
  assert.deepEqual(b.keys, ['installations/tenant-b/media/x.jpg']);
  assert.deepEqual([...new Set(b.names)], ['tenant-b']);
  assert.deepEqual([b.started, b.forwarded], [[], [1]]);
});

test('E3a: primary keeps its prefix, marker keys and marker size bound exactly', async () => {
  assert.equal(installationPrefix('primary'), 'installations/primary/');
  assert.equal(markerBodyLimit(installationPrefix('primary')), 6 * (1024 + 1024) + 'installations/primary/'.length + 15);
  const digest = Buffer.from(await crypto.subtle.digest('SHA-256', new TextEncoder().encode('upload-1'))).toString('hex');
  assert.equal(await multipartMarkerKey('installations/primary/', 'upload-1'), `installations/primary/.mpu/${digest}`);
});
