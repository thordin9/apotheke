import assert from 'node:assert/strict';
import test from 'node:test';
import { handleR2Request, r2UnboundResponse, validKey, validateRawPath } from '../src/r2.ts';
import { register } from 'node:module';
import { readFileSync } from 'node:fs';
import net from 'node:net';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
const execFileAsync = promisify(execFile);
const workerStub = 'export class DurableObject { constructor(ctx, env) { this.ctx = ctx; this.env = env; } } export class WorkerEntrypoint { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }';
register('data:text/javascript,' + encodeURIComponent(`export async function resolve(s, c, next) {
  if (s === 'cloudflare:workers') return { url: 'data:text/javascript,${encodeURIComponent(workerStub)}', shortCircuit: true };
  if (s.startsWith('./') && !s.endsWith('.ts') && c.parentURL) return next(s + '.ts', c);
  return next(s, c);
}`));
const { R2Outbound } = await import('../src/index.ts');

class Upload {
  constructor(bucket, key, id) { this.bucket = bucket; this.key = key; this.uploadId = id; }
  async uploadPart(number, body) {
    if (number !== (this.bucket.uploads.get(this.uploadId) ?? []).length + 1) throw new Error('invalid multipart upload');
    const bytes = body instanceof Uint8Array ? body : new Uint8Array(await new Response(body).arrayBuffer());
    this.bucket.partReads.push(bytes.byteLength);
    this.bucket.parts.set(`${this.uploadId}:${number}`, bytes);
    const uploaded = { partNumber: number, etag: crypto.randomUUID().replaceAll('-', '') };
    this.bucket.uploads.set(this.uploadId, [...(this.bucket.uploads.get(this.uploadId) ?? []), uploaded]);
    return uploaded;
  }
  async complete(parts) {
    assert.deepEqual(parts, this.bucket.uploads.get(this.uploadId) ?? []);
    const sorted = [...parts].sort((a, b) => a.partNumber - b.partNumber);
    this.bucket.objects.set(this.key, new Uint8Array(sorted.reduce((n, part) => n + this.bucket.parts.get(`${this.uploadId}:${part.partNumber}`).byteLength, 0)));
    this.bucket.completed.push(this.key);
  }
  async abort() { this.bucket.aborted.push(this.uploadId); }
}
class Bucket {
  uploads = new Map();
  objects = new Map(); parts = new Map(); sessions = new Map(); prefix = ''; partReads = []; completed = []; aborted = []; failAt = 0;
  assertKey(key) { assert.ok(new TextEncoder().encode(key).byteLength <= 1024, 'bucket key exceeds 1024 bytes'); }
  async put(key, value, options = {}) {
    this.assertKey(key);
    const current = this.etags.get(key);
    if (options.onlyIf?.etagDoesNotMatch === '*' && this.objects.has(key)) return null;
    if (options.onlyIf?.etagMatches !== undefined && options.onlyIf.etagMatches !== current) return null;
    this.etags.set(key, `etag-${this.etags.size + 1}`);
    this.objects.set(key, typeof value === "string" ? new TextEncoder().encode(value) : new Uint8Array(value));
    return { key };
  }
  etags = new Map();
  async get(key) {
    this.assertKey(key);
    const data = this.objects.get(key);
    if (data === undefined) return null;
    if (!this.etags.has(key)) this.etags.set(key, `etag-${this.etags.size + 1}`);
    const etag = this.etags.get(key);
    const bytes = data.slice();
    return { size: bytes.byteLength, etag, text: async () => new TextDecoder().decode(bytes), arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) };
  }
  async head(key) { this.assertKey(key); const data = this.objects.get(key); if (data !== undefined && !this.etags.has(key)) this.etags.set(key, `etag-${this.etags.size + 1}`); return data ? { uploaded: new Date(1740000000000), etag: this.etags.get(key) } : null; }
  async delete(key) { this.assertKey(key); this.objects.delete(key); }
  async list({ prefix, limit, cursor }) { this.assertKey(prefix); this.prefix = prefix; const keys = [...this.objects.keys()].filter((key) => key.startsWith(prefix)).sort(); const start = cursor ? Number(cursor) : 0; const slice = keys.slice(start, start + limit); return { objects: slice.map((key) => ({ key })), truncated: start + limit < keys.length, cursor: String(start + limit) }; }
  async createMultipartUpload(key) { const id = `upload-${crypto.randomUUID().replaceAll('-', '')}`; this.sessions.set(id, key); return { uploadId: id }; }
  resumeMultipartUpload(key, id) { return new Upload(this, key, id); }
}
const replenish = () => {
  let n = 0;
  return new ReadableStream({ start(controller) { while (n < 20) { controller.enqueue(new Uint8Array(256 * 1024)); n += 1; } controller.close(); } });
};
function request(method = 'GET', path = 'file.bin', query = {}, body) { return new Request(`http://r2.internal/${path}?${new URLSearchParams(query)}`, { method, body, ...(body === undefined ? {} : { duplex: 'half' }) }); }

