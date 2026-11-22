// OPS_MOCK_FIXTURE
import type { LifecycleJob, LifecycleJobKind, LimitKey, LimitOverride, PlanLimit, Subscription, SubscriptionStatus } from '../src/api/types.ts';
import type { MockResult } from './mock-api.ts';

export const LIMIT_KEYS: LimitKey[] = [
  'items', 'storage_bytes', 'photos', 'users', 'autofill_calls_month', 'requests_month',
  'container_hours_month', 'sleep_after_ms', 'import_file_bytes', 'photos_per_item',
];

const KINDS: LifecycleJobKind[] = ['provision', 'suspend', 'resume', 'export', 'delete', 'purge'];
const SUB_STATUS: SubscriptionStatus[] = ['trial', 'active', 'past_due', 'cancelled'];
const NONCE = /^[A-Za-z0-9_-]{8,128}$/;
const DAY = /^\d{4}-\d{2}-\d{2}$/;
const STAMP = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

export interface WriteState {
  jobs: LifecycleJob[];
  limits: PlanLimit[];
  subscriptions: Subscription[];
  overrides: LimitOverride[];
  primaryPlan: (planId: string) => boolean;
  primaryInstallation: (id: string) => boolean;
  hostsOf: (id: string) => string[] | undefined;
  statusOf: (id: string) => string | undefined;
  hasAdmin: (id: string) => boolean;
  planActive: (id: string) => boolean | null;
  provisioning: boolean;
}

export interface WriteGate {
  csrf?: string;
  origin?: string;
}

export function refuseWrite(gate: WriteGate | undefined, writes: boolean): MockResult | null {
  if (!writes) return fail(403, 'writes_disabled');
  if (gate?.csrf !== '1' || gate.origin !== 'http://127.0.0.1:4181') return fail(403, 'csrf');
  return null;
}

export function applyWrite(method: string, path: string, raw: string, state: WriteState): MockResult | null {
  const limits = /^\/plans\/([^/]+)\/limits$/.exec(path);
  if (limits && method === 'PUT') return putPlanLimits(decodeURIComponent(limits[1]), raw, state);
  const sub = /^\/installations\/([^/]+)\/subscription$/.exec(path);
  if (sub && method === 'PUT') return putSubscription(decodeURIComponent(sub[1]), raw, state);
  if (path === '/jobs' && method === 'POST') return postJob(raw, state);
  const confirm = /^\/jobs\/([^/]+)\/confirm$/.exec(path);
  if (confirm && method === 'POST') return postConfirm(decodeURIComponent(confirm[1]), raw, state);
  const override = /^\/installations\/([^/]+)\/limits\/([^/]+)$/.exec(path);
  if (override && method === 'PUT') return putOverride(decodeURIComponent(override[1]), decodeURIComponent(override[2]), raw, state);
  if (override && method === 'DELETE') return deleteOverride(decodeURIComponent(override[1]), decodeURIComponent(override[2]), state);
  const claim = /^\/installations\/([^/]+)\/claim$/.exec(path);
  if (claim && method === 'POST') return postClaim(decodeURIComponent(claim[1]), raw, state);
  const reset = /^\/installations\/([^/]+)\/admin-reset$/.exec(path);
  if (reset && method === 'POST') return postReset(decodeURIComponent(reset[1]), raw, state);
  return null;
}

function putPlanLimits(planId: string, raw: string, state: WriteState): MockResult {
  if (state.planActive(planId) === null) return fail(404, 'not_found');
  if (state.primaryPlan(planId)) return fail(403, 'primary');
  const body = object(raw, ['limits']);
  if (isFail(body)) return body;
  const rows = body.limits;
  if (!rows || typeof rows !== 'object' || Array.isArray(rows)) return fail(422, 'invalid_body', 'limits');
  const next: PlanLimit[] = [];
  for (const [key, value] of Object.entries(rows as Record<string, unknown>)) {
    if (!LIMIT_KEYS.includes(key as LimitKey)) return fail(422, 'invalid_body', `limits.${key}`);
    if (value === null) continue;
    if (!value || typeof value !== 'object' || Array.isArray(value)) return fail(422, 'invalid_body', `limits.${key}`);
    const record = value as Record<string, unknown>;
    for (const field of Object.keys(record)) {
      if (field !== 'soft_value' && field !== 'hard_value') return fail(422, 'invalid_body', `limits.${key}.${field}`);
    }
    const soft = intOrNull(record.soft_value);
    const hard = intOrNull(record.hard_value);
    if (soft === undefined) return fail(422, 'invalid_body', `limits.${key}.soft_value`);
    if (hard === undefined) return fail(422, 'invalid_body', `limits.${key}.hard_value`);
    if (soft !== null && hard !== null && soft > hard) return fail(422, 'invalid_body', `limits.${key}.soft_value`);
    next.push({ plan_id: planId, limit_key: key as LimitKey, soft_value: soft, hard_value: hard });
  }
  for (let index = state.limits.length - 1; index >= 0; index -= 1) {
    if (state.limits[index].plan_id === planId) state.limits.splice(index, 1);
  }
  state.limits.push(...next);
  return { status: 200, body: { ok: true, plan_id: planId, limits: next } };
}

