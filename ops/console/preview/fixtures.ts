// OPS_MOCK_FIXTURE
// Made-up preview rows. The production bundle must not contain this module.
import type {
  Installation,
  InstallationRoute,
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
  row('north', 'active', 'north@example.test'),
  row('south', 'suspended', 'south@example.test'),
  row('west', 'provisioning', 'west@example.test'),
];

export const routes: InstallationRoute[] = [
  { host: 'api.services.getapotheke.com', installation_id: 'primary', created_at: at },
  { host: 'north.example.test', installation_id: 'north', created_at: at },
  { host: 'south.example.test', installation_id: 'south', created_at: at },
  { host: 'west.example.test', installation_id: 'west', created_at: at },
];

export const subscriptions: Subscription[] = [
  sub('primary', 'internal', 'active'),
  sub('north', 'shelf', 'active'),
  sub('south', 'loft', 'past_due'),
  sub('west', 'shelf', 'trial'),
];

export const usage: UsageCounter[] = [
  { installation_id: 'primary', period: '2026-11', metric: 'items', value: 3 },
  { installation_id: 'primary', period: '2026-11', metric: 'photos', value: 1 },
  { installation_id: 'north', period: '2026-11', metric: 'items', value: 12 },
  { installation_id: 'south', period: '2026-11', metric: 'items', value: 4 },
  { installation_id: 'west', period: '2026-11', metric: 'photos', value: 2 },
];

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

function sub(id: string, plan: string, status: Subscription['status']): Subscription {
  return {
    installation_id: id,
    plan_id: plan,
    status,
    trial_end: null,
    period_end: null,
    notes: null,
    updated_at: at,
  };
}
