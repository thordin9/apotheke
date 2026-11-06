import assert from 'node:assert/strict';
import test from 'node:test';
import { handleR2Request, r2UnboundResponse, validKey, validateRawPath } from '../src/r2.ts';
import { register } from 'node:module';
import { readFileSync } from 'node:fs';
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
  async put(key, value, options = {}) { if (options.onlyIf?.etagDoesNotMatch === '*' && this.objects.has(key)) return null; this.objects.set(key, typeof value === "string" ? new TextEncoder().encode(value) : new Uint8Array(value)); return { key }; }
  async get(key) { const data = this.objects.get(key); return data ? { size: data.byteLength, text: async () => new TextDecoder().decode(data), arrayBuffer: async () => data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength) } : null; }
  async head(key) { const data = this.objects.get(key); return data ? { uploaded: new Date(1740000000000) } : null; }
  async delete(key) { this.objects.delete(key); }
  async list({ prefix, limit, cursor }) { this.prefix = prefix; const keys = [...this.objects.keys()].filter((key) => key.startsWith(prefix)).sort(); const start = cursor ? Number(cursor) : 0; const slice = keys.slice(start, start + limit); return { objects: slice.map((key) => ({ key })), truncated: start + limit < keys.length, cursor: String(start + limit) }; }
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
  assert.deepEqual(read, { ok: true, found: true, bytes: 'AQL/' });
  const head = await (await handleR2Request(request('HEAD', 'media/a'), bucket)).json();
  assert.deepEqual(head, { ok: true, found: true, modified: 1740000000 });
  assert.deepEqual(await (await handleR2Request(request('GET', 'index', { action: 'list', prefix: 'media', limit: '1' }), bucket)).json(), { ok: true, objects: ['media/a'], cursor: '1' });
  const next = await handleR2Request(request('GET', 'index', { action: 'list', prefix: 'media', limit: '1', cursor: '1' }), bucket);
  assert.deepEqual(await next.json(), { ok: true, objects: ['media/b'], cursor: null });
  assert.equal(bucket.prefix, 'installations/primary/media');
  assert.equal((await handleR2Request(request(), new Bucket())).status, 200);
  assert.deepEqual(await (await handleR2Request(request('DELETE', 'media/a'), bucket)).json(), { ok: true });
});

test('application listings never expose multipart marker keys', async () => {
  const bucket = new Bucket();
  bucket.objects.set('installations/primary/.mpu/upload', new TextEncoder().encode('{"key":"secret"}'));
  bucket.objects.set('installations/primary/media/a', new Uint8Array([1]));
  const response = await handleR2Request(request('GET', 'index', { action: 'list' }), bucket);
  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), { ok: true, objects: ['media/a'], cursor: null });
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
