import assert from 'node:assert/strict';
import test from 'node:test';
import { statsStamp } from './format.ts';

test('a missing stamp says the stats are not computed', () => {
  assert.equal(statsStamp(null), null);
  assert.equal(statsStamp('not-a-date'), null);
});

test('a date-only value stays a date and an invalid value is empty', () => {
  const stamp = statsStamp('2026-11-20');
  assert.equal(stamp?.datetime, '2026-11-20');
  assert.match(stamp?.text ?? '', /2026/);
  assert.equal(stamp?.text.includes('T'), false);
  assert.equal(statsStamp(''), null);
  assert.equal(statsStamp('2026-13-40'), null);
});

test('a stamp is a UTC date and time', () => {
  const stamp = statsStamp('2026-11-20T00:00:00Z');
  assert.equal(stamp?.datetime, '2026-11-20T00:00:00Z');
  assert.match(stamp?.text ?? '', /2026/);
  assert.match(stamp?.text ?? '', /00:00/);
});
