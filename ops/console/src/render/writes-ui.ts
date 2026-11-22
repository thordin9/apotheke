import type { OpsClient } from '../api/client.ts';
import { OpsError } from '../api/client.ts';
import { explain, fieldOf, PRIMARY_MESSAGE } from '../api/messages.ts';
import type { InstallationDetail, LifecycleJob, LifecycleJobKind, LimitKey, PlanLimit, PlanWithLimits } from '../api/types.ts';
import { LIMIT_KEYS } from '../api/types.ts';
import { el, label } from './view.ts';

const KINDS: LifecycleJobKind[] = ['provision', 'suspend', 'resume', 'export', 'delete', 'purge'];

let flash = '';
let poll = 0;

export function discardSecrets(): void {
  const link = document.getElementById('claim-link');
  if (link) link.textContent = '';
  document.getElementById('claim-once')?.remove();
  document.querySelectorAll('dialog.write-dialog').forEach((node) => node.remove());
  if (poll) window.clearInterval(poll);
  poll = 0;
}

export function takeFlash(): string {
  const text = flash;
  flash = '';
  return text;
}

export function watchOpenJobs(open: boolean, refresh: () => void): void {
  if (poll) window.clearInterval(poll);
  poll = 0;
  if (!open) return;
  poll = window.setInterval(() => {
    if (document.hidden) return;
    refresh();
  }, 5000);
}

export function primaryNote(): HTMLElement {
  return el('p', { id: 'primary-refused' }, PRIMARY_MESSAGE);
}

export function usersLaterNote(): HTMLElement {
  return el('p', { id: 'users-later' }, 'Not available yet.');
}

export function limitsStoredNote(): HTMLElement {
  return el('p', { id: 'limits-unenforced' }, 'Limits are stored but not enforced yet.');
}

export function claimButton(detail: InstallationDetail, client: OpsClient, refresh: () => void): HTMLButtonElement {
  return openButton('Claim link', () => hostDialog({
    title: 'Claim link',
    action: 'Create claim link',
    hosts: detail.routes.map((route) => route.host),
    warning: 'The link is shown once. A new claim voids the previous one.',
    run: async (host) => {
      const result = await client.claim(detail.installation.id, { confirm_host: host });
      showClaim(result.link, result.expires_at);
      refresh();
    },
  }));
}

export function resetButton(detail: InstallationDetail, client: OpsClient, refresh: () => void): HTMLButtonElement {
  return openButton('Reset admin', () => hostDialog({
    title: 'Reset admin',
    action: 'Reset admin',
    danger: true,
    hosts: detail.routes.map((route) => route.host),
    warning: 'This replaces the admin sign-in for this host.',
    run: async (host) => {
      await client.resetAdmin(detail.installation.id, { confirm_host: host });
      saved('Admin reset.');
      refresh();
    },
  }));
}

export function subscriptionForm(detail: InstallationDetail, client: OpsClient, refresh: () => void): HTMLElement {
  const form = formEl('Change plan or status');
  const plan = selectField(form, 'plan_id', 'Plan', []);
  const status = selectField(form, 'status', 'Status', ['trial', 'active', 'past_due', 'cancelled'].map((item) => [item, label(item)]));
  const trial = textField(form, 'trial_end', 'Trial end');
  const period = textField(form, 'period_end', 'Period end');
  const sub = detail.subscription;
  if (sub) {
    status.value = sub.status;
    trial.value = sub.trial_end ?? '';
    period.value = sub.period_end ?? '';
  }
  const confirm = el('div', { class: 'confirm-panel', hidden: '' });
  const keys = el('p', { id: 'downgrade-keys' });
  const again = el('button', { type: 'button', class: 'danger', id: 'confirm-downgrade' }, 'Confirm downgrade') as HTMLButtonElement;
  confirm.append(el('p', {}, 'Changing the plan lowers these limits.'), keys, again);
  form.append(confirm);
  void client.plans().then((list) => {
    fillSelect(plan, list.plans.map((item) => [item.id, item.name] as [string, string]), sub?.plan_id ?? '');
  }).catch((error: unknown) => showFormError(form, error));
  const submit = submitButton(form, 'Save subscription');
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void busy(form, submit, async () => {
      await client.putSubscription(detail.installation.id, subscriptionBody(plan, status, trial, period, false));
      saved('Subscription saved.');
      refresh();
    }, (error) => {
      if (error instanceof OpsError && error.code === 'downgrade_requires_confirm') {
        const names = error.body && typeof error.body === 'object' && Array.isArray((error.body as { keys?: unknown }).keys)
          ? (error.body as { keys: string[] }).keys.join(', ')
          : '';
        keys.textContent = names;
        confirm.hidden = false;
        again.focus();
        return;
      }
      showFormError(form, error);
    });
  });
  again.addEventListener('click', () => {
    void busy(form, again, async () => {
      await client.putSubscription(detail.installation.id, subscriptionBody(plan, status, trial, period, true));
      saved('Subscription saved.');
      refresh();
    });
  });
  return form;
}

