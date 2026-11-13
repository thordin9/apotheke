// Operator console C2: the edge side of the signed backup request matches PHP's OpsSignature (shared vector).
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
const { opsSignature, opsBackupRequest, OPS_SIGNATURE_HEADER } = await import('../src/ops-signature.ts');

const vector = JSON.parse(readFileSync(new URL('../../tests/Modules/_support/ops-signature-vector.json', import.meta.url), 'utf8'));
const appKey = 'base64:' + Buffer.from(Array.from({ length: 32 }, (_, i) => i + 1)).toString('base64');

test('C2: the ops signature matches the vector PHP checks', async () => {
  assert.equal(await opsSignature(appKey, vector.method, vector.path, vector.body, vector.t), vector.header);
  assert.notEqual(await opsSignature(appKey, vector.method, vector.path, vector.body + ' ', vector.t), vector.header);
  await assert.rejects(opsSignature('base64:AAAA', 'POST', '/x', '', 1));
});

test('C2: the backup request goes to ops.internal with the job id and a fresh signature', async () => {
  const r = await opsBackupRequest(appKey, 'job-1', vector.t * 1000);
  assert.equal(r.method, 'POST');
  assert.equal(r.url, 'http://ops.internal/internal/backup');
  assert.equal(await r.text(), vector.body);
  assert.equal(r.headers.get(OPS_SIGNATURE_HEADER), vector.header);
  assert.equal(r.headers.get('x-forwarded-host'), null);
});
