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
  assert.equal(body.installations_by_status.find((item) => item.status === 'active')?.count, 3);
  assert.equal(body.open_jobs_by_state.find((item) => item.state === 'paused')?.count, 3);
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
  assert.ok(next.next_cursor);
  const last = get(`/api/ops/v1/installations?limit=2&cursor=${next.next_cursor}`).body as InstallationList;
  assert.deepEqual(last.installations.map((item) => item.installation.id), ['yard']);
  assert.equal(last.next_cursor, null);
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

test('installation users are a read-only list', () => {
  const north = get('/api/ops/v1/installations/north/users');
  assert.equal(north.status, 200);
  const body = north.body as { ok: true; installation: string; users: { username: string; display_name: null; roles: string[]; status: string }[]; next_cursor: null };
  assert.equal(body.installation, 'north');
  assert.equal(body.next_cursor, null);
  assert.equal(body.users[0].username, '<b>x</b>');
  assert.equal(body.users[0].display_name, null);
  assert.deepEqual(body.users[0].roles, []);
  assert.equal(body.users[1].status, 'active');
  assert.equal(get('/api/ops/v1/installations/primary/users').status, 403);
  assert.equal((get('/api/ops/v1/installations/primary/users').body as { code: string }).code, 'primary');
  assert.equal(get('/api/ops/v1/installations/missing/users').status, 404);
  assert.equal(get('/api/ops/v1/installations/south/users').status, 409);
  assert.equal((get('/api/ops/v1/installations/south/users').body as { code: string }).code, 'not_startable');
  assert.equal((get('/api/ops/v1/installations/nohost/users').body as { code: string }).code, 'no_host');
  assert.equal(get('/api/ops/v1/installations/old/users').status, 410);
  assert.equal((get('/api/ops/v1/installations/unread/users').body as { detail: string }).detail, 'read_failed');
  assert.equal((get('/api/ops/v1/installations/unconfigured/users').body as { code: string }).code, 'not_configured');
  const blocked = handleMock('POST', new URL('/api/ops/v1/installations/north/users', 'http://127.0.0.1'));
  assert.equal(blocked.status, 403);
  assert.equal((blocked.body as { code: string }).code, 'writes_disabled');
});

test('config is read-only unless the preview writes cookie is set', () => {
  const off = get('/api/ops/v1/config').body as { writes_enabled: boolean };
  assert.equal(off.writes_enabled, false);
  const on = handleMock('GET', new URL('/api/ops/v1/config', 'http://127.0.0.1'), '', true).body as { writes_enabled: boolean };
  assert.equal(on.writes_enabled, true);
});

test('a failed job filter matches nothing', () => {
  const jobs = get('/api/ops/v1/jobs?state=failed').body as { jobs: unknown[] };
  assert.deepEqual(jobs.jobs, []);
});

test('yard has no accounts', () => {
  const body = get('/api/ops/v1/installations/yard/users').body as { users: unknown[] };
  assert.deepEqual(body.users, []);
});

const gate = { csrf: '1', origin: 'http://127.0.0.1:4181' };

function write(method: string, path: string, body?: unknown) {
  return handleMock(method, new URL(path, 'http://127.0.0.1'), '', true, { ...gate, raw: body === undefined ? '' : JSON.stringify(body) });
}

test('a write without the CSRF header or the preview origin is refused', () => {
  const missing = handleMock('POST', new URL('/api/ops/v1/jobs', 'http://127.0.0.1'), '', true, { raw: '{}', origin: 'http://127.0.0.1:4181' });
  assert.equal(missing.status, 403);
  assert.equal((missing.body as { code: string }).code, 'csrf');
  const other = handleMock('DELETE', new URL('/api/ops/v1/installations/north/limits/items', 'http://127.0.0.1'), '', true, { csrf: '1', origin: 'https://evil.example', raw: '' });
  assert.equal((other.body as { code: string }).code, 'csrf');
});

test('a downgrade asks for confirm and a job nonce is reused', () => {
  const first = write('PUT', '/api/ops/v1/installations/north/subscription', { plan_id: 'loft' });
  assert.equal(first.status, 409);
  assert.equal((first.body as { code: string }).code, 'downgrade_requires_confirm');
  assert.ok(((first.body as { keys: string[] }).keys).includes('storage_bytes'));
  const done = write('PUT', '/api/ops/v1/installations/north/subscription', { plan_id: 'loft', confirm: true });
  assert.equal(done.status, 200);
  const nonce = 'nonce-once-1';
  const created = write('POST', '/api/ops/v1/jobs', { kind: 'export', installation_id: 'yard', nonce });
  assert.equal(created.status, 201);
  const again = write('POST', '/api/ops/v1/jobs', { kind: 'export', installation_id: 'yard', nonce });
  assert.equal(again.status, 200);
  assert.equal((again.body as { created: boolean }).created, false);
  const provision = write('POST', '/api/ops/v1/jobs', { kind: 'provision', installation_id: 'yard', nonce: 'nonce-prov-1' });
  assert.equal((provision.body as { code: string }).code, 'provisioning_disabled');
});

test('plan limits replace every row and primary is refused', () => {
  const primary = write('PUT', '/api/ops/v1/plans/internal/limits', { limits: {} });
  assert.equal(primary.status, 403);
  assert.equal((primary.body as { code: string }).code, 'primary');
  const limits: Record<string, { soft_value: number | null; hard_value: number | null }> = {};
  for (const key of ['items', 'storage_bytes', 'photos', 'users', 'autofill_calls_month', 'requests_month', 'container_hours_month', 'sleep_after_ms', 'import_file_bytes', 'photos_per_item']) {
    limits[key] = { soft_value: 1, hard_value: 2 };
  }
  const saved = write('PUT', '/api/ops/v1/plans/shelf/limits', { limits });
  assert.equal(saved.status, 200);
  assert.equal((saved.body as { limits: unknown[] }).limits.length, 10);
  const bad = write('PUT', '/api/ops/v1/plans/shelf/limits', { limits: { items: { soft_value: 9, hard_value: 1 } } });
  assert.equal(bad.status, 422);
  assert.equal((bad.body as { detail: string }).detail, 'limits.items.soft_value');
});
