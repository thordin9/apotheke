import assert from 'node:assert/strict';
import test from 'node:test';
import { handleR2Request, validKey, validateRawPath } from '../src/r2.ts';

class Upload {
  constructor(bucket, key, id) { this.bucket = bucket; this.key = key; this.uploadId = id; }
  async uploadPart(number, body) {
    const bytes = body instanceof Uint8Array ? body : new Uint8Array(await new Response(body).arrayBuffer());
    this.bucket.partReads.push(bytes.byteLength);
    this.bucket.parts.set(`${this.uploadId}:${number}`, bytes);
    return { partNumber: number, etag: `test-${crypto.randomUUID()}` };
  }
  async complete(parts) {
    const sorted = [...parts].sort((a, b) => a.partNumber - b.partNumber);
    this.bucket.objects.set(this.key, new Uint8Array(sorted.reduce((n, part) => n + this.bucket.parts.get(`${this.uploadId}:${part.partNumber}`).byteLength, 0)));
    this.bucket.completed.push(this.key);
  }
  async abort() { this.bucket.aborted.push(this.uploadId); }
}
class Bucket {
  objects = new Map(); parts = new Map(); sessions = new Map(); prefix = ''; partReads = []; completed = []; aborted = []; failAt = 0;
  async put(key, value, options = {}) { if (options.onlyIf?.etagDoesNotMatch === '*' && this.objects.has(key)) return null; this.objects.set(key, new Uint8Array(value)); return { key }; }
  async get(key) { const data = this.objects.get(key); return data ? { arrayBuffer: async () => data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength) } : null; }
  async head(key) { const data = this.objects.get(key); return data ? { uploaded: new Date(1740000000000) } : null; }
  async delete(key) { this.objects.delete(key); }
  async list({ prefix, limit, cursor }) { this.prefix = prefix; const keys = [...this.objects.keys()].filter((key) => key.startsWith(prefix)).sort(); const start = cursor ? Number(cursor) : 0; const slice = keys.slice(start, start + limit); return { objects: slice.map((key) => ({ key })), truncated: start + limit < keys.length, cursor: String(start + limit) }; }
  async createMultipartUpload(key) { const id = `upload-${crypto.randomUUID().replaceAll('-', '')}`; this.sessions.set(id, key); return { uploadId: id }; }
  resumeMultipartUpload(key, id) { assert.equal(key, this.sessions.get(id)); return new Upload(this, key, id); }
}
const replenish = () => {
  let n = 0;
  return new ReadableStream({ start(controller) { while (n < 20) { controller.enqueue(new Uint8Array(256 * 1024)); n += 1; } controller.close(); } });
};
function request(method = 'GET', path = 'file.bin', query = {}, body) { return new Request(`http://r2.internal/${path}?${new URLSearchParams(query)}`, { method, body, ...(body === undefined ? {} : { duplex: 'half' }) }); }

test('application keys must be safe before any bucket call', () => {
  for (const path of ['', '/a', 'a/../b', 'a//b', 'a\\b', '../x', 'a/.', 'a?b', 'a#b', 'a\n']) assert.equal(validKey(path), false, path);
  assert.equal(validateRawPath('/a/%2e%2e/secret'), undefined);
  for (const path of ['a', 'media/one+two ü.txt', 'a.b']) assert.equal(validKey(path), true);
});

test('Worker alone prefixes reads, deletes, and scoped listings', async () => {
  const bucket = new Bucket();
  bucket.objects.set('installations/primary/media/a', new Uint8Array([1, 2, 255]));
  bucket.objects.set('installations/primary/media/b', new Uint8Array());
  bucket.objects.set('other/tenant/secret', new Uint8Array([99]));
  const read = await (await handleR2Request(request('GET', 'media/a'), bucket, new Map())).json();
  assert.deepEqual(read, { ok: true, found: true, bytes: 'AQL/' });
  const head = await (await handleR2Request(request('HEAD', 'media/a'), bucket, new Map())).json();
  assert.deepEqual(head, { ok: true, found: true, modified: 1740000000 });
  assert.deepEqual(await (await handleR2Request(request('GET', 'index', { action: 'list', prefix: 'media', limit: '1' }), bucket, new Map())).json(), { ok: true, objects: ['media/a'], cursor: '1' });
  assert.equal(bucket.prefix, 'installations/primary/media');
  const next = await handleR2Request(request('GET', 'index', { action: 'list', prefix: 'media', limit: '1', cursor: '1' }), bucket, new Map());
  assert.deepEqual(await next.json(), { ok: true, objects: ['media/b'], cursor: null });
  assert.equal((await handleR2Request(request(), new Bucket(), new Map())).status, 200);
  assert.deepEqual(await (await handleR2Request(request('DELETE', 'media/a'), bucket, new Map())).json(), { ok: true });
});