export function overrideForm(detail: InstallationDetail, client: OpsClient, refresh: () => void): HTMLElement {
  const wrap = el('div');
  const rows = detail.overrides ?? [];
  if (rows.length > 0) {
    const list = el('ul', { class: 'override-list' });
    for (const row of rows) {
      const item = el('li');
      item.append(`${row.limit_key}: ${row.hard_value} `);
      item.append(openButton('Remove override', () => confirmDialog({
        title: 'Remove override',
        text: `Remove the ${row.limit_key} override?`,
        action: 'Remove override',
        danger: true,
        run: async () => {
          await client.deleteOverride(detail.installation.id, row.limit_key);
          saved('Override removed.');
          refresh();
        },
      })));
      list.append(item);
    }
    wrap.append(list);
  }
  const form = formEl('Grant limit override');
  const key = selectField(form, 'limit_key', 'Limit', LIMIT_KEYS.map((item) => [item, item]));
  const hard = textField(form, 'hard_value', 'Hard value');
  const reason = textField(form, 'reason', 'Reason');
  const expires = textField(form, 'expires_at', 'Expires');
  const submit = submitButton(form, 'Grant limit override');
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void busy(form, submit, async () => {
      await client.putOverride(detail.installation.id, key.value as LimitKey, {
        hard_value: Number(hard.value),
        reason: reason.value.trim() || null,
        expires_at: expires.value.trim() || null,
      });
      saved('Override saved.');
      refresh();
    });
  });
  wrap.append(form);
  return wrap;
}

export function jobForm(installationId: string, provisioning: boolean, client: OpsClient, refresh: () => void): HTMLFormElement {
  const form = formEl('Create job');
  const nonce = newNonce();
  form.dataset.nonce = nonce;
  const kind = selectField(form, 'kind', 'Kind', KINDS.map((item) => [item, label(item)]));
  const provision = kind.querySelector('option[value="provision"]');
  if (provision instanceof HTMLOptionElement && !provisioning) {
    provision.disabled = true;
    const fallback = kind.querySelector('option[value="export"]');
    if (fallback instanceof HTMLOptionElement) fallback.selected = true;
    form.append(el('p', { id: 'provision-off' }, "Provisioning is turned off, so a provision job can't be started."));
    kind.setAttribute('aria-describedby', 'provision-off');
  }
  const retention = textField(form, 'retention_days', 'Retention days');
  retention.closest('.field')?.setAttribute('hidden', '');
  kind.addEventListener('change', () => {
    const field = retention.closest('.field');
    if (field instanceof HTMLElement) field.hidden = kind.value !== 'delete';
  });
  const submit = submitButton(form, 'Create job');
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void busy(form, submit, async () => {
      const body: { kind: LifecycleJobKind; installation_id: string; nonce: string; retention_days?: number } = {
        kind: kind.value as LifecycleJobKind,
        installation_id: installationId,
        nonce: form.dataset.nonce || nonce,
      };
      if (body.kind === 'delete') body.retention_days = Number(retention.value);
      const result = await client.createJob(body);
      saved(result.created ? 'Job created. It stays paused until you confirm it.' : 'That job already exists.');
      refresh();
    });
  });
  return form;
}

export function limitsButton(plan: PlanWithLimits, client: OpsClient, refresh: () => void): HTMLButtonElement {
  return openButton('Edit limits', () => {
    const form = formEl(`Edit ${plan.name} limits`);
    const inputs = new Map<LimitKey, { soft: HTMLInputElement; hard: HTMLInputElement }>();
    for (const key of LIMIT_KEYS) {
      const row = plan.limits.find((item) => item.limit_key === key);
      const pair = el('fieldset', { class: 'field' });
      pair.append(el('legend', {}, key));
      const soft = numberInput(`limits.${key}.soft_value`, row?.soft_value);
      const hard = numberInput(`limits.${key}.hard_value`, row?.hard_value);
      pair.append(wrap('Soft', soft), wrap('Hard', hard));
      form.append(pair);
      inputs.set(key, { soft, hard });
    }
    const submit = submitButton(form, 'Save limits');
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const limits: Record<string, { soft_value: number | null; hard_value: number | null }> = {};
      for (const [key, pair] of inputs) {
        limits[key] = { soft_value: pair.soft.value === '' ? null : Number(pair.soft.value), hard_value: pair.hard.value === '' ? null : Number(pair.hard.value) };
      }
      void busy(form, submit, async () => {
        await client.putPlanLimits(plan.id, limits);
        saved('Plan limits saved.');
        refresh();
      });
    });
    mountDialog(`Edit ${plan.name} limits`, form);
  });
}