function putSubscription(id: string, raw: string, state: WriteState): MockResult {
  if (state.primaryInstallation(id)) return fail(403, 'primary');
  if (!state.statusOf(id)) return fail(404, 'not_found');
  const current = state.subscriptions.find((item) => item.installation_id === id);
  const body = object(raw, ['plan_id', 'status', 'trial_end', 'period_end', 'confirm']);
  if (isFail(body)) return body;
  const present = ['plan_id', 'status', 'trial_end', 'period_end'].filter((key) => body[key] !== undefined);
  if (present.length === 0) return fail(422, 'invalid_body', 'plan_id');
  if (body.confirm !== undefined && typeof body.confirm !== 'boolean') return fail(422, 'invalid_body', 'confirm');
  if (body.status !== undefined && !SUB_STATUS.includes(body.status as SubscriptionStatus)) return fail(422, 'invalid_body', 'status');
  for (const key of ['trial_end', 'period_end'] as const) {
    if (body[key] === undefined || body[key] === null) continue;
    if (typeof body[key] !== 'string' || (!DAY.test(body[key]) && !STAMP.test(body[key]))) return fail(422, 'invalid_body', key);
  }
  const planId = typeof body.plan_id === 'string' ? body.plan_id : current?.plan_id;
  const status = typeof body.status === 'string' ? body.status as SubscriptionStatus : current?.status;
  if (!planId) return fail(422, 'invalid_body', 'plan_id');
  if (!status) return fail(422, 'invalid_body', 'status');
  if (planId !== current?.plan_id) {
    const active = state.planActive(planId);
    if (active === null) return fail(422, 'unknown_plan');
    if (!active) return fail(409, 'plan_inactive');
    if (current) {
      const keys = downgradeKeys(current.plan_id, planId, state);
      if (keys.length > 0 && body.confirm !== true) {
        return { status: 409, body: { ok: false, code: 'downgrade_requires_confirm', keys } };
      }
    }
  }
  const next: Subscription = current ?? {
    installation_id: id,
    plan_id: planId,
    status,
    trial_end: null,
    period_end: null,
    notes: null,
    updated_at: '2026-11-21T12:00:00Z',
  };
  next.plan_id = planId;
  next.status = status;
  if (body.trial_end !== undefined) next.trial_end = body.trial_end as string | null;
  if (body.period_end !== undefined) next.period_end = body.period_end as string | null;
  next.updated_at = '2026-11-21T12:00:00Z';
  if (!current) state.subscriptions.push(next);
  return { status: 200, body: { ok: true, subscription: next } };
}