test('application keys must be safe before any bucket call', () => {
  for (const path of ['', '/a', 'a/../b', 'a//b', 'a\\b', '../x', 'a/.', 'a?b', 'a#b', 'a\n', '.mpu', '.mpu/upload-id', 'folder/.mpu/id']) assert.equal(validKey(path), false, path);
  assert.equal(validateRawPath('/a/%2e%2e/secret'), undefined);
  for (const path of ['a', 'media/one+two ü.txt', 'a.b']) assert.equal(validKey(path), true);
});

test('Worker alone prefixes reads, deletes, and scoped listings', async () => {
  const bucket = new Bucket();
  bucket.objects.set('installations/primary/media/a', new Uint8Array([1, 2, 255]));
  bucket.objects.set('installations/primary/media/b', new Uint8Array());
  bucket.objects.set('other/tenant/secret', new Uint8Array([99]));
  const read = await (await handleR2Request(request('GET', 'media/a'), bucket)).json();
  assert.deepEqual(read, { ok: true, found: true, bytes: 'AQL/', etag: 'etag-1' });
  const head = await (await handleR2Request(request('HEAD', 'media/a'), bucket)).json();
  assert.deepEqual(head, { ok: true, found: true, modified: 1740000000, etag: 'etag-1' });
  assert.deepEqual(await (await handleR2Request(request('GET', 'index', { action: 'list', prefix: 'media', limit: '1' }), bucket)).json(), { ok: true, objects: ['media/a'], cursor: '1' });
  const next = await handleR2Request(request('GET', 'index', { action: 'list', prefix: 'media', limit: '1', cursor: '1' }), bucket);
  assert.deepEqual(await next.json(), { ok: true, objects: ['media/b'], cursor: null });
  assert.equal(bucket.prefix, 'installations/primary/media');
  assert.equal((await handleR2Request(request(), new Bucket())).status, 200);
  assert.deepEqual(await (await handleR2Request(request('DELETE', 'media/a'), bucket)).json(), { ok: true });
});

test('application listing filtering uses the shared dot-segment predicate', async () => {
  const bucket = new Bucket();
  for (const key of ['.mpu/upload', '.hidden/id', 'nested/.internal/id', '.dot']) bucket.objects.set(`installations/primary/${key}`, new TextEncoder().encode('{"id":"private","key":"private"}'));
  bucket.objects.set('installations/primary/media/a', new Uint8Array([1]));
  const response = await handleR2Request(request('GET', 'index', { action: 'list' }), bucket);
  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), { ok: true, objects: ['media/a'], cursor: null });
  const malformed = new Bucket();
  malformed.list = async () => ({ objects: [{ key: 'installations/primary/bad//unsafe' }], truncated: false });
  assert.equal((await handleR2Request(request('GET', 'index', { action: 'list' }), malformed)).status, 502);
});

test('manifest replacements require the exact observed ETag', async () => {
  const bucket = new Bucket();
  const worker = new R2Outbound({}, { STORAGE: bucket });
  const path = 'backups/manifest.json';
  assert.equal((await worker.fetch(request('PUT', path, {}, 'one'))).status, 200);
  const head = await (await worker.fetch(request('HEAD', path))).json();
  assert.equal(head.etag, 'etag-1');
  const first = await (await worker.fetch(request('GET', path))).json();
  assert.deepEqual(Object.keys(first), ['ok', 'found', 'bytes', 'etag']);
  assert.equal(first.etag, head.etag);
  assert.equal((await worker.fetch(request('PUT', path, { if_match: head.etag }, 'two'))).status, 200);
  assert.equal((await worker.fetch(request('PUT', path, { if_match: head.etag }, 'wrong'))).status, 412);
  const object = await (await worker.fetch(request('GET', path))).json();
  assert.equal(atob(object.bytes), 'two');
  assert.equal(object.etag, 'etag-2');
});

