import assert from 'node:assert/strict';
import test from 'node:test';
import { writesAreEnabled } from './writes.ts';

test('a missing writes flag stays off', () => {
  assert.equal(writesAreEnabled({}), false);
  assert.equal(writesAreEnabled({ writes_enabled: undefined }), false);
  assert.equal(writesAreEnabled({ writes_enabled: false }), false);
  assert.equal(writesAreEnabled({ writes_enabled: true }), true);
});