test('only installation-local atomic conditional create is permitted', async () => {
  const bucket = new Bucket(); const sessions = new Map();
  const fixture = { if_none_match: '*' };
  const a = handleR2Request(request('PUT', 'photo.jpg', fixture, new Uint8Array([2])), bucket, sessions);
  const b = handleR2Request(request('PUT', 'photo.jpg', fixture, new Uint8Array([3])), bucket, sessions);
  const statuses = (await Promise.all([a, b])).map((response) => response.status).sort();
  assert.deepEqual(statuses, [200, 412]);
  assert.equal(bucket.objects.has('installations/other/photo.jpg'), false);
  assert.equal((await handleR2Request(request('PUT', 'photo.jpg', { if_none_match: 'wrong' }, 'a'), bucket, sessions)).status, 400);
  assert.equal((await handleR2Request(request('PUT', 'photo.jpg', { action: 'begin', if_none_match: '*' }), bucket, sessions)).status, 400);
});

test('multipart forwards bounded streams and completes in source order', async () => {
  const bucket = new Bucket(); const sessions = new Map(); let id;
  const begin = await handleR2Request(request('PUT', 'a.bin', { action: 'begin' }), bucket, sessions);
  ({ upload_id: id } = await begin.json());
  const part = request('PUT', 'a.bin', { action: 'part', upload_id: id, part: '1', size: String(5 * 1024 * 1024) }, replenish());
  const upload = await handleR2Request(part, bucket, sessions);
  assert.deepEqual(await upload.json(), { ok: true, bytes: 5 * 1024 * 1024 });
  assert.deepEqual(bucket.partReads, [5 * 1024 * 1024]);
  assert.equal((await handleR2Request(request('PUT', 'a.bin', { action: 'complete', upload_id: id }), bucket, sessions)).status, 200);
  assert.equal(bucket.objects.get('installations/primary/a.bin').byteLength, 5 * 1024 * 1024);
});

test('wrong and overlong part bodies never reach the bucket', async () => {
  const bucket = new Bucket(); const sessions = new Map();
  const response = await handleR2Request(request('PUT', 'a.bin', { action: 'begin' }), bucket, sessions);
  const { upload_id: id } = await response.json();
  assert.equal((await handleR2Request(request('PUT', 'a.bin', { action: 'part', upload_id: id, part: '2', size: '1' }, 'a'), bucket, sessions)).status, 400);
  assert.equal((await handleR2Request(request('PUT', 'a.bin', { action: 'part', upload_id: id, part: '1', size: '0' }, 'ab'), bucket, sessions)).status, 400);
  assert.equal((await handleR2Request(request('PUT', 'a.bin', { action: 'part', upload_id: id, part: '1', size: '5242881' }, replenish()), bucket, sessions)).status, 400);
  assert.deepEqual(bucket.partReads, []);
  assert.equal((await handleR2Request(request('DELETE', 'a.bin', { action: 'abort', upload_id: id }), bucket, sessions)).status, 200);
  assert.equal(sessions.size, 0);
  assert.equal(bucket.aborted.length, 1);
});

test('unsafe paths, missing caps, and absent R2 fail generically', async () => {
  assert.equal((await handleR2Request(request('GET', 'a/%2e%2e/secret'), new Bucket(), new Map())).status, 200);
  assert.equal((await handleR2Request(request('POST'), new Bucket(), new Map())).status, 405);
  const { r2UnboundResponse } = await import('../src/r2.ts');
  assert.equal(r2UnboundResponse().status, 503);
  const bucket = new Bucket(); bucket.list = async () => ({ objects: [{ key: 'outside' }], truncated: false });
  assert.equal((await handleR2Request(request('GET', 'index', { action: 'list' }), bucket, new Map())).status, 502);
});