test('one object read keeps its own etag when a later lookup sees a replacement', async () => {
  const bucket = new Bucket();
  const key = 'installations/primary/backups/manifest.json';
  await bucket.put(key, 'body-A');
  const realGet = bucket.get.bind(bucket);
  const realPut = bucket.put.bind(bucket);
  const calls = [];
  bucket.get = async (objectKey) => {
    calls.push(['get', objectKey]);
    const object = await realGet(objectKey);
    if (calls.length !== 1) return object;
    let etag = object.etag;
    const bytes = await object.arrayBuffer();
    await realPut(objectKey, 'body-B');
    return {
      get etag() { return etag; },
      size: bytes.byteLength,
      arrayBuffer: async () => {
        etag = 'etag-replaced-during-body';
        return bytes;
      },
      text: async () => new TextDecoder().decode(bytes),
    };
  };
  bucket.head = async (objectKey) => {
    calls.push(['head', objectKey]);
    return null;
  };
  const read = await (await handleR2Request(request('GET', 'backups/manifest.json'), bucket)).json();
  assert.equal(atob(read.bytes), 'body-A');
  assert.equal(read.etag, 'etag-1');
  assert.deepEqual(calls, [['get', key]]);
  const later = await realGet(key);
  assert.equal(await later.text(), 'body-B');
  assert.equal(later.etag, 'etag-2');
});

test('real transport rejects a stale conditional write after the read response', { timeout: 20000 }, async () => {
  const bucket = new Bucket();
  const worker = new R2Outbound({}, { STORAGE: bucket });
  const relative = 'backups/manifest.json';
  const objectKey = `installations/primary/${relative}`;
  await bucket.put(objectKey, 'body-A');
  const realGet = bucket.get.bind(bucket);
  const realHead = bucket.head.bind(bucket);
  const realPut = bucket.put.bind(bucket);
  const ops = [];
  bucket.get = async (key) => { ops.push(['get', key]); return realGet(key); };
  bucket.head = async (key) => { ops.push(['head', key]); return realHead(key); };
  bucket.put = async (key, value, options = {}) => {
    ops.push(['put', key, options.onlyIf?.etagMatches ?? null]);
    return realPut(key, value, options);
  };
  let releaseNext = () => {};
  let nextAllowed = new Promise((resolve) => { releaseNext = resolve; });
  const seen = [];
  const sockets = new Set();
  const server = net.createServer((socket) => {
    sockets.add(socket);
    socket.on('close', () => sockets.delete(socket));
    readHttpRequest(socket).then(async (incoming) => {
      const turn = seen.length + 1;
      if (turn > 1) await nextAllowed;
      const url = new URL(incoming.target, 'http://r2.internal');
      const forwarded = new Request(url, { method: incoming.method, ...(incoming.body.length ? { body: incoming.body, duplex: 'half' } : {}) });
      const result = await worker.fetch(forwarded);
      const payload = Buffer.from(await result.arrayBuffer());
      await writeHttpResponse(socket, result.status, payload);
      seen.push(`${incoming.method} ${url.pathname}`);
      if (turn === 1) {
        await bucket.put(objectKey, 'body-B');
        releaseNext();
      }
    }).catch((error) => {
      socket.destroy(error);
      releaseNext();
    });
  });
  server.listen(0, '127.0.0.1');
  await new Promise((resolve) => server.once('listening', resolve));
  try {
    const { stdout } = await execFileAsync('php', [
      new URL('r2-version-coherence-client.php', import.meta.url).pathname,
      `http://127.0.0.1:${server.address().port}`,
    ], { timeout: 15000 });
    const actual = JSON.parse(stdout.trim());
    assert.equal(actual.replaced, false);
    assert.deepEqual(actual.version, ['body-A', 'etag-1']);
    assert.deepEqual(seen, ['GET /backups/manifest.json', 'PUT /backups/manifest.json']);
    assert.deepEqual(ops.filter((op) => op[0] === 'head'), []);
    assert.deepEqual(ops.filter((op) => op[0] === 'get'), [['get', objectKey]]);
    assert.deepEqual(ops.filter((op) => op[2]), [['put', objectKey, 'etag-1']]);
    const finalObject = await realGet(objectKey);
    assert.equal(await finalObject.text(), 'body-B');
  } finally {
    for (const socket of sockets) socket.destroy();
    await new Promise((resolve) => server.close(resolve));
  }
});

test('only installation-local atomic conditional create is permitted', async () => {
  const bucket = new Bucket();
  const fixture = { if_none_match: '*' };
  const a = handleR2Request(request('PUT', 'photo.jpg', fixture, new Uint8Array([2])), bucket);
  const b = handleR2Request(request('PUT', 'photo.jpg', fixture, new Uint8Array([3])), bucket);
  const statuses = (await Promise.all([a, b])).map((response) => response.status).sort();
  assert.deepEqual(statuses, [200, 412]);
  assert.equal(bucket.objects.has('installations/other/photo.jpg'), false);
  assert.equal((await handleR2Request(request('PUT', 'photo.jpg', { if_none_match: 'wrong' }, 'a'), bucket)).status, 400);
  assert.equal((await handleR2Request(request('PUT', 'photo.jpg', { action: 'begin', if_none_match: '*' }), bucket)).status, 400);
});

