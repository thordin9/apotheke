// OPS_MOCK_FIXTURE
import type {
  AuditList,
  InstallationDetail,
  InstallationList,
  InstallationStatus,
  InstallationSummary,
  JobList,
  Overview,
  PlanList,
  SubscriptionDueList,
  SubscriptionStatus,
} from '../src/api/types.ts';
import { NOW, audit, installationUsers, installations, jobs, limits, plans, routes, subscriptions, usage } from './fixtures.ts';

const statuses: InstallationStatus[] = ['provisioning', 'active', 'suspended', 'deleting', 'deleted'];
const subStatuses: SubscriptionStatus[] = ['trial', 'active', 'past_due', 'cancelled'];
const openStates = ['paused', 'queued', 'running'] as const;

export interface MockResult {
  status: number;
  body: unknown;
  headers?: Record<string, string>;
}

export function handleMock(method: string, url: URL, fault = ''): MockResult {
  if (!url.pathname.startsWith('/api/ops/v1')) return fail(404, 'not_found');
  if (fault === '401' || fault === '410' || fault === '503') return fail(Number(fault), fault === '401' ? 'unauthorized' : fault === '410' ? 'gone' : 'not_configured');
  const verb = method.toUpperCase();
  if (verb !== 'GET' && verb !== 'HEAD') return fail(405, 'method_not_allowed');
  const path = url.pathname.slice('/api/ops/v1'.length);
  try {
    if (path === '/config') return ok({ provisioning_enabled: false, limits_enabled: false });
    if (path === '/overview') return ok(overview());
    if (path === '/installations') return ok(listInstallations(url));
    if (path === '/plans') return ok(listPlans());
    if (path === '/subscriptions') return ok(listDue(url));
    if (path === '/jobs') return ok(listJobs(url));
    if (path === '/audit') return ok(listAudit(url));
    if (path === '/audit.csv') return csv(url);
    const usersPath = /^\/installations\/([^/]+)\/users$/.exec(path);
    if (usersPath) return usersOf(decodeURIComponent(usersPath[1]));
    const detail = /^\/installations\/([^/]+)$/.exec(path);
    if (detail) {
      const id = decodeURIComponent(detail[1]);
      const found = one(id);
      if (!found) return fail(404, 'not_found');
      const body: InstallationDetail = {
        ok: true,
        ...found,
        limits: limits.filter((limit) => limit.plan_id === found.plan?.id),
        usage: usage.filter((row) => row.installation_id === id).sort((a, b) => b.period.localeCompare(a.period) || a.metric.localeCompare(b.metric)),
        jobs: jobs.filter((row) => row.installation_id === id).sort(newestJob).slice(0, 20),
        audit: audit.filter((row) => row.installation_id === id).sort(newestAudit).slice(0, 20),
      };
      return { status: 200, body };
    }
    return fail(404, 'not_found');
  } catch (error) {
    if (error instanceof QueryError) return fail(422, 'invalid_query', error.param);
    throw error;
  }
}

class QueryError extends Error {
  readonly param: string;
  constructor(param: string) {
    super(param);
    this.param = param;
  }
}

function ok(body: object): MockResult {
  return { status: 200, body: { ok: true, ...body } };
}

function fail(status: number, code: string, detail?: string): MockResult {
  return { status, body: detail ? { ok: false, code, detail } : { ok: false, code } };
}

function usersOf(id: string): MockResult {
  if (id === 'primary') return fail(403, 'primary');
  if (id === 'old') return fail(410, 'gone');
  if (id === 'nohost') return fail(409, 'no_host');
  if (id === 'unread') return fail(503, 'unavailable', 'read_failed');
  if (id === 'unconfigured') return fail(503, 'not_configured');
  const found = one(id);
  if (!found) return fail(404, 'not_found');
  if (found.installation.status === 'deleted') return fail(410, 'gone');
  if (found.installation.status !== 'provisioning' && found.installation.status !== 'active') return fail(409, 'not_startable');
  if (found.routes.length === 0) return fail(409, 'no_host');
  const rows = installationUsers.filter((item) => item.installation_id === id).map((item) => ({
    id: item.id,
    username: item.username,
    display_name: item.display_name,
    roles: item.roles,
    active: item.active,
    status: item.status,
    created_at: item.created_at,
    last_login_at: item.last_login_at,
  }));
  return { status: 200, body: { ok: true, installation: id, users: rows, next_cursor: null } };
}

