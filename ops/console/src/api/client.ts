import type {
  AuditList,
  AuditQuery,
  InstallationDetail,
  InstallationList,
  InstallationQuery,
  InstallationUserList,
  JobList,
  JobQuery,
  LifecycleJob,
  LifecycleJobKind,
  LimitKey,
  LimitOverride,
  OpsConfig,
  Overview,
  PlanLimit,
  PlanList,
  Subscription,
  SubscriptionDueList,
  SubscriptionStatus,
  WriteMethod,
} from './types.ts';

// No CSRF token. Writes, including DELETE, send this fixed header; csrfHeaders()
// is the only place that sets it, so a real token can replace the fixed value
// later. The server also checks that Origin equals the ops host.
export const CSRF_HEADER = 'X-CSRF';

export function csrfHeaders(): Record<string, string> {
  return { [CSRF_HEADER]: '1' };
}

export type OpsErrorKind =
  | 'unauthorized'
  | 'forbidden'
  | 'not_found'
  | 'method'
  | 'conflict'
  | 'gone'
  | 'invalid'
  | 'unavailable'
  | 'network'
  | 'unexpected';

const KIND_BY_STATUS: Record<number, OpsErrorKind> = {
  401: 'unauthorized',
  403: 'forbidden',
  404: 'not_found',
  405: 'method',
  409: 'conflict',
  410: 'gone',
  422: 'invalid',
  503: 'unavailable',
};

export class OpsError extends Error {
  readonly name = 'OpsError';
  readonly status: number;
  readonly kind: OpsErrorKind;
  readonly code: string;
  readonly detail: string;
  readonly body: unknown;

  constructor(message: string, status: number, kind: OpsErrorKind, code: string, detail: string, body: unknown) {
    super(message);
    this.status = status;
    this.kind = kind;
    this.code = code;
    this.detail = detail;
    this.body = body;
  }
}

const DEFAULT_BASE = '/api/ops/v1';

export function readApiBase(doc: { querySelector(selector: string): { getAttribute(name: string): string | null } | null }): string {
  const content = doc.querySelector('meta[name="ops-api-base"]')?.getAttribute('content')?.trim() ?? '';
  if (content === '') return DEFAULT_BASE;
  return content.replace(/\/$/, '');
}

export function withQuery(path: string, query: object): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (typeof value === 'string' && value !== '') params.set(key, value);
  }
  const search = params.toString();
  return path + (search ? `?${search}` : '');
}

export interface OpsClientOptions {
  fetchImpl?: typeof fetch;
  base?: string;
}

export interface OpsClient {
  config(): Promise<OpsConfig>;
  overview(): Promise<Overview>;
  installations(query?: InstallationQuery): Promise<InstallationList>;
  installation(id: string): Promise<InstallationDetail>;
  users(id: string): Promise<InstallationUserList>;
  plans(): Promise<PlanList>;
  subscriptions(due?: string): Promise<SubscriptionDueList>;
  jobs(query?: JobQuery): Promise<JobList>;
  audit(query?: AuditQuery): Promise<AuditList>;
  auditExportUrl(query?: AuditQuery): string;
  write<T>(method: WriteMethod, path: string, body?: unknown): Promise<T>;
  putPlanLimits(planId: string, limits: Record<string, { soft_value: number | null; hard_value: number | null } | null>): Promise<{ ok: true; plan_id: string; limits: PlanLimit[] }>;
  putSubscription(id: string, body: { plan_id?: string; status?: SubscriptionStatus; trial_end?: string | null; period_end?: string | null; confirm?: boolean }): Promise<{ ok: true; subscription: Subscription }>;
  createJob(body: { kind: LifecycleJobKind; installation_id: string; nonce: string; retention_days?: number }): Promise<{ ok: true; created: boolean; job: LifecycleJob }>;
  confirmJob(id: string, body: { confirm_host?: string }): Promise<{ ok: true; job: LifecycleJob }>;
  putOverride(id: string, key: LimitKey, body: { hard_value: number; reason: string | null; expires_at: string | null }): Promise<{ ok: true; override: LimitOverride }>;
  deleteOverride(id: string, key: LimitKey): Promise<{ ok: true }>;
  claim(id: string, body: { confirm_host: string }): Promise<{ ok: true; link: string; expires_at: string }>;
  resetAdmin(id: string, body: { confirm_host: string }): Promise<{ ok: true }>;
}