export function confirmButton(job: LifecycleJob, client: OpsClient, refresh: () => void): HTMLButtonElement {
  const name = job.kind === 'purge' ? 'Confirm purge' : job.kind === 'delete' ? 'Confirm delete' : 'Confirm';
  return openButton(job.kind === 'purge' || job.kind === 'delete' ? name : 'Confirm', () => {
    void openConfirm(job, client, refresh);
  });
}

async function openConfirm(job: LifecycleJob, client: OpsClient, refresh: () => void): Promise<void> {
  const form = formEl(job.kind === 'purge' ? 'Confirm purge' : job.kind === 'delete' ? 'Confirm delete' : 'Confirm job');
  let host: HTMLInputElement | null = null;
  if (job.kind === 'purge') {
    const detail = await client.installation(job.installation_id);
    host = hostField(form, detail.routes.map((route) => route.host));
  }
  if (job.kind === 'delete' || job.kind === 'purge') form.append(el('p', {}, 'This cannot be undone from the console.'));
  const action = job.kind === 'purge' ? 'Confirm purge' : job.kind === 'delete' ? 'Confirm delete' : 'Confirm job';
  const submit = submitButton(form, action, job.kind === 'delete' || job.kind === 'purge');
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void busy(form, submit, async () => {
      await client.confirmJob(job.id, host ? { confirm_host: host.value.trim() } : {});
      saved('Job confirmed.');
      refresh();
    });
  });
  mountDialog(action, form);
}

function hostDialog(options: { title: string; action: string; hosts: string[]; warning: string; danger?: boolean; run: (host: string) => Promise<void> }): void {
  const form = formEl(options.title);
  form.append(el('p', {}, options.warning));
  const host = hostField(form, options.hosts);
  const submit = submitButton(form, options.action, options.danger);
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void busy(form, submit, async () => {
      await options.run(host.value.trim());
    });
  });
  mountDialog(options.title, form);
}

function confirmDialog(options: { title: string; text: string; action: string; danger?: boolean; run: () => Promise<void> }): void {
  const form = formEl(options.title);
  form.append(el('p', {}, options.text));
  const submit = submitButton(form, options.action, options.danger);
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void busy(form, submit, () => options.run());
  });
  mountDialog(options.title, form);
}

let claimLink: string | null = null;

function showClaim(link: string, expires: string): void {
  claimLink = link;
  document.getElementById('claim-once')?.remove();
  const panel = el('div', { id: 'claim-once', class: 'notice', role: 'status' });
  panel.append(el('p', {}, 'This link is shown once. It is not shown again, and a new claim voids the old one.'));
  const value = el('p', { id: 'claim-link' });
  value.textContent = link;
  panel.append(value, el('p', {}, `Expires ${expires}.`));
  const copy = el('button', { type: 'button', id: 'claim-copy' }, 'Copy') as HTMLButtonElement;
  copy.addEventListener('click', () => {
    if (claimLink) void navigator.clipboard?.writeText(claimLink).catch(() => undefined);
  });
  const dismiss = el('button', { type: 'button', id: 'claim-dismiss' }, 'Dismiss') as HTMLButtonElement;
  dismiss.addEventListener('click', () => {
    claimLink = null;
    value.textContent = '';
    panel.remove();
  });
  panel.append(copy, dismiss);
  document.body.append(panel);
}

function mountDialog(title: string, form: HTMLFormElement): void {
  const dialog = document.createElement('dialog');
  dialog.className = 'write-dialog';
  const heading = el('h2', { id: 'dialog-title' }, title);
  dialog.setAttribute('aria-labelledby', 'dialog-title');
  const cancel = el('button', { type: 'button', class: 'quiet', id: 'dialog-cancel' }, 'Cancel') as HTMLButtonElement;
  cancel.addEventListener('click', () => dialog.close());
  form.append(cancel);
  dialog.append(heading, form);
  dialog.addEventListener('close', () => dialog.remove());
  document.body.append(dialog);
  dialog.showModal();
}

function openButton(name: string, open: () => void): HTMLButtonElement {
  const button = el('button', { type: 'button' }, name) as HTMLButtonElement;
  button.addEventListener('click', () => open());
  return button;
}