function one(id: string): InstallationSummary | null {
  const installation = installations.find((item) => item.id === id);
  if (!installation) return null;
  const subscription = subscriptions.find((item) => item.installation_id === id) ?? null;
  const plan = plans.find((item) => item.id === subscription?.plan_id) ?? null;
  return {
    installation,
    routes: routes.filter((item) => item.installation_id === id),
    subscription,
    plan,
    primary: id === 'primary' || installation.do_name === 'primary',
  };
}

function listInstallations(url: URL): Omit<InstallationList, 'ok'> {
  const status = url.searchParams.get('status') ?? '';
  const plan = url.searchParams.get('plan') ?? '';
  const q = (url.searchParams.get('q') ?? '').trim().toLowerCase();
  const limit = pageSize(url);
  const after = cursor(url, 1);
  const rows = installations.flatMap((installation) => {
    const item = one(installation.id);
    if (!item) return [];
    if (status && item.installation.status !== status) return [];
    if (plan && item.plan?.id !== plan) return [];
    if (q && !item.routes.some((route) => route.host.toLowerCase().includes(q))) return [];
    if (after && item.installation.id <= after[0]) return [];
    return [item];
  }).sort((a, b) => a.installation.id.localeCompare(b.installation.id));
  const page = rows.slice(0, limit);
  const last = page[page.length - 1];
  return { installations: page, next_cursor: rows.length > limit && last ? encodeCursor([last.installation.id]) : null };
}

function overview(): Omit<Overview, 'ok'> {
  return {
    revenue: null,
    as_of: null,
    installations_by_status: statuses.map((status) => ({ status, count: installations.filter((item) => item.status === status).length })),
    installations_by_plan: plans.map((plan) => ({
      plan_id: plan.id,
      plan_name: plan.name,
      count: subscriptions.filter((item) => item.plan_id === plan.id).length,
    })),
    subscriptions_by_status: subStatuses.map((status) => ({ status, count: subscriptions.filter((item) => item.status === status).length })),
    open_jobs_by_state: openStates.map((state) => ({ state, count: jobs.filter((item) => item.state === state).length })),
    usage_period: '2026-11',
    usage_totals: [
      { metric: 'items', value: usage.filter((row) => row.period === '2026-11' && row.metric === 'items').reduce((sum, row) => sum + row.value, 0) },
      { metric: 'photos', value: usage.filter((row) => row.period === '2026-11' && row.metric === 'photos').reduce((sum, row) => sum + row.value, 0) },
    ],
  };
}

function listPlans(): Omit<PlanList, 'ok'> {
  return { plans: plans.map((plan) => ({ ...plan, limits: limits.filter((limit) => limit.plan_id === plan.id) })) };
}

function listDue(url: URL): Omit<SubscriptionDueList, 'ok'> {
  const raw = url.searchParams.get('due');
  let days = 14;
  if (raw) {
    const match = /^(\d{1,3})d?$/.exec(raw);
    if (!match || Number(match[1]) > 365) throw new QueryError('due');
    days = Number(match[1]);
  }
  const now = Date.parse(NOW);
  const until = now + days * 86_400_000;
  const rows = subscriptions.flatMap((subscription) => {
    if (subscription.status === 'cancelled') return [];
    const due = subscription.status === 'trial' ? subscription.trial_end : subscription.period_end;
    if (!due) return [];
    const at = Date.parse(due);
    if (Number.isNaN(at) || at > until) return [];
    const plan = plans.find((item) => item.id === subscription.plan_id);
    return [{
      ...subscription,
      plan_name: plan?.name ?? null,
      due_at: due,
      hosts: routes.filter((route) => route.installation_id === subscription.installation_id).map((route) => route.host),
      overdue: at < now,
    }];
  }).sort((a, b) => Date.parse(a.due_at) - Date.parse(b.due_at));
  return { due_days: days, now: NOW, subscriptions: rows, truncated: false };
}