function postJob(raw: string, state: WriteState): MockResult {
  const body = object(raw, ['kind', 'installation_id', 'nonce', 'retention_days']);
  if (isFail(body)) return body;
  if (typeof body.kind !== 'string' || !KINDS.includes(body.kind as LifecycleJobKind)) return fail(422, 'invalid_body', 'kind');
  if (typeof body.installation_id !== 'string') return fail(422, 'invalid_body', 'installation_id');
  if (typeof body.nonce !== 'string' || !NONCE.test(body.nonce)) return fail(422, 'invalid_body', 'nonce');
  const kind = body.kind as LifecycleJobKind;
  if (kind === 'delete') {
    if (!integer(body.retention_days) || (body.retention_days as number) < 1 || (body.retention_days as number) > 3650) return fail(422, 'invalid_body', 'retention_days');
  } else if (body.retention_days !== undefined) return fail(422, 'invalid_body', 'retention_days');
  if (kind === 'provision' && !state.provisioning) return fail(403, 'provisioning_disabled');
  if (state.primaryInstallation(body.installation_id)) return fail(403, 'primary');
  if (!state.hostsOf(body.installation_id) && body.installation_id !== 'old') return fail(404, 'not_found');
  if (body.installation_id === 'old') return fail(410, 'gone');
  const existing = state.jobs.find((job) => job.idempotency_key === body.nonce);
  if (existing) return { status: 200, body: { ok: true, created: false, job: existing } };
  const job: LifecycleJob = {
    id: `job-${body.nonce.slice(0, 8)}`,
    installation_id: body.installation_id,
    kind,
    idempotency_key: body.nonce,
    state: 'paused',
    step: null,
    params_json: kind === 'delete' ? JSON.stringify({ retention_days: body.retention_days }) : null,
    result_json: null,
    error_code: null,
    created_by: 'operator',
    confirmed_by: null,
    confirmed_at: null,
    created_at: '2026-11-21T12:00:00Z',
    updated_at: '2026-11-21T12:00:00Z',
  };
  state.jobs.push(job);
  return { status: 201, body: { ok: true, created: true, job } };
}

function postConfirm(id: string, raw: string, state: WriteState): MockResult {
  const job = state.jobs.find((item) => item.id === id);
  if (!job) return fail(404, 'not_found');
  const body = object(raw, job.kind === 'purge' ? ['confirm_host'] : []);
  if (isFail(body)) return body;
  if (state.primaryInstallation(job.installation_id)) return fail(403, 'primary');
  if (job.installation_id === 'old') return fail(410, 'gone');
  if (job.kind === 'provision' && !state.provisioning) return fail(403, 'provisioning_disabled');
  if (job.state !== 'paused') return fail(409, 'not_paused', job.state);
  if (job.kind === 'purge') {
    if (typeof body.confirm_host !== 'string') return fail(422, 'invalid_body', 'confirm_host');
    const hosts = state.hostsOf(job.installation_id) ?? [];
    if (!hosts.includes(body.confirm_host)) return fail(422, 'confirm_host');
  }
  job.state = 'queued';
  job.confirmed_by = 'operator';
  job.confirmed_at = '2026-11-21T12:00:00Z';
  job.updated_at = job.confirmed_at;
  return { status: 200, body: { ok: true, job } };
}

function putOverride(id: string, key: string, raw: string, state: WriteState): MockResult {
  if (state.primaryInstallation(id)) return fail(403, 'primary');
  if (id === 'old') return fail(410, 'gone');
  const hosts = state.hostsOf(id);
  if (!hosts) return fail(404, 'not_found');
  if (!LIMIT_KEYS.includes(key as LimitKey)) return fail(404, 'unknown_limit');
  const body = object(raw, ['hard_value', 'reason', 'expires_at']);
  if (isFail(body)) return body;
  if (!integer(body.hard_value) || (body.hard_value as number) < 0) return fail(422, 'invalid_body', 'hard_value');
  const sub = state.subscriptions.find((item) => item.installation_id === id);
  if (!sub) return fail(409, 'no_subscription');
  const planLimit = state.limits.find((item) => item.plan_id === sub.plan_id && item.limit_key === key);
  if (!planLimit || planLimit.hard_value === null) return fail(409, 'no_plan_limit');
  if ((body.hard_value as number) < planLimit.hard_value) {
    return { status: 422, body: { ok: false, code: 'below_plan_limit', detail: 'hard_value', plan_hard_value: planLimit.hard_value } };
  }
  if (body.reason !== undefined && body.reason !== null && (typeof body.reason !== 'string' || body.reason.length > 200)) return fail(422, 'invalid_body', 'reason');
  if (body.expires_at !== undefined && body.expires_at !== null && (typeof body.expires_at !== 'string' || !STAMP.test(body.expires_at))) return fail(422, 'invalid_body', 'expires_at');
  const override: LimitOverride = {
    installation_id: id,
    limit_key: key as LimitKey,
    hard_value: body.hard_value as number,
    reason: typeof body.reason === 'string' ? body.reason : null,
    expires_at: typeof body.expires_at === 'string' ? body.expires_at : null,
  };
  const index = state.overrides.findIndex((item) => item.installation_id === id && item.limit_key === key);
  if (index >= 0) state.overrides[index] = override;
  else state.overrides.push(override);
  return { status: 200, body: { ok: true, override } };
}