test('multipart forwards bounded streams and completes in source order', async () => {
  const bucket = new Bucket(); let id;
  const begin = await handleR2Request(request('PUT', 'a.bin', { action: 'begin' }), bucket);
  ({ upload_id: id } = await begin.json());
  const part = request('PUT', 'a.bin', { action: 'part', upload_id: id, part: '1', size: String(5 * 1024 * 1024) }, replenish());
  const upload = await handleR2Request(part, bucket);
  assert.deepEqual(await upload.json(), { ok: true, bytes: 5 * 1024 * 1024, part_number: 1, etag: bucket.uploads.get(id)[0].etag });
  assert.deepEqual(bucket.partReads, [5 * 1024 * 1024]);
  assert.equal((await handleR2Request(request('PUT', 'a.bin', { action: 'complete', upload_id: id, parts: JSON.stringify(bucket.uploads?.get(id) ?? []) }), bucket)).status, 200);
  assert.equal(bucket.objects.get('installations/primary/a.bin').byteLength, 5 * 1024 * 1024);
});

test('wrong and overlong part bodies never reach the bucket', async () => {
  const bucket = new Bucket();
  const response = await handleR2Request(request('PUT', 'a.bin', { action: 'begin' }), bucket);
  const { upload_id: id } = await response.json();
  assert.equal((await handleR2Request(request('PUT', 'a.bin', { action: 'part', upload_id: id, part: '2', size: '1' }, 'a'), bucket)).status, 500);
  assert.equal((await handleR2Request(request('PUT', 'a.bin', { action: 'part', upload_id: id, part: '1', size: '0' }, 'ab'), bucket)).status, 400);
  assert.equal((await handleR2Request(request('PUT', 'a.bin', { action: 'part', upload_id: id, part: '1', size: '5242881' }, replenish()), bucket)).status, 400);
  assert.deepEqual(bucket.partReads, []);
  assert.equal((await handleR2Request(request('DELETE', 'a.bin', { action: 'abort', upload_id: id }), bucket)).status, 200);
  assert.equal(bucket.aborted.length, 1);
});

test('unsafe paths, missing caps, and absent R2 fail generically', async () => {
  assert.equal((await handleR2Request(request('GET', 'a/%2e%2e/secret'), new Bucket())).status, 200);
  assert.equal((await handleR2Request(request('POST'), new Bucket())).status, 405);
  assert.equal((await handleR2Request(request('PUT', 'a.bin', { action: 'part', upload_id: 'a', part: 'abc', size: '1' }, 'a'), new Bucket())).status, 400);
  assert.equal(r2UnboundResponse().status, 503);
  const bucket = new Bucket(); bucket.list = async () => ({ objects: [{ key: 'outside' }], truncated: false });
  assert.equal((await handleR2Request(request('GET', 'index', { action: 'list' }), bucket)).status, 502);
});

test('the real entrypoint resumes begin, parts, and complete without request sessions', async () => {
  const bucket = new Bucket();
  const outbound = () => new R2Outbound({}, { STORAGE: bucket });
  const begin = await outbound().fetch(request('PUT', 'a.bin', { action: 'begin' }));
  assert.equal(begin.status, 200);
  const { upload_id: id } = await begin.json();
  const uploaded = [];
  for (const [part, body] of [new Uint8Array(5 * 1024 * 1024).fill(3), new TextEncoder().encode('tail')].entries()) {
    const response = await outbound().fetch(request('PUT', 'a.bin', { action: 'part', upload_id: id, part: String(part + 1), size: String(body.byteLength) }, body));
    assert.equal(response.status, 200);
    const json = await response.json();
    uploaded.push({ partNumber: json.part_number, etag: json.etag });
  }
  const complete = await outbound().fetch(request('PUT', 'a.bin', { action: 'complete', upload_id: id, parts: JSON.stringify(uploaded) }));
  assert.equal(complete.status, 200);
  assert.equal(bucket.objects.get('installations/primary/a.bin').byteLength, 5 * 1024 * 1024 + 4);
  assert.deepEqual(bucket.aborted, []);
});

test('the real entrypoint aborts a resumed upload and confines its installation key', async () => {
  const bucket = new Bucket();
  const outbound = () => new R2Outbound({}, { STORAGE: bucket });
  const begin = await outbound().fetch(request('PUT', 'a.bin', { action: 'begin' }));
  const { upload_id: id } = await begin.json();
  assert.equal((await outbound().fetch(request('DELETE', 'other.bin', { action: 'abort', upload_id: id }))).status, 400);
  assert.equal((await outbound().fetch(request('PUT', 'other.bin', { action: 'part', upload_id: id, part: '1', size: '1' }, 'x'))).status, 400);
  assert.deepEqual(bucket.partReads, []);
  assert.deepEqual(bucket.aborted, []);
  assert.equal((await outbound().fetch(request('DELETE', 'a.bin', { action: 'abort', upload_id: id }))).status, 200);
  assert.deepEqual(bucket.aborted, [id]);
});