function errorMessage(status: number, code: string, detail: string): string {
  if (status === 401) return 'Your Access session ended, reload to sign in';
  if (status === 403) {
    if (code === 'csrf') return 'The request was refused: CSRF.';
    if (code === 'primary') return 'The request was refused: primary.';
    if (code === 'writes_disabled') return 'The request was refused: writes are disabled.';
    return 'The request was refused: a disallowed identity.';
  }
  if (status === 404) return 'That record was not found.';
  if (status === 405) return 'That method is not allowed.';
  if (status === 409) {
    if (code === 'not_startable' || code === 'no_host') return "This installation isn't running or has no host, so its users can't be read.";
    return 'The request conflicts with the current record.';
  }
  if (status === 410) return 'This installation has been deleted.';
  if (status === 422) {
    if (code === 'invalid_query') return `The query is not valid: ${detail || 'unknown'}.`;
    return 'The console could not accept that.';
  }
  if (status === 503) {
    if (code === 'not_configured') return 'The operator API is not configured.';
    if (code === 'access_unavailable') return 'Access is unavailable.';
    if (detail) return `The container cannot be reached: ${detail}.`;
    return 'The container cannot be reached.';
  }
  return 'The API returned an unexpected answer.';
}

export function createClient(options: OpsClientOptions = {}): OpsClient {
  const base = (options.base ?? (typeof document === 'undefined' ? DEFAULT_BASE : readApiBase(document))).replace(/\/$/, '');
  const doFetch = options.fetchImpl ?? fetch;

  async function request<T>(method: 'GET' | 'HEAD' | WriteMethod, path: string, body?: unknown): Promise<T> {
    const write = method === 'POST' || method === 'PUT' || method === 'DELETE';
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (write) Object.assign(headers, csrfHeaders());
    if (write && body !== undefined) headers['Content-Type'] = 'application/json';
    let response: Response;
    try {
      response = await doFetch(base + path, {
        method,
        credentials: 'same-origin',
        headers,
        body: write && body !== undefined ? JSON.stringify(body) : undefined,
      });
    } catch (cause) {
      throw new OpsError('The console could not reach the API.', 0, 'network', 'network', '', cause);
    }
    let parsed: unknown = null;
    const type = response.headers.get('content-type') ?? '';
    if (type.includes('json') || type === '') {
      try {
        parsed = await response.json();
      } catch {
        parsed = null;
      }
    }
    const record = parsed && typeof parsed === 'object' ? parsed as { ok?: boolean; code?: string; detail?: string } : null;
    if (!response.ok || record?.ok === false) {
      const code = typeof record?.code === 'string' ? record.code : '';
      const detail = typeof record?.detail === 'string' ? record.detail : '';
      const kind = KIND_BY_STATUS[response.status] ?? 'unexpected';
      throw new OpsError(errorMessage(response.status, code, detail), response.status, kind, code, detail, parsed);
    }
    return parsed as T;
  }

  return {
    config: () => request<OpsConfig>('GET', '/config'),
    overview: () => request<Overview>('GET', '/overview'),
    installations: (query) => request<InstallationList>('GET', withQuery('/installations', query ?? {})),
    installation: (id) => request<InstallationDetail>('GET', `/installations/${encodeURIComponent(id)}`),
    users: (id) => request<InstallationUserList>('GET', `/installations/${encodeURIComponent(id)}/users`),
    plans: () => request<PlanList>('GET', '/plans'),
    subscriptions: (due) => request<SubscriptionDueList>('GET', withQuery('/subscriptions', { due: due ?? '' })),
    jobs: (query) => request<JobList>('GET', withQuery('/jobs', query ?? {})),
    audit: (query) => request<AuditList>('GET', withQuery('/audit', query ?? {})),
    auditExportUrl: (query) => base + withQuery('/audit.csv', query ?? {}),
    write: (method, path, body) => request(method, path, body),
    putPlanLimits: (planId, limits) => request('PUT', `/plans/${encodeURIComponent(planId)}/limits`, { limits }),
    putSubscription: (id, body) => request('PUT', `/installations/${encodeURIComponent(id)}/subscription`, body),
    createJob: (body) => request('POST', '/jobs', body),
    confirmJob: (id, body) => request('POST', `/jobs/${encodeURIComponent(id)}/confirm`, body),
    putOverride: (id, key, body) => request('PUT', `/installations/${encodeURIComponent(id)}/limits/${encodeURIComponent(key)}`, body),
    deleteOverride: (id, key) => request('DELETE', `/installations/${encodeURIComponent(id)}/limits/${encodeURIComponent(key)}`),
    claim: (id, body) => request('POST', `/installations/${encodeURIComponent(id)}/claim`, body),
    resetAdmin: (id, body) => request('POST', `/installations/${encodeURIComponent(id)}/admin-reset`, body),
  };
}
