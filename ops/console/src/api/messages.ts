import { OpsError } from './client.ts';

const TEXT: Record<string, string> = {
  unauthorized: 'Your Access session ended, reload to sign in',
  csrf: 'The request was refused: CSRF.',
  writes_disabled: 'The request was refused: writes are disabled.',
  forbidden: 'The request was refused: a disallowed identity.',
  not_configured: 'The operator API is not configured.',
  access_unavailable: 'Access is unavailable.',
  not_found: 'That record was not found.',
  method_not_allowed: 'That method is not allowed.',
  invalid_body: 'That value is not valid.',
  invalid_query: 'The query is not valid.',
  unknown_plan: 'That plan is not known.',
  plan_inactive: 'That plan is not active.',
  downgrade_requires_confirm: 'Changing the plan lowers some limits. Confirm to continue.',
  provisioning_disabled: 'Provisioning is turned off.',
  retention: 'The retention period does not fit this installation.',
  not_paused: 'This job is not paused.',
  confirm_host: 'That host does not match this installation.',
  no_subscription: 'This installation has no subscription.',
  no_plan_limit: 'This plan has no hard limit for that key.',
  below_plan_limit: 'That value is below the plan hard limit.',
  unknown_limit: 'That limit is not known.',
  not_startable: "This installation isn't running or has no host, so its users can't be read.",
  no_host: "This installation isn't running or has no host, so its users can't be read.",
  active_admin_exists: 'An admin already exists on this installation.',
  ambiguous_installation: 'More than one installation matches that host.',
  gone: 'This installation has been deleted.',
  unavailable: 'The operator API is unavailable.',
  audit_failed: 'The claim link was withheld because the audit record failed.',
  workflow_unavailable: 'The job stays queued. The workflow is unavailable.',
  network: 'The console could not reach the API.',
};

export const PRIMARY_MESSAGE = "The primary installation can't be changed here.";

export function explainCode(code: string, detail = '', extra?: { plan_hard_value?: unknown; keys?: unknown }): string {
  if (code === 'primary') return PRIMARY_MESSAGE;
  if (code === 'invalid_query') return `The query is not valid: ${detail || 'unknown'}.`;
  if (code === 'invalid_body') return detail ? `That value is not valid: ${detail}.` : TEXT.invalid_body;
  if (code === 'below_plan_limit') {
    const hard = extra?.plan_hard_value;
    return typeof hard === 'number' ? `That value is below the plan hard limit (${hard}).` : TEXT.below_plan_limit;
  }
  if (code === 'downgrade_requires_confirm' && Array.isArray(extra?.keys) && extra.keys.length > 0) {
    return `Changing the plan lowers ${extra.keys.join(', ')}. Confirm to continue.`;
  }
  if (code === 'not_paused' && detail) return `This job is ${detail}, not paused.`;
  if (code === 'unavailable' && detail) return `The container cannot be reached: ${detail}.`;
  if (TEXT[code]) return TEXT[code];
  if (code) return `The API refused the request (${code}).`;
  return 'The API returned an unexpected answer.';
}

export function explain(error: unknown): string {
  if (!(error instanceof OpsError)) return 'The console could not reach the API.';
  const body = error.body && typeof error.body === 'object' ? error.body as { plan_hard_value?: unknown; keys?: unknown } : undefined;
  return explainCode(error.code, error.detail, body);
}

/** A 422 detail that names a form field, such as limits.items.soft_value. */
export function fieldOf(detail: string): string | null {
  if (!detail || /\s/.test(detail)) return null;
  if (detail === 'body' || detail === 'confirm') return null;
  return detail;
}