test('multipart rejects a real body over five MiB with a matching declared length', async () => {
  const bucket = new Bucket();
  const begin = await handleR2Request(request('PUT', 'huge.bin', { action: 'begin' }), bucket);
  const { upload_id: id } = await begin.json();
  const body = new Uint8Array(5 * 1024 * 1024 + 1);
  const response = await handleR2Request(request('PUT', 'huge.bin', { action: 'part', upload_id: id, part: '1', size: String(body.byteLength) }, body), bucket);
  assert.equal(response.status, 400);
  assert.deepEqual(bucket.partReads, []);
});

test('a permissive bucket cannot cross multipart keys or reuse finished uploads', async () => {
  const bucket = new Bucket();
  const begin = await handleR2Request(request('PUT', 'first.bin', { action: 'begin' }), bucket);
  const { upload_id: id } = await begin.json();
  const mismatched = await handleR2Request(request('PUT', 'other.bin', { action: 'part', upload_id: id, part: '1', size: '1' }, 'x'), bucket);
  assert.equal(mismatched.status, 400);
  assert.deepEqual(bucket.partReads, []);
  const uploaded = await handleR2Request(request('PUT', 'first.bin', { action: 'part', upload_id: id, part: '1', size: '1' }, 'x'), bucket);
  const part = await uploaded.json();
  assert.equal(uploaded.status, 200);
  const completion = { action: 'complete', upload_id: id, parts: JSON.stringify([{ partNumber: part.part_number, etag: part.etag }]) };
  assert.equal((await handleR2Request(request('PUT', 'first.bin', completion), bucket)).status, 200);
  assert.equal((await handleR2Request(request('PUT', 'first.bin', { action: 'part', upload_id: id, part: '2', size: '1' }, 'y'), bucket)).status, 400);
  assert.equal((await handleR2Request(request('PUT', 'first.bin', completion), bucket)).status, 400);
  assert.deepEqual(bucket.partReads, [1]);

  const aborted = await handleR2Request(request('PUT', 'abort.bin', { action: 'begin' }), bucket);
  const { upload_id: abortId } = await aborted.json();
  assert.equal((await handleR2Request(request('DELETE', 'abort.bin', { action: 'abort', upload_id: abortId }), bucket)).status, 200);
  assert.equal((await handleR2Request(request('PUT', 'abort.bin', { action: 'complete', upload_id: abortId, parts: '[]' }), bucket)).status, 400);
  assert.deepEqual(bucket.aborted, [abortId]);
  assert.deepEqual(bucket.completed, ['installations/primary/first.bin']);
});

const markers = (bucket) => [...bucket.objects.entries()].filter(([key]) => key.startsWith('installations/primary/.mpu/'));

test('complete and abort keep retry markers on transient failures and finalize after success', async () => {
  for (const action of ['complete', 'abort']) {
    const bucket = new Bucket();
    assert.equal((await handleR2Request(request('PUT', 'retry.bin', { action: 'begin' }), bucket)).status, 200);
    const [id] = bucket.sessions.keys();
    const etag = 'fixture-etag';
    let parts = [];
    if (action === 'complete') {
      const uploaded = await handleR2Request(request('PUT', 'retry.bin', { action: 'part', upload_id: id, part: '1', size: '1' }, 'x'), bucket);
      const uploadedPart = await uploaded.json();
      parts = [{ partNumber: uploadedPart.part_number, etag: uploadedPart.etag }];
    }
    const original = Upload.prototype[action];
    let calls = 0;
    Upload.prototype[action] = async function (...args) { calls += 1; if (calls === 1) throw new Error('temporary R2 failure'); return original.apply(this, args); };
    const query = { action, upload_id: id, ...(action === 'complete' ? { parts: JSON.stringify(parts) } : {}) };
    assert.equal(markers(bucket).length, 1);
    const first = await handleR2Request(request(action === 'abort' ? 'DELETE' : 'PUT', 'retry.bin', query), bucket);
    assert.equal(first.status, 500);
    assert.equal(markers(bucket).length, 1, 'a failed final call must leave its retry marker');
    const retry = await handleR2Request(request(action === 'abort' ? 'DELETE' : 'PUT', 'retry.bin', query), bucket);
    Upload.prototype[action] = original;
    assert.equal(retry.status, 200);
    assert.equal(markers(bucket).length, 0, 'success must consume its marker');
    assert.equal((await handleR2Request(request(action === 'abort' ? 'DELETE' : 'PUT', 'retry.bin', query), bucket)).status, 400);
    assert.equal(calls, 2);
  }
});

