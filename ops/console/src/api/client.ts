import type { InstallationDetail, InstallationList, InstallationQuery, OpsConfig, Overview } from './types.ts';

// No CSRF token. Writes send this fixed header; csrfHeaders() is the only place
// that sets it, so a real token can replace the fixed value later. The server
// also checks that Origin equals the ops host.
export const CSRF_HEADER = 'X-CSRF';

export function csrfHeaders(): Record<string, string> {
  return { [CSRF_HEADER]: '1' };
}

export type OpsErrorKind =
  | 'unauthorized'
  | 'forbidden'
  | 'not_found'
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
  409: 'conflict',
  410: 'gone',
  422: 'invalid',
  503: 'unavailable',
};

export class OpsError extends Error {
  readonly name = 'OpsError';
  readonly status: number;
  readonly kind: OpsErrorKind;
  readonly body: unknown;

  constructor(message: string, status: number, kind: OpsErrorKind, body: unknown) {
    super(message);
    this.status = status;
    this.kind = kind;
    this.body = body;
  }
}

const DEFAULT_BASE = '/api/ops/v1';

export function readApiBase(doc: { querySelector(selector: string): { getAttribute(name: string): string | null } | null }): string {
  const content = doc.querySelector('meta[name="ops-api-base"]')?.getAttribute('content')?.trim() ?? '';
  if (content === '') return DEFAULT_BASE;
  return content.replace(/\/$/, '');
}

export function installationPath(query: InstallationQuery = {}): string {
  const params = new URLSearchParams();
  if (query.status) params.set('status', query.status);
  if (query.plan) params.set('plan', query.plan);
  if (query.q) params.set('q', query.q);
  const search = params.toString();
  return '/installations' + (search ? `?${search}` : '');
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
  write<T>(method: string, path: string, body?: unknown): Promise<T>;
}

function errorMessage(kind: OpsErrorKind): string {
  switch (kind) {
    case 'unauthorized':
      return 'Your Access session ended, reload to sign in';
    case 'forbidden':
      return 'The request was refused: CSRF, Origin, or a disallowed identity.';
    case 'not_found':
      return 'That record was not found.';
    case 'conflict':
      return 'The request conflicts with the current record.';
    case 'gone':
      return 'This installation has been deleted.';
    case 'invalid':
      return 'The console could not accept that.';
    case 'unavailable':
      return 'The operator API is not configured, or the container cannot be reached.';
    case 'network':
      return 'The console could not reach the API.';
    case 'unexpected':
      return 'The API returned an unexpected answer.';
    default: {
      const neverKind: never = kind;
      return neverKind;
    }
  }
}

export function createClient(options: OpsClientOptions = {}): OpsClient {
  const base = (options.base ?? (typeof document === 'undefined' ? DEFAULT_BASE : readApiBase(document))).replace(/\/$/, '');
  const doFetch = options.fetchImpl ?? fetch;

  async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
    const verb = method.toUpperCase();
    const write = verb === 'POST' || verb === 'PUT';
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
      throw new OpsError(errorMessage('network'), 0, 'network', cause);
    }
    if (!response.ok) {
      let parsed: unknown = null;
      try {
        parsed = await response.json();
      } catch {
        parsed = null;
      }
      const kind = KIND_BY_STATUS[response.status] ?? 'unexpected';
      throw new OpsError(errorMessage(kind), response.status, kind, parsed);
    }
    if (response.status === 204) return undefined as T;
    return await response.json() as T;
  }

  return {
    config: () => request<OpsConfig>('GET', '/config'),
    overview: () => request<Overview>('GET', '/overview'),
    installations: (query) => request<InstallationList>('GET', installationPath(query)),
    installation: (id) => request<InstallationDetail>('GET', `/installations/${encodeURIComponent(id)}`),
    write: (method, path, body) => request(method, path, body),
  };
}
