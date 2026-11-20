import assert from 'node:assert/strict';
import test from 'node:test';
import type { InstallationDetail, InstallationList, Overview, SubscriptionDueList } from '../src/api/types.ts';
import { handleMock } from './mock-api.ts';

function get(path: string): ReturnType<typeof handleMock> {
  return handleMock('GET', new URL(path, 'http://127.0.0.1'));
}

test('overview matches the read shape and leaves revenue and the stamp empty', () => {
  const result = get('/api/ops/v1/overview');
  assert.equal(result.status, 200);
  const body = result.body as Overview;
  assert.equal(body.ok, true);
  assert.equal(body.revenue, null);
  assert.equal(body.as_of, null);
  assert.equal(body.usage_period, '2026-11');
  assert.equal(body.installations_by_status.find((item) => item.status === 'active')?.count, 2);
  assert.equal(body.open_jobs_by_state.find((item) => item.state === 'paused')?.count, 1);
  assert.deepEqual(body.usage_totals, [
    { metric: 'items', value: 19 },
    { metric: 'photos', value: 3 },
  ]);
});

test('the list pages by id and marks primary', () => {
  const page = get('/api/ops/v1/installations?limit=2').body as InstallationList;
  assert.deepEqual(page.installations.map((item) => item.installation.id), ['north', 'primary']);
  assert.equal(page.installations[1].primary, true);
  assert.equal(page.installations[1].routes[0].host, 'primary.example.test');
  assert.ok(page.next_cursor);
  const next = get(`/api/ops/v1/installations?limit=2&cursor=${page.next_cursor}`).body as InstallationList;
  assert.deepEqual(next.installations.map((item) => item.installation.id), ['south', 'west']);
  assert.equal(next.next_cursor, null);
});

test('installation detail is newest usage first and has no token', () => {
  const body = get('/api/ops/v1/installations/north').body as InstallationDetail;
  assert.equal(body.ok, true);
  assert.deepEqual(body.usage.map((row) => row.period), ['2026-11', '2026-10']);
  assert.equal(body.jobs[0].state, 'done');
  assert.equal(JSON.stringify(body).includes('token'), false);
  assert.equal(get('/api/ops/v1/installations/missing').status, 404);
});

test('subscriptions due include overdue and skip dates outside the window', () => {
  const body = get('/api/ops/v1/subscriptions?due=14d').body as SubscriptionDueList;
  assert.deepEqual(body.subscriptions.map((row) => row.installation_id), ['south', 'north']);
  assert.equal(body.subscriptions[0].overdue, true);
  assert.equal(body.subscriptions[1].overdue, false);
  assert.equal(body.truncated, false);
});

test('jobs filter on state and audit csv is a download', () => {
  const jobs = get('/api/ops/v1/jobs?state=paused');
  assert.equal((jobs.body as { jobs: { id: string }[] }).jobs[0].id, 'job-south');
  const csv = handleMock('GET', new URL('/api/ops/v1/audit.csv?installation=north', 'http://127.0.0.1'));
  assert.equal(csv.headers?.['content-type'], 'text/csv; charset=utf-8');
  assert.equal(csv.headers?.['x-truncated'], '0');
  assert.match(String(csv.body), /installation.claim/);
});

test('a preview fault answers 401, 503 or 410', () => {
  assert.equal(handleMock('GET', new URL('/api/ops/v1/config', 'http://127.0.0.1'), '401').status, 401);
  assert.equal(handleMock('GET', new URL('/api/ops/v1/config', 'http://127.0.0.1'), '503').status, 503);
  assert.equal(handleMock('GET', new URL('/api/ops/v1/config', 'http://127.0.0.1'), '410').status, 410);
});

test('writes are not implemented', () => {
  const result = handleMock('POST', new URL('/api/ops/v1/jobs', 'http://127.0.0.1'));
  assert.equal(result.status, 405);
  assert.equal((result.body as { code: string }).code, 'method_not_allowed');
});
