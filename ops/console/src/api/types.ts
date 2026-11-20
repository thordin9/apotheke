// Columns from edge/migrations/control/0001_control_plane.sql, plus the limit key
// photos_per_item and lifecycle_jobs. No secrets, keys, tokens or passwords.

export type InstallationStatus = 'provisioning' | 'active' | 'suspended' | 'deleting' | 'deleted';

export type SubscriptionStatus = 'trial' | 'active' | 'past_due' | 'cancelled';

export type LimitKey =
  | 'items'
  | 'storage_bytes'
  | 'photos'
  | 'photos_per_item'
  | 'users'
  | 'autofill_calls_month'
  | 'requests_month'
  | 'container_hours_month'
  | 'sleep_after_ms'
  | 'import_file_bytes';

export type LifecycleJobState = 'paused' | 'queued' | 'running' | 'done' | 'failed';

export type LifecycleJobKind = 'provision' | 'suspend' | 'resume' | 'export' | 'delete' | 'purge';

export interface Plan {
  id: string;
  name: string;
  active: 0 | 1;
  created_at: string;
}

export interface PlanLimit {
  plan_id: string;
  limit_key: LimitKey;
  soft_value: number | null;
  hard_value: number | null;
}

export interface Installation {
  id: string;
  do_name: string;
  r2_prefix: string;
  status: InstallationStatus;
  owner_contact: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface InstallationRoute {
  host: string;
  installation_id: string;
  created_at: string;
}

export interface Subscription {
  installation_id: string;
  plan_id: string;
  status: SubscriptionStatus;
  trial_end: string | null;
  period_end: string | null;
  notes: string | null;
  updated_at: string;
}

export interface UsageCounter {
  installation_id: string;
  period: string;
  metric: string;
  value: number;
}

export interface OperatorAudit {
  id: string;
  at: string;
  operator: string;
  action: string;
  installation_id: string | null;
  before_json: string | null;
  after_json: string | null;
  request_id: string | null;
}

export interface LifecycleJob {
  id: string;
  installation_id: string;
  kind: LifecycleJobKind;
  idempotency_key: string;
  state: LifecycleJobState;
  step: string | null;
  params_json: string | null;
  result_json: string | null;
  error_code: string | null;
  created_by: string;
  confirmed_by: string | null;
  confirmed_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface OpsConfig {
  provisioning_enabled: boolean;
  limits_enabled: boolean;
}

export interface StatusCount {
  status: InstallationStatus;
  count: number;
}

export interface PlanCount {
  plan_id: string;
  plan_name: string;
  count: number;
}

export interface UsageTotal {
  metric: string;
  value: number;
}

export interface Overview {
  revenue: number | null;
  as_of: string;
  installations_by_status: StatusCount[];
  installations_by_plan: PlanCount[];
  usage_totals: UsageTotal[];
}

export interface InstallationSummary {
  installation: Installation;
  routes: InstallationRoute[];
  subscription: Subscription;
  plan: Plan;
}

export interface InstallationList {
  installations: InstallationSummary[];
}

export interface InstallationDetail extends InstallationSummary {
  limits: PlanLimit[];
  usage: UsageCounter[];
}

export interface InstallationQuery {
  status?: string;
  plan?: string;
  q?: string;
}
