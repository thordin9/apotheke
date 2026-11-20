// OPS_MOCK_FIXTURE
import type {
  InstallationDetail,
  InstallationList,
  InstallationStatus,
  InstallationSummary,
  Overview,
  PlanCount,
  StatusCount,
  UsageTotal,
} from '../src/api/types.ts';
import { installations, limits, plans, routes, subscriptions, usage } from './fixtures.ts';

const statuses: InstallationStatus[] = ['provisioning', 'active', 'suspended', 'deleting', 'deleted'];

export interface MockResult {
  status: number;
  body: unknown;
}

const AS_OF = '2026-11-20T00:00:00Z';

export function handleMock(method: string, url: URL, fault = ''): MockResult {
  if (!url.pathname.startsWith('/api/ops/v1')) return { status: 404, body: { error: 'not_found' } };
  if (fault === '401' || fault === '410' || fault === '503') return { status: Number(fault), body: { error: fault } };
  if (method !== 'GET' && method !== 'HEAD') return { status: 405, body: { error: 'method_not_allowed' } };
  const path = url.pathname.slice('/api/ops/v1'.length);
  if (path === '/config') return { status: 200, body: { provisioning_enabled: false, limits_enabled: false } };
  if (path === '/overview') return { status: 200, body: overview() };
  if (path === '/installations') return { status: 200, body: list(url) };
  const detail = /^\/installations\/([^/]+)$/.exec(path);
  if (detail) {
    const id = decodeURIComponent(detail[1]);
    const found = summary(id);
    if (!found) return { status: 404, body: { error: 'not_found' } };
    const body: InstallationDetail = {
      ...found,
      limits: limits.filter((limit) => limit.plan_id === found.plan.id),
      usage: usage.filter((counter) => counter.installation_id === id),
    };
    return { status: 200, body };
  }
  return { status: 404, body: { error: 'not_found' } };
}

function summary(id: string): InstallationSummary | null {
  const installation = installations.find((item) => item.id === id);
  if (!installation) return null;
  const subscription = subscriptions.find((item) => item.installation_id === id);
  const plan = plans.find((item) => item.id === subscription?.plan_id);
  if (!subscription || !plan) return null;
  return {
    installation,
    routes: routes.filter((item) => item.installation_id === id),
    subscription,
    plan,
  };
}

function list(url: URL): InstallationList {
  const status = url.searchParams.get('status') ?? '';
  const plan = url.searchParams.get('plan') ?? '';
  const q = (url.searchParams.get('q') ?? '').trim().toLowerCase();
  const rows = installations.flatMap((installation) => {
    const item = summary(installation.id);
    if (!item) return [];
    if (status && item.installation.status !== status) return [];
    if (plan && item.plan.id !== plan) return [];
    if (q && !item.routes.some((route) => route.host.toLowerCase().includes(q))) return [];
    return [item];
  });
  return { installations: rows };
}

function overview(): Overview {
  const byStatus: StatusCount[] = statuses.map((status) => ({
    status,
    count: installations.filter((item) => item.status === status).length,
  }));
  const byPlan: PlanCount[] = plans.map((plan) => ({
    plan_id: plan.id,
    plan_name: plan.name,
    count: subscriptions.filter((item) => item.plan_id === plan.id).length,
  }));
  const totals = new Map<string, number>();
  for (const counter of usage) totals.set(counter.metric, (totals.get(counter.metric) ?? 0) + counter.value);
  const usageTotals: UsageTotal[] = [...totals.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([metric, value]) => ({ metric, value }));
  return { revenue: null, as_of: AS_OF, installations_by_status: byStatus, installations_by_plan: byPlan, usage_totals: usageTotals };
}