function formEl(name: string): HTMLFormElement {
  const form = document.createElement('form');
  form.className = 'write-form';
  form.method = 'post';
  form.action = '';
  form.dataset.write = name;
  return form;
}

function submitButton(form: HTMLFormElement, name: string, danger = false): HTMLButtonElement {
  const button = el('button', { type: 'submit', class: danger ? 'danger' : '' }, name) as HTMLButtonElement;
  form.append(button);
  return button;
}

function textField(form: HTMLElement, name: string, caption: string): HTMLInputElement {
  const input = document.createElement('input');
  input.name = name;
  input.id = `field-${name}`;
  const field = wrap(caption, input);
  form.append(field);
  return input;
}

function numberInput(name: string, value: number | null | undefined): HTMLInputElement {
  const input = document.createElement('input');
  input.name = name;
  input.id = `field-${name}`;
  input.inputMode = 'numeric';
  input.value = value === null || value === undefined ? '' : String(value);
  return input;
}

function selectField(form: HTMLElement, name: string, caption: string, options: [string, string][]): HTMLSelectElement {
  const select = document.createElement('select');
  select.name = name;
  select.id = `field-${name}`;
  fillSelect(select, options, options[0]?.[0] ?? '');
  form.append(wrap(caption, select));
  return select;
}

function fillSelect(select: HTMLSelectElement, options: [string, string][], selected: string): void {
  select.replaceChildren();
  for (const [value, text] of options) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = text;
    if (value === selected) option.selected = true;
    select.append(option);
  }
}

function hostField(form: HTMLElement, hosts: string[]): HTMLInputElement {
  const input = textField(form, 'confirm_host', 'Host');
  input.setAttribute('list', 'host-choices');
  const list = document.createElement('datalist');
  list.id = 'host-choices';
  for (const host of hosts) {
    const option = document.createElement('option');
    option.value = host;
    list.append(option);
  }
  form.append(list);
  return input;
}

function wrap(caption: string, control: HTMLElement): HTMLElement {
  const field = el('div', { class: 'field' });
  const name = el('label', { for: control.id }, caption);
  field.append(name, control);
  return field;
}

function subscriptionBody(plan: HTMLSelectElement, status: HTMLSelectElement, trial: HTMLInputElement, period: HTMLInputElement, confirm: boolean) {
  return {
    plan_id: plan.value,
    status: status.value as 'trial' | 'active' | 'past_due' | 'cancelled',
    trial_end: trial.value.trim() || null,
    period_end: period.value.trim() || null,
    confirm,
  };
}

async function busy(form: HTMLFormElement, button: HTMLButtonElement, run: () => Promise<void>, fail?: (error: unknown) => void): Promise<void> {
  if (form.dataset.busy === '1') return;
  form.dataset.busy = '1';
  form.setAttribute('aria-busy', 'true');
  button.disabled = true;
  clearErrors(form);
  try {
    await run();
    const dialog = form.closest('dialog');
    if (dialog instanceof HTMLDialogElement) dialog.close();
  } catch (error) {
    if (fail) fail(error);
    else showFormError(form, error);
  } finally {
    delete form.dataset.busy;
    form.removeAttribute('aria-busy');
    button.disabled = false;
  }
}

function showFormError(form: HTMLFormElement, error: unknown): void {
  const message = explain(error);
  const detail = error instanceof OpsError ? fieldOf(error.detail) : null;
  if (detail) {
    const input = form.querySelector(`[name="${cssEscape(detail)}"]`);
    if (input instanceof HTMLElement) {
      const note = el('p', { class: 'field-error', id: `${input.id}-error`, role: 'alert' }, message);
      input.setAttribute('aria-describedby', note.id);
      input.insertAdjacentElement('afterend', note);
      return;
    }
  }
  form.prepend(el('p', { class: 'field-error', role: 'alert' }, message));
}

function clearErrors(form: HTMLFormElement): void {
  form.querySelectorAll('.field-error').forEach((node) => node.remove());
}

function cssEscape(value: string): string {
  return value.replace(/["\\]/g, '\\$&');
}

function saved(message: string): void {
  flash = message;
}

function newNonce(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function limitRows(planLimits: PlanLimit[]): Record<string, { soft_value: number | null; hard_value: number | null }> {
  const rows: Record<string, { soft_value: number | null; hard_value: number | null }> = {};
  for (const key of LIMIT_KEYS) {
    const found = planLimits.find((item) => item.limit_key === key);
    rows[key] = { soft_value: found?.soft_value ?? null, hard_value: found?.hard_value ?? null };
  }
  return rows;
}