function listJobs(url: URL): Omit<JobList, 'ok'> {
  const kind = url.searchParams.get('kind') ?? '';
  const state = url.searchParams.get('state') ?? '';
  const installation = url.searchParams.get('installation') ?? '';
  const limit = pageSize(url);
  const after = cursor(url, 2);
  const rows = jobs.filter((job) => {
    if (kind && job.kind !== kind) return false;
    if (state && job.state !== state) return false;
    if (installation && job.installation_id !== installation) return false;
    if (after && (job.created_at > after[0] || (job.created_at === after[0] && job.id >= after[1]))) return false;
    return true;
  }).sort(newestJob);
  const page = rows.slice(0, limit);
  const last = page[page.length - 1];
  return { jobs: page, next_cursor: rows.length > limit && last ? encodeCursor([last.created_at, last.id]) : null };
}

function listAudit(url: URL): Omit<AuditList, 'ok'> {
  const rows = filterAudit(url);
  const limit = pageSize(url);
  const after = cursor(url, 2);
  const filtered = rows.filter((row) => !after || row.at < after[0] || (row.at === after[0] && row.id < after[1]));
  const page = filtered.slice(0, limit);
  const last = page[page.length - 1];
  return { audit: page, next_cursor: filtered.length > limit && last ? encodeCursor([last.at, last.id]) : null };
}

function csv(url: URL): MockResult {
  const rows = filterAudit(url);
  const fields = ['id', 'at', 'operator', 'action', 'installation_id', 'before_json', 'after_json', 'request_id'] as const;
  const lines = [fields.join(',')];
  for (const row of rows) lines.push(fields.map((field) => csvCell(row[field])).join(','));
  return {
    status: 200,
    body: lines.join('\r\n') + '\r\n',
    headers: { 'content-type': 'text/csv; charset=utf-8', 'x-truncated': '0' },
  };
}

function filterAudit(url: URL) {
  const installation = url.searchParams.get('installation') ?? '';
  const operator = (url.searchParams.get('operator') ?? '').toLowerCase();
  const action = url.searchParams.get('action') ?? '';
  const from = url.searchParams.get('from') ?? '';
  const to = url.searchParams.get('to') ?? '';
  return audit.filter((row) => {
    if (installation && row.installation_id !== installation) return false;
    if (operator && row.operator !== operator) return false;
    if (action && row.action !== action) return false;
    if (from && row.at < from) return false;
    if (to && row.at >= to) return false;
    return true;
  }).sort(newestAudit);
}

function pageSize(url: URL): number {
  const raw = url.searchParams.get('limit');
  if (!raw) return 50;
  if (!/^\d{1,3}$/.test(raw) || Number(raw) < 1 || Number(raw) > 100) throw new QueryError('limit');
  return Number(raw);
}

function cursor(url: URL, parts: number): string[] | null {
  const raw = url.searchParams.get('cursor');
  if (!raw) return null;
  try {
    const pad = '='.repeat((4 - (raw.length % 4)) % 4);
    const keys = JSON.parse(atob(raw.replace(/-/g, '+').replace(/_/g, '/') + pad)) as unknown;
    if (!Array.isArray(keys) || keys.length !== parts || !keys.every((key) => typeof key === 'string')) throw new Error();
    return keys as string[];
  } catch {
    throw new QueryError('cursor');
  }
}

function encodeCursor(keys: string[]): string {
  return btoa(JSON.stringify(keys)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function newestJob(a: { created_at: string; id: string }, b: { created_at: string; id: string }): number {
  return b.created_at.localeCompare(a.created_at) || b.id.localeCompare(a.id);
}

function newestAudit(a: { at: string; id: string }, b: { at: string; id: string }): number {
  return b.at.localeCompare(a.at) || b.id.localeCompare(a.id);
}

function csvCell(value: unknown): string {
  if (value === null || value === undefined) return '';
  let text = String(value);
  if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`;
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}
