import assert from 'node:assert/strict';
import test from 'node:test';
import { OpsError } from './client.ts';
import { PRIMARY_MESSAGE, explain, explainCode, fieldOf } from './messages.ts';

test('primary, a missing code and a field detail are plain sentences', () => {
  assert.equal(explainCode('primary'), PRIMARY_MESSAGE);
  assert.equal(explainCode('no_such'), 'The API refused the request (no_such).');
  assert.equal(explainCode('invalid_body', 'limits.items.soft_value'), 'That value is not valid: limits.items.soft_value.');
  assert.equal(explainCode('below_plan_limit', '', { plan_hard_value: 40 }), 'That value is below the plan hard limit (40).');
  assert.equal(explainCode('downgrade_requires_confirm', '', { keys: ['items', 'photos'] }), 'Changing the plan lowers items, photos. Confirm to continue.');
  assert.equal(explainCode(''), 'The API returned an unexpected answer.');
});

test('explain reads an OpsError, including a network failure', () => {
  const error = new OpsError('x', 403, 'forbidden', 'primary', '', { ok: false, code: 'primary' });
  assert.equal(explain(error), PRIMARY_MESSAGE);
  assert.equal(explain(new TypeError('offline')), 'The console could not reach the API.');
});

test('fieldOf keeps a 422 field path and drops prose', () => {
  assert.equal(fieldOf('limits.items.soft_value'), 'limits.items.soft_value');
  assert.equal(fieldOf('confirm_host'), 'confirm_host');
  assert.equal(fieldOf('not a field'), null);
  assert.equal(fieldOf('body'), null);
});
