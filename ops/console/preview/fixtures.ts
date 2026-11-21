// OPS_MOCK_FIXTURE
// Made-up preview rows. The production bundle must not contain this module.
import type {
  Installation,
  InstallationRoute,
  InstallationUser,
  LifecycleJob,
  OperatorAudit,
  Plan,
  PlanLimit,
  Subscription,
  UsageCounter,
} from '../src/api/types.ts';

const at = '2026-11-01T00:00:00Z';

export const plans: Plan[] = [
  { id: 'internal', name: 'Internal', active: 1, created_at: at },
  { id: 'shelf', name: 'Shelf', active: 1, created_at: at },
  { id: 'loft', name: 'Loft', active: 1, created_at: at },
];

export const limits: PlanLimit[] = [
  { plan_id: 'shelf', limit_key: 'photos_per_item', soft_value: 20, hard_value: 40 },
  { plan_id: 'shelf', limit_key: 'items', soft_value: null, hard_value: 500 },
  { plan_id: 'loft', limit_key: 'storage_bytes', soft_value: 1000, hard_value: 5000 },
];

export const installations: Installation[] = [
  row('primary', 'active', 'primary@example.test'),
  row('north', 'active', '<img src=x onerror=alert(1)>'),
  row('south', 'suspended', 'south@example.test'),
  row('west', 'provisioning', 'west@example.test'),
];

export const routes: InstallationRoute[] = [
  { host: 'primary.example.test', installation_id: 'primary', created_at: at },
  { host: 'north.example.test', installation_id: 'north', created_at: at },
  { host: '<b>x</b>', installation_id: 'north', created_at: at },
  { host: 'south.example.test', installation_id: 'south', created_at: at },
  { host: 'west.example.test', installation_id: 'west', created_at: at },
];

export const subscriptions: Subscription[] = [
  sub('primary', 'internal', 'active', null, null),
  sub('north', 'shelf', 'active', null, '2026-11-28T00:00:00Z'),
  sub('south', 'loft', 'past_due', null, '2026-11-01T00:00:00Z'),
  sub('west', 'shelf', 'trial', '2026-12-20T00:00:00Z', null),
];

export const usage: UsageCounter[] = [
  { installation_id: 'primary', period: '2026-11', metric: 'items', value: 3 },
  { installation_id: 'primary', period: '2026-11', metric: 'photos', value: 1 },
  { installation_id: 'north', period: '2026-10', metric: 'items', value: 4 },
  { installation_id: 'north', period: '2026-11', metric: 'items', value: 12 },
  { installation_id: 'south', period: '2026-11', metric: 'items', value: 4 },
  { installation_id: 'west', period: '2026-11', metric: 'photos', value: 2 },
];

export const jobs: LifecycleJob[] = [
  job('job-north', 'north', 'provision', 'done', '2026-11-02T00:00:00Z'),
  job('job-south', 'south', 'suspend', 'paused', '2026-11-18T00:00:00Z'),
  job('job-west', 'west', 'provision', 'queued', '2026-11-19T00:00:00Z'),
];

export const audit: OperatorAudit[] = [
  { ...entry('aud-mark', '2026-11-20T00:00:00Z', 'north', 'installation.note'), before_json: '<img src=x onerror=alert(1)>' },
  entry('aud-1', '2026-11-19T00:00:00Z', 'west', 'installation.claim'),
  entry('aud-2', '2026-11-18T00:00:00Z', 'south', 'installation.admin_reset'),
  entry('aud-3', '2026-11-02T00:00:00Z', 'north', 'installation.claim'),
];

export const installationUsers: (InstallationUser & { installation_id: string })[] = [
  user('north', 1, '<b>x</b>', [], 'pending', false, '2026-11-01T10:00:00Z', null),
  user('north', 2, 'ada', ['admin', 'reader'], 'active', true, '2026-11-01T10:00:00Z', '2026-11-19T08:30:00Z'),
  user('west', 3, 'beau', ['admin'], 'disabled', false, '2026-11-03T10:00:00Z', null),
];

export const NOW = '2026-11-20T12:00:00Z';

function row(id: string, status: Installation['status'], owner: string): Installation {
  return {
    id,
    do_name: id,
    r2_prefix: `installations/${id}/`,
    status,
    owner_contact: owner,
    notes: null,
    created_at: at,
    updated_at: at,
  };
}

function sub(
  id: string,
  plan: string,
  status: Subscription['status'],
  trial: string | null,
  period: string | null,
): Subscription {
  return {
    installation_id: id,
    plan_id: plan,
    status,
    trial_end: trial,
    period_end: period,
    notes: null,
    updated_at: at,
  };
}

function job(id: string, installation: string, kind: LifecycleJob['kind'], state: LifecycleJob['state'], created: string): LifecycleJob {
  return {
    id,
    installation_id: installation,
    kind,
    idempotency_key: `${kind}:${installation}:preview`,
    state,
    step: null,
    params_json: null,
    result_json: null,
    error_code: null,
    created_by: 'ops@example.test',
    confirmed_by: state === 'paused' ? null : 'ops@example.test',
    confirmed_at: state === 'paused' ? null : created,
    created_at: created,
    updated_at: created,
  };
}

function user(
  installation: string,
  id: number,
  username: string,
  roles: string[],
  status: string,
  active: boolean,
  created: string | null,
  lastLogin: string | null,
): InstallationUser & { installation_id: string } {
  return {
    installation_id: installation,
    id,
    username,
    display_name: null,
    roles,
    active,
    status,
    created_at: created,
    last_login_at: lastLogin,
  };
}

function entry(id: string, when: string, installation: string, action: string): OperatorAudit {
  return {
    id,
    at: when,
    operator: 'ops@example.test',
    action,
    installation_id: installation,
    before_json: null,
    after_json: '{"host":"made-up.example.test"}',
    request_id: id,
  };
}
