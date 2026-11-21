// Control-plane columns from edge/migrations/control. No secrets, keys, tokens or passwords.

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

export type WriteMethod = 'POST' | 'PUT';

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

export interface PlanWithLimits extends Plan {
  limits: PlanLimit[];
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
  ok: true;
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

export interface SubscriptionCount {
  status: SubscriptionStatus;
  count: number;
}

export interface JobStateCount {
  state: 'paused' | 'queued' | 'running';
  count: number;
}

export interface UsageTotal {
  metric: string;
  value: number;
}

export interface Overview {
  ok: true;
  revenue: null;
  as_of: string | null;
  installations_by_status: StatusCount[];
  installations_by_plan: PlanCount[];
  subscriptions_by_status: SubscriptionCount[];
  open_jobs_by_state: JobStateCount[];
  usage_period: string;
  usage_totals: UsageTotal[];
}

export interface InstallationSummary {
  installation: Installation;
  routes: InstallationRoute[];
  subscription: Subscription | null;
  plan: Plan | null;
  primary: boolean;
}

export interface InstallationList {
  ok: true;
  installations: InstallationSummary[];
  next_cursor: string | null;
}

export interface InstallationDetail extends InstallationSummary {
  ok: true;
  limits: PlanLimit[];
  usage: UsageCounter[];
  jobs: LifecycleJob[];
  audit: OperatorAudit[];
}

export interface PlanList {
  ok: true;
  plans: PlanWithLimits[];
}

export interface DueSubscription extends Subscription {
  plan_name: string | null;
  due_at: string;
  hosts: string[];
  overdue: boolean;
}

export interface SubscriptionDueList {
  ok: true;
  due_days: number;
  now: string;
  subscriptions: DueSubscription[];
  truncated: boolean;
}

export interface JobList {
  ok: true;
  jobs: LifecycleJob[];
  next_cursor: string | null;
}

export interface AuditList {
  ok: true;
  audit: OperatorAudit[];
  next_cursor: string | null;
}

export interface InstallationUser {
  id: number;
  username: string;
  display_name: string | null;
  roles: string[];
  active: boolean;
  status: string;
  created_at: string | null;
  last_login_at: string | null;
}

export interface InstallationUserList {
  ok: true;
  installation: string;
  users: InstallationUser[];
  next_cursor: null;
}

export interface PageQuery {
  limit?: string;
  cursor?: string;
}

export interface InstallationQuery extends PageQuery {
  status?: string;
  plan?: string;
  q?: string;
}

export interface JobQuery extends PageQuery {
  kind?: string;
  state?: string;
  installation?: string;
}

export interface AuditQuery extends PageQuery {
  installation?: string;
  operator?: string;
  action?: string;
  from?: string;
  to?: string;
}
