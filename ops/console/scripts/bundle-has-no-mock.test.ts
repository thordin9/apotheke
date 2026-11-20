import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const here = path.dirname(fileURLToPath(import.meta.url));
const dist = path.join(here, '..', 'dist');
const banned = ['OPS_MOCK_FIXTURE', 'example.test', 'mock-api', 'preview-csrf', 'ops_preview_fault'];

async function files(dir: string): Promise<string[]> {
  let entries;
  try {
    entries = await readdir(dir, { withFileTypes: true });
  } catch {
    return [];
  }
  const found: string[] = [];
  for (const entry of entries) {
    const next = path.join(dir, entry.name);
    if (entry.isDirectory()) found.push(...await files(next));
    else found.push(next);
  }
  return found;
}

test('the production build contains no mock or fixture code', async () => {
  const built = await files(dist);
  assert.ok(built.length > 0, 'build ops/console before this check');
  const text = built.filter((file) => /\.(html|js|css|map)$/.test(file));
  assert.ok(text.length > 0, 'the build produced no html, js or css');
  for (const file of text) {
    const source = await readFile(file, 'utf8');
    for (const needle of banned) {
      assert.equal(source.includes(needle), false, `${file} contains ${needle}`);
    }
  }
});

test('the sentinel stays in the preview module so this check can see it', async () => {
  const source = await readFile(path.join(here, '..', 'preview', 'fixtures.ts'), 'utf8');
  assert.equal(source.includes('OPS_MOCK_FIXTURE'), true);
});