test('NoSuchUpload removes stale markers and still returns the generic failure', async () => {
  for (const error of [new Error('resumeMultipartUpload: The specified multipart upload does not exist. (10024)'), Object.assign(new Error('NoSuchUpload'), { code: 10024 })]) {
    for (const action of ['complete', 'abort']) {
      const bucket = new Bucket();
      assert.equal((await handleR2Request(request('PUT', 'missing.bin', { action: 'begin' }), bucket)).status, 200);
      const [id] = bucket.sessions.keys();
      const original = Upload.prototype[action];
      Upload.prototype[action] = async () => { throw error; };
      const response = await handleR2Request(request(action === 'abort' ? 'DELETE' : 'PUT', 'missing.bin', { action, upload_id: id, ...(action === 'complete' ? { parts: '[]' } : {}) }), bucket);
      Upload.prototype[action] = original;
      assert.equal(response.status, 500);
      assert.deepEqual(await response.json(), { ok: false, code: 'storage_failed', error: 'Object storage request failed.' });
      assert.equal(markers(bucket).length, 0);
    }
  }
});

test('a 1024-character upload id survives reserved marker key limits end to end', async () => {
  const bucket = new Bucket();
  const id = 'r2Mpu!$+/:;=@[]{}~'.padEnd(1024, 'x');
  const etag = 'etag-$+/:;=@[]{}~'.repeat(32);
  bucket.createMultipartUpload = async () => ({ uploadId: id });
  const begin = await handleR2Request(request('PUT', 'backup.zip', { action: 'begin' }), bucket);
  assert.equal(begin.status, 200);
  assert.deepEqual(await begin.json(), { ok: true, upload_id: id });
  const part = await handleR2Request(request('PUT', 'backup.zip', { action: 'part', upload_id: id, part: '1', size: '7' }, 'payload'), bucket);
  assert.equal(part.status, 200);
  const uploaded = await part.json();
  const marker = [...bucket.objects.keys()].find((key) => key.startsWith('installations/primary/.mpu/'));
  if (marker === undefined) throw new Error('Expected a marker before completion');
  assert.match(marker, /^installations\/primary\/\.mpu\/[0-9a-f]{64}$/);
  const body = JSON.parse(new TextDecoder().decode(bucket.objects.get(marker)));
  assert.deepEqual(body, { id, key: 'installations/primary/backup.zip' });
  const complete = await handleR2Request(request('PUT', 'backup.zip', { action: 'complete', upload_id: id, parts: JSON.stringify([{ partNumber: uploaded.part_number, etag: uploaded.etag }]) }), bucket);
  assert.equal(complete.status, 200);
  assert.ok(bucket.objects.has('installations/primary/backup.zip'));
});

test('every worst-case escaped marker supports real multipart requests', async () => {
  const id = '\\'.repeat(1024);
  const key = '"'.repeat(1024 - 'installations/primary/'.length);
  const bucket = new Bucket();
  bucket.createMultipartUpload = async (key) => {
    assert.ok(new TextEncoder().encode(key).byteLength <= 1024);
    return { uploadId: id, abort: async () => { bucket.aborted.push(id); } };
  };
  assert.equal((await handleR2Request(request('PUT', key, { action: 'begin' }), bucket)).status, 200);
  assert.equal((await handleR2Request(request('PUT', key, { action: 'part', upload_id: id, part: '1', size: '7' }, 'payload'), bucket)).status, 200);
  assert.deepEqual(bucket.partReads, [7]);
  assert.equal((await handleR2Request(request('DELETE', key, { action: 'abort', upload_id: id }), bucket)).status, 200);
  assert.deepEqual(bucket.aborted, [id]);

  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(id));
  const markerKey = 'installations/primary/.mpu/' + [...new Uint8Array(digest)].map(b => b.toString(16).padStart(2, '0')).join('');
  const stored = new TextEncoder().encode(JSON.stringify({ id, key: `installations/primary/${key}` }));
  assert.ok(stored.length > 3176 && stored.length <= 12327);
  const readSize = new Bucket();
  readSize.objects.set(markerKey, stored);
  assert.equal((await handleR2Request(request('DELETE', key, { action: 'abort', upload_id: id }), readSize)).status, 200);
  const unbounded = new TextEncoder().encode(JSON.stringify({ id, key: `installations/primary/${key}` }) + ' '.repeat(16000));
  readSize.objects.set(markerKey, unbounded);
  assert.equal((await handleR2Request(request('DELETE', key, { action: 'abort', upload_id: id }), readSize)).status, 400);

  const unicode = String.fromCharCode(218).repeat(501);
  const unicodeBucket = new Bucket();
  unicodeBucket.createMultipartUpload = async (key) => {
    assert.equal(new TextEncoder().encode(key).length, 1024);
    return { uploadId: id, abort: async () => { unicodeBucket.aborted.push(id); } };
  };
  assert.equal((await handleR2Request(request('PUT', unicode, { action: 'begin' }), unicodeBucket)).status, 200);
  assert.equal((await handleR2Request(request('DELETE', unicode, { action: 'abort', upload_id: id }), unicodeBucket)).status, 200);

});