function deleteOverride(id: string, key: string, state: WriteState): MockResult {
  if (state.primaryInstallation(id)) return fail(403, 'primary');
  if (id === 'old') return fail(410, 'gone');
  if (!state.hostsOf(id)) return fail(404, 'not_found');
  if (!LIMIT_KEYS.includes(key as LimitKey)) return fail(404, 'unknown_limit');
  const index = state.overrides.findIndex((item) => item.installation_id === id && item.limit_key === key);
  if (index < 0) return fail(404, 'not_found');
  state.overrides.splice(index, 1);
  return { status: 200, body: { ok: true } };
}

function postClaim(id: string, raw: string, state: WriteState): MockResult {
  return hostAction(id, raw, state, (host) => ({
    status: 200,
    body: { ok: true, link: `http://127.0.0.1/claim/${id}/${encodeURIComponent(host)}`, expires_at: '2026-11-22T12:00:00Z' },
  }));
}

function postReset(id: string, raw: string, state: WriteState): MockResult {
  return hostAction(id, raw, state, () => {
    if (state.hasAdmin(id)) return fail(409, 'active_admin_exists');
    return { status: 200, body: { ok: true } };
  });
}

function hostAction(id: string, raw: string, state: WriteState, ok: (host: string) => MockResult): MockResult {
  if (state.primaryInstallation(id)) return fail(403, 'primary');
  const status = state.statusOf(id);
  if (!status) return fail(404, 'not_found');
  if (status === 'deleted') return fail(410, 'gone');
  if (status !== 'provisioning' && status !== 'active') return fail(409, 'not_startable');
  const hosts = state.hostsOf(id) ?? [];
  if (hosts.length === 0) return fail(409, 'no_host');
  const body = object(raw, ['confirm_host']);
  if (isFail(body)) return body;
  if (typeof body.confirm_host !== 'string') return fail(422, 'invalid_body', 'confirm_host');
  const typed = body.confirm_host.trim().toLowerCase();
  if (!hosts.includes(typed)) return fail(422, 'confirm_host');
  return ok(typed);
}

function downgradeKeys(from: string, to: string, state: WriteState): string[] {
  const old = new Map(state.limits.filter((row) => row.plan_id === from).map((row) => [row.limit_key, row.hard_value]));
  return state.limits
    .filter((row) => row.plan_id === to && row.hard_value !== null && (old.get(row.limit_key) == null || (row.hard_value as number) < (old.get(row.limit_key) as number)))
    .map((row) => row.limit_key)
    .sort();
}

export function advanceJob(job: LifecycleJob, ticks: Map<string, number>): void {
  if (!job.confirmed_at || (job.state !== 'queued' && job.state !== 'running')) return;
  const seen = (ticks.get(job.id) ?? 0) + 1;
  ticks.set(job.id, seen);
  if (seen >= 2) job.state = 'done';
  else job.state = 'running';
  job.updated_at = '2026-11-21T12:00:05Z';
}

function object(raw: string, keys: string[]): Record<string, unknown> | MockResult {
  if (raw.length > 4096) return fail(422, 'invalid_body', 'body');
  let parsed: unknown;
  try {
    parsed = raw === '' ? {} : JSON.parse(raw);
  } catch {
    return fail(422, 'invalid_body', 'body');
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return fail(422, 'invalid_body', 'body');
  const record = parsed as Record<string, unknown>;
  for (const key of Object.keys(record)) {
    if (!keys.includes(key)) return fail(422, 'invalid_body', key);
  }
  return record;
}

function isFail(value: Record<string, unknown> | MockResult): value is MockResult {
  return typeof (value as MockResult).status === 'number';
}

function integer(value: unknown): boolean {
  return typeof value === 'number' && Number.isInteger(value);
}

function intOrNull(value: unknown): number | null | undefined {
  if (value === null) return null;
  if (integer(value) && (value as number) >= 0) return value as number;
  return undefined;
}

function fail(status: number, code: string, detail?: string): MockResult {
  return { status, body: detail ? { ok: false, code, detail } : { ok: false, code } };
}
