import assert from 'node:assert/strict';
import test from 'node:test';
import type { InstallationDetail, InstallationList, Overview } from '../src/api/types.ts';
import { handleMock } from './mock-api.ts';

function get(path: string): ReturnType<typeof handleMock> {
  return handleMock('GET', new URL(path, 'http://127.0.0.1'));
}

test('overview counts status, plan and usage from the made-up rows', () => {
  const result = get('/api/ops/v1/overview');
  assert.equal(result.status, 200);
  const body = result.body as Overview;
  assert.equal(body.revenue, null);
  assert.equal(body.as_of, '2026-11-20T00:00:00Z');
  const count = (status: string) => body.installations_by_status.find((item) => item.status === status)?.count;
  assert.equal(count('active'), 2);
  assert.equal(count('suspended'), 1);
  assert.equal(count('provisioning'), 1);
  assert.equal(count('deleted'), 0);
  assert.equal(body.installations_by_plan.find((item) => item.plan_id === 'internal')?.count, 1);
  assert.equal(body.installations_by_plan.find((item) => item.plan_id === 'shelf')?.count, 2);
  assert.deepEqual(body.usage_totals, [
    { metric: 'items', value: 19 },
    { metric: 'photos', value: 3 },
  ]);
});

test('the list filters by status, plan and host', () => {
  const all = (get('/api/ops/v1/installations').body as InstallationList).installations;
  assert.deepEqual(all.map((item) => item.installation.id), ['primary', 'north', 'south', 'west']);
  assert.equal(all[0].routes[0].host, 'api.services.getapotheke.com');
  assert.ok(all.every((item) => item.installation.owner_contact?.endsWith('@example.test')));

  const suspended = get('/api/ops/v1/installations?status=suspended').body as InstallationList;
  assert.deepEqual(suspended.installations.map((item) => item.installation.id), ['south']);

  const shelf = get('/api/ops/v1/installations?plan=shelf').body as InstallationList;
  assert.deepEqual(shelf.installations.map((item) => item.installation.id), ['north', 'west']);

  const host = get('/api/ops/v1/installations?q=North.Example').body as InstallationList;
  assert.deepEqual(host.installations.map((item) => item.installation.id), ['north']);
});

test('installation detail returns limits and usage, and an unknown id is 404', () => {
  const result = get('/api/ops/v1/installations/north');
  assert.equal(result.status, 200);
  const body = result.body as InstallationDetail;
  assert.equal(body.plan.id, 'shelf');
  assert.equal(body.limits.some((limit) => limit.limit_key === 'photos_per_item'), true);
  assert.equal(body.usage[0].value, 12);
  assert.equal(get('/api/ops/v1/installations/missing').status, 404);
});

test('config reports provisioning off, as on prod', () => {
  const result = get('/api/ops/v1/config');
  assert.equal(result.status, 200);
  assert.deepEqual(result.body, { provisioning_enabled: false, limits_enabled: false });
});

test('a preview fault answers 401, 503 or 410', () => {
  for (const fault of ['401', '503', '410']) {
    const result = handleMock('GET', new URL('/api/ops/v1/config', 'http://127.0.0.1'), fault);
    assert.equal(result.status, Number(fault));
  }
});

test('writes are not implemented', () => {
  const result = handleMock('POST', new URL('/api/ops/v1/jobs', 'http://127.0.0.1'));
  assert.equal(result.status, 405);
});