test('long printable R2 identifiers survive markers, upload, completion, and abort', async () => {
  const bucket = new Bucket();
  const id = 'r2Mpu!$+/:;=@[]{}~'.repeat(40);
  const etag = 'etag-$+/:;=@[]{}~'.repeat(32);
  bucket.createMultipartUpload = async () => ({ uploadId: id });
  const original = Upload.prototype.uploadPart;
  Upload.prototype.uploadPart = async function (number, body) {
    const bytes = body instanceof Uint8Array ? body : new Uint8Array(await new Response(body).arrayBuffer());
    this.bucket.partReads.push(bytes.byteLength);
    this.bucket.parts.set(`${this.uploadId}:${number}`, bytes);
    const uploaded = { partNumber: number, etag };
    this.bucket.uploads.set(this.uploadId, [...(this.bucket.uploads.get(this.uploadId) ?? []), uploaded]);
    return uploaded;
  };
  try {
    const begin = await handleR2Request(request('PUT', 'backup.zip', { action: 'begin' }), bucket);
    assert.equal(begin.status, 200);
    assert.deepEqual(await begin.json(), { ok: true, upload_id: id });
    const part = await handleR2Request(request('PUT', 'backup.zip', { action: 'part', upload_id: id, part: '1', size: '7' }, 'payload'), bucket);
    assert.equal(part.status, 200);
    const complete = await handleR2Request(request('PUT', 'backup.zip', { action: 'complete', upload_id: id, parts: JSON.stringify([{ partNumber: 1, etag }]) }), bucket);
    assert.equal(complete.status, 200);
  } finally {
    Upload.prototype.uploadPart = original;
  }
  assert.equal((await handleR2Request(request('PUT', 'backup.zip', { action: 'begin' }), bucket)).status, 200);
  const abort = await handleR2Request(request('DELETE', 'backup.zip', { action: 'abort', upload_id: id }), bucket);
  assert.equal(abort.status, 200);
});

test('a marker write rejection or thrown error aborts the new upload', async () => {
  for (const collision of ['collision', 'throw']) {
    const bucket = new Bucket();
    bucket.createMultipartUpload = async () => ({ uploadId: 'r2-long-id', abort: async () => { bucket.aborted.push('r2-long-id'); } });
    bucket.put = collision === 'collision'
      ? async () => null
      : async () => { throw new Error('marker storage unavailable'); };
    const response = await handleR2Request(request('PUT', 'backup.zip', { action: 'begin' }), bucket);
    assert.equal(response.status, 500);
    assert.deepEqual(await response.json(), { ok: false, code: 'storage_failed', error: 'Object storage request failed.' });
    assert.deepEqual(bucket.aborted, ['r2-long-id']);
    assert.equal(bucket.sessions.size, 0);
  }
});

test('the bucket double enforces the R2 object-key byte limit', async () => {
  const bucket = new Bucket();
  await assert.rejects(() => bucket.put(`installations/primary/.mpu/${'x'.repeat(1024)}`, new Uint8Array()), Error, /1024/);
  assert.equal(bucket.objects.size, 0);
});
test('the reserved multipart marker prefix is unreachable through normal storage', async () => {
  const bucket = new Bucket();
  bucket.objects.set('installations/primary/.mpu/private', new TextEncoder().encode('{"key":"private"}'));
  const read = await handleR2Request(request('GET', '.mpu/private'), bucket);
  assert.equal(read.status, 400);
  assert.deepEqual(await read.json(), { ok: false, code: 'storage_failed', error: 'Object storage request failed.' });
  assert.equal((await handleR2Request(request('PUT', '.mpu/private', {}, 'forged'), bucket)).status, 400);
  assert.equal((await handleR2Request(request('DELETE', '.mpu/private'), bucket)).status, 400);
  assert.equal(new TextDecoder().decode(bucket.objects.get('installations/primary/.mpu/private')), '{"key":"private"}');
});

test('every validation failure exposes the stable generic protocol error', async () => {
  const bucket = new Bucket();
  bucket.list = async () => ({ objects: [{ key: 'outside' }], truncated: false });
  const responses = await Promise.all([
    handleR2Request(request('POST'), bucket),
    handleR2Request(request('GET', '.mpu/forged'), bucket),
    handleR2Request(request('PUT', 'large.bin', {}, new Uint8Array(100 * 1024 * 1024 + 1)), bucket),
    handleR2Request(request('GET', 'index', { action: 'list' }), bucket),
  ]);
  assert.deepEqual(responses.map((response) => response.status), [405, 400, 413, 502]);
  for (const response of responses) {
    assert.deepEqual(await response.json(), { ok: false, code: 'storage_failed', error: 'Object storage request failed.' });
  }
});

