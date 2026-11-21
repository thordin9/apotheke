import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { OPS_CSP } from '../src/csp.ts';

const edge = path.join(path.dirname(fileURLToPath(import.meta.url)), '../../../edge/ops/src/index.ts');

test('the preview CSP is the operator Worker policy', async () => {
  const source = await readFile(edge, 'utf8');
  const parts = [...source.matchAll(/"((?:default-src|base-uri)[^"]*)"/g)].map((match) => match[1]);
  assert.equal(parts.length, 2);
  assert.equal(OPS_CSP, parts.join(''));
});