test('Worker errors expose a stable code and fixed generic sentence only', async () => {
  const bucket = new Bucket();
  const original = console.error;
  console.error = () => {};
  try {
    bucket.get = async () => { throw new Error('private binding detail'); };
    const failed = await handleR2Request(request('GET', 'a.bin'), bucket);
    assert.equal(failed.status, 500);
    assert.deepEqual(await failed.json(), { ok: false, code: 'storage_failed', error: 'Object storage request failed.' });
    assert.equal(r2UnboundResponse().status, 503);
    assert.deepEqual(await r2UnboundResponse().json(), { ok: false, code: 'unbound', error: 'Object storage is not available.' });
  } finally {
    console.error = original;
  }
});

test('the outbound entrypoint does not construct request-local multipart sessions', () => {
  const source = readFileSync(new URL('../src/index.ts', import.meta.url), 'utf8');
  const outbound = source.slice(source.indexOf('export class R2Outbound'), source.indexOf('export class ApothekeContainer'));
  assert.doesNotMatch(outbound, /new\s+Map|sessions?[,)\s]/, 'R2 state belongs to its uploadId, never a per-request Map');
  assert.match(outbound, /handleR2Request\(request, this\.env\.STORAGE\)/);
});

function writeHttpResponse(socket, status, body) {
  const payload = Buffer.from(body);
  const head = Buffer.from(`HTTP/1.1 ${status} Fixture\r\nContent-Type: application/json\r\nContent-Length: ${payload.length}\r\nConnection: close\r\n\r\n`);
  return new Promise((resolve, reject) => {
    socket.end(Buffer.concat([head, payload]), (error) => error ? reject(error) : resolve());
  });
}

function readHttpRequest(socket) {
  return new Promise((resolve, reject) => {
    let buf = Buffer.alloc(0);
    let settled = false;
    const finish = (error, value) => {
      if (settled) return;
      settled = true;
      socket.off('data', onData);
      socket.off('error', onError);
      socket.off('close', onClose);
      if (error) reject(error);
      else resolve(value);
    };
    const onError = (error) => finish(error);
    const onClose = () => finish(new Error('request closed'));
    const onData = (chunk) => {
      buf = Buffer.concat([buf, chunk]);
      if (buf.length > 1024 * 1024) return finish(new Error('request too large'));
      try {
        const message = parseHttpRequest(buf);
        if (message) finish(null, message);
      } catch (error) {
        finish(error);
      }
    };
    socket.on('data', onData);
    socket.on('error', onError);
    socket.on('close', onClose);
  });
}

function parseHttpRequest(buf) {
  const headerEnd = buf.indexOf('\r\n\r\n');
  if (headerEnd < 0) return null;
  const lines = buf.subarray(0, headerEnd).toString('latin1').split('\r\n');
  const [method, target] = lines[0].split(' ');
  if (!method || !target) throw new Error('bad request');
  const headers = new Map();
  for (const line of lines.slice(1)) {
    const index = line.indexOf(':');
    if (index > 0) headers.set(line.slice(0, index).toLowerCase(), line.slice(index + 1).trim());
  }
  const rest = buf.subarray(headerEnd + 4);
  if ((headers.get('transfer-encoding') ?? '').toLowerCase().includes('chunked')) {
    const body = parseChunks(rest);
    return body && { method, target, body };
  }
  if (method === 'GET' || method === 'HEAD' || headers.get('content-length') === '0') return { method, target, body: Buffer.alloc(0) };
  const length = Number(headers.get('content-length'));
  if (!Number.isInteger(length) || length < 0) throw new Error('bad length');
  if (rest.length < length) return null;
  return { method, target, body: rest.subarray(0, length) };
}

function parseChunks(buf) {
  const parts = [];
  let offset = 0;
  while (offset <= buf.length) {
    const lineEnd = buf.indexOf('\r\n', offset);
    if (lineEnd < 0) return null;
    const size = Number.parseInt(buf.subarray(offset, lineEnd).toString('latin1').split(';')[0].trim(), 16);
    if (!Number.isInteger(size) || size < 0) throw new Error('bad chunk');
    const start = lineEnd + 2;
    if (size === 0) return buf.length < start + 2 ? null : Buffer.concat(parts);
    if (buf.length < start + size + 2) return null;
    parts.push(buf.subarray(start, start + size));
    offset = start + size + 2;
  }
  return null;
}
