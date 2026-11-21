import { statsStamp } from './format.ts';
import { OpsError, createClient, type OpsClient } from './api/client.ts';
import type {
  AuditQuery,
  InstallationQuery,
  InstallationSummary,
  InstallationUser,
  JobQuery,
  LifecycleJob,
  OperatorAudit,
  OpsConfig,
  Overview,
} from './api/types.ts';
import { writesAreEnabled } from './writes.ts';
import {
  actions,
  dataTable,
  el,
  facts,
  label,
  primaryPill,
  problem,
  quietControl,
  statGroup,
  statusPill,
  text,
  writeControl,
  writesNote,
} from './render/view.ts';
import {
  claimButton,
  confirmButton,
  discardSecrets,
  jobForm,
  limitsButton,
  limitsStoredNote,
  overrideForm,
  primaryNote,
  resetButton,
  subscriptionForm,
  takeFlash,
  usersLaterNote,
  watchOpenJobs,
} from './render/writes-ui.ts';

const STATUSES = ['provisioning', 'active', 'suspended', 'deleting', 'deleted'] as const;
const JOB_KINDS = ['provision', 'suspend', 'resume', 'export', 'delete', 'purge'] as const;
const JOB_STATES = ['paused', 'queued', 'running', 'done', 'failed'] as const;

const NAV: { name: string; label: string; path: string }[] = [
  { name: 'overview', label: 'Overview', path: '/overview' },
  { name: 'installations', label: 'Installations', path: '/installations' },
  { name: 'plans', label: 'Plans and limits', path: '/plans' },
  { name: 'subscriptions', label: 'Subscriptions', path: '/subscriptions' },
  { name: 'jobs', label: 'Jobs', path: '/jobs' },
  { name: 'audit', label: 'Audit log', path: '/audit' },
];

type Route =
  | { name: 'overview' }
  | { name: 'installations' }
  | { name: 'installation'; id: string }
  | { name: 'users'; id: string }
  | { name: 'plans' }
  | { name: 'subscriptions' }
  | { name: 'jobs' }
  | { name: 'audit' }
  | { name: 'missing' };

const TITLES: Record<Route['name'], string> = {
  overview: 'Overview',
  installations: 'Installations',
  installation: 'Installation',
  users: 'Users',
  plans: 'Plans and limits',
  subscriptions: 'Subscriptions',
  jobs: 'Jobs',
  audit: 'Audit log',
  missing: 'Not in the console',
};

const WRITE_SCREENS = new Set<Route['name']>(['installation', 'users', 'plans', 'jobs']);

let generation = 0;
let lastKey = '';
let client: OpsClient = createClient();
let ready = false;
let bootError: unknown = null;
let provisioningEnabled = false;
let limitsEnabled = false;
let writesEnabled = false;
let pageKey = '';
let pageRows: unknown[] = [];
let pageCursor: string | null = null;

export function appFlags(): { provisioningEnabled: boolean; limitsEnabled: boolean; writesEnabled: boolean } {
  return { provisioningEnabled, limitsEnabled, writesEnabled };
}

export function start(next: OpsClient = createClient()): void {
  client = next;
  if (!document.querySelector('#nav')) return;
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target.closest('#try-again')) {
      event.preventDefault();
      if (!ready) void boot();
      else void show(false);
      return;
    }
    if (target.closest('#load-more')) {
      event.preventDefault();
      void loadMore();
      return;
    }
    const link = target.closest('a');
    if (!link) return;
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#') || link.hasAttribute('download') || link.id === 'audit-csv' || link.id === 'reload') return;
    const url = new URL(href, location.origin);
    if (url.origin !== location.origin) return;
    event.preventDefault();
    go(url.pathname + url.search);
  });
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.id !== 'host-search' && form.id !== 'job-search' && form.id !== 'audit-search') return;
    event.preventDefault();
    const params = new URLSearchParams(location.search);
    const fields = form.querySelectorAll('input');
    for (let index = 0; index < fields.length; index += 1) {
      const field = fields[index];
      if (!(field instanceof HTMLInputElement) || !field.name) continue;
      const textValue = field.value.trim();
      if (textValue) params.set(field.name, textValue);
      else params.delete(field.name);
    }
    params.delete('cursor');
    const path = form.getAttribute('action') || location.pathname;
    const search = params.toString();
    go(path + (search ? `?${search}` : ''));
  });
  window.addEventListener('popstate', () => { void show(false); });
  void boot();
}

async function boot(): Promise<void> {
  paintNav(parseRoute(location.pathname));
  try {
    const config = await client.config();
    rememberConfig(config);
    ready = true;
    bootError = null;
    await show(false);
  } catch (error) {
    ready = false;
    bootError = error;
    paintFailure(error, false);
  }
}

function rememberConfig(config: OpsConfig): void {
  provisioningEnabled = config.provisioning_enabled;
  limitsEnabled = config.limits_enabled;
  writesEnabled = writesAreEnabled(config);
  document.body.dataset.provisioningEnabled = String(provisioningEnabled);
  document.body.dataset.limitsEnabled = String(limitsEnabled);
  document.body.dataset.writesEnabled = String(writesEnabled);
  const pill = document.querySelector('#read-only');
  if (pill instanceof HTMLElement) pill.hidden = writesEnabled;
}

function refreshPage(): void {
  pageKey = '';
  void show(false);
}

function go(url: string): void {
  const next = new URL(url, location.origin);
  const changed = routeKey(parseRoute(next.pathname)) !== routeKey(parseRoute(location.pathname));
  if (next.pathname + next.search !== location.pathname + location.search) {
    history.pushState(null, '', next.pathname + next.search);
  }
  void show(changed);
}

async function show(changed: boolean): Promise<void> {
  if (!ready) {
    paintFailure(bootError, changed);
    return;
  }
  await render(changed);
}

function safeDecode(value: string): string | null {
  try {
    return decodeURIComponent(value);
  } catch (error) {
    if (error instanceof URIError) return null;
    throw error;
  }
}

function parseRoute(pathname: string): Route {
  const raw = pathname.split('/').filter(Boolean);
  const parts: string[] = [];
  for (const part of raw) {
    const decoded = safeDecode(part);
    if (decoded === null) return { name: 'missing' };
    parts.push(decoded);
  }
  if (parts.length === 0 || (parts.length === 1 && parts[0] === 'overview')) return { name: 'overview' };
  if (parts[0] === 'installations' && parts.length === 1) return { name: 'installations' };
  if (parts[0] === 'installations' && parts.length === 2) return { name: 'installation', id: parts[1] };
  if (parts[0] === 'installations' && parts.length === 3 && parts[2] === 'users') return { name: 'users', id: parts[1] };
  if (parts.length === 1 && (parts[0] === 'plans' || parts[0] === 'subscriptions' || parts[0] === 'jobs' || parts[0] === 'audit')) {
    return { name: parts[0] };
  }
  return { name: 'missing' };
}

function routeKey(route: Route): string {
  return route.name + ('id' in route ? route.id : '');
}

async function render(changed: boolean): Promise<void> {
  const gen = ++generation;
  const route = parseRoute(location.pathname);
  const key = routeKey(route);
  const moveFocus = changed || (lastKey !== '' && lastKey !== key);
  if (moveFocus) discardSecrets();
  lastKey = key;
  paintNav(route);
  const main = document.querySelector('#main');
  if (!(main instanceof HTMLElement)) return;
  main.replaceChildren();
  const heading = el('h1', {}, TITLES[route.name]);
  heading.tabIndex = -1;
  heading.id = 'screen-title';
  main.append(heading);
  document.title = `${TITLES[route.name]}, Apotheke operator console`;
  if (moveFocus) heading.focus({ focusVisible: true } as FocusOptions);
  main.setAttribute('aria-busy', 'true');
  const timer = window.setTimeout(() => {
    if (gen !== generation) return;
    main.append(el('p', { class: 'status', role: 'status' }, 'Loading…'));
  }, 150);
  try {
    if (WRITE_SCREENS.has(route.name) && !writesEnabled) main.append(writesNote(false));
    else if (writesEnabled && !limitsEnabled && (route.name === 'installation' || route.name === 'plans')) main.append(limitsStoredNote());
    const saved = takeFlash();
    if (saved) main.append(el('p', { id: 'saved', class: 'notice', role: 'status' }, saved));
    if (route.name === 'overview') await paintOverview(main, gen);
    else if (route.name === 'installations') await paintInstallations(main, gen);
    else if (route.name === 'installation') await paintInstallation(main, route.id, gen);
    else if (route.name === 'users') await paintUsers(main, route.id, gen);
    else if (route.name === 'plans') await paintPlans(main, gen);
    else if (route.name === 'subscriptions') await paintSubscriptions(main, gen);
    else if (route.name === 'jobs') await paintJobs(main, gen);
    else if (route.name === 'audit') await paintAudit(main, gen);
    else main.append(el('p', {}, 'This screen comes later.'));
  } catch (error) {
    if (gen !== generation) return;
    const title = main.querySelector('#screen-title');
    main.replaceChildren();
    if (title) main.append(title);
    main.append(route.name === 'users' && error instanceof OpsError && error.status === 403 && error.code === 'primary'
      ? el('p', { role: 'alert' }, "Users of the primary installation aren't shown here.")
      : problem(error));
  } finally {
    window.clearTimeout(timer);
    if (gen === generation) {
      main.removeAttribute('aria-busy');
      main.querySelector(':scope > .status')?.remove();
    }
  }
}

function paintNav(route: Route): void {
  const nav = document.querySelector('#nav');
  if (!nav) return;
  nav.replaceChildren();
  for (const item of NAV) {
    const li = el('li');
    const link = el('a', { href: item.path }, item.label);
    if (item.name === route.name) link.setAttribute('aria-current', 'page');
    li.append(link);
    nav.append(li);
  }
}

function subnav(id: string, current: 'installation' | 'users'): HTMLElement {
  const nav = el('nav', { class: 'subnav', 'aria-label': 'Installation' });
  const overview = el('a', { href: `/installations/${encodeURIComponent(id)}` }, 'Overview');
  const users = el('a', { href: `/installations/${encodeURIComponent(id)}/users` }, 'Users');
  if (current === 'installation') overview.setAttribute('aria-current', 'page');
  else users.setAttribute('aria-current', 'page');
  nav.append(overview, users);
  return nav;
}

function crumb(current = ''): HTMLElement {
  const nav = el('nav', { class: 'crumb', 'aria-label': 'Breadcrumb' });
  const list = document.createElement('ol');
  const back = document.createElement('li');
  back.append(el('a', { href: '/installations' }, 'Installations'));
  list.append(back);
  if (current) {
    const here = document.createElement('li');
    here.append(el('span', { 'aria-hidden': 'true', class: 'crumb-sep' }, '/'));
    here.append(el('span', { 'aria-current': 'page' }, current));
    list.append(here);
  }
  nav.append(list);
  return nav;
}

function when(value: string | null, empty = ''): Node {
  const stamp = statsStamp(value);
  if (!stamp) return document.createTextNode(empty);
  return el('time', { datetime: stamp.datetime }, stamp.text);
}

function code(value: string): HTMLElement {
  return el('span', { class: 'code' }, value);
}

async function paintOverview(main: HTMLElement, gen: number): Promise<void> {
  const overview = await client.overview();
  if (gen !== generation) return;
  const stamp = statsStamp(overview.as_of);
  if (!stamp) main.append(el('p', { class: 'muted' }, 'Stats not computed yet'));
  else {
    const line = el('p', { class: 'muted' });
    line.append('Figures as of ');
    line.append(el('time', { datetime: stamp.datetime }, stamp.text));
    line.append('.');
    main.append(line);
  }
  main.append(revenueTile(overview));
  main.append(statGroup('Installations by status', overview.installations_by_status.map((item) => [label(item.status), String(item.count)])));
  main.append(statGroup('Installations by plan', overview.installations_by_plan.map((item) => [item.plan_name, String(item.count)])));
  main.append(statGroup('Subscriptions', overview.subscriptions_by_status.map((item) => [label(item.status), String(item.count)])));
  main.append(statGroup('Open jobs', overview.open_jobs_by_state.map((item) => [label(item.state), String(item.count)])));
  main.append(statGroup(`Usage for ${overview.usage_period}`, overview.usage_totals.map((item) => [item.metric, String(item.value)])));
}

function revenueTile(overview: Overview): HTMLElement {
  const section = el('section', { class: 'stat-group' });
  section.append(el('h2', {}, 'Revenue'));
  const grid = el('dl', { class: 'stats' });
  const item = el('div');
  item.append(el('dt', { id: 'revenue-note' }, 'Not set'));
  const amount = el('dd', { id: 'revenue-amount', class: 'num' });
  amount.setAttribute('aria-describedby', 'revenue-note');
  amount.textContent = overview.revenue === null ? '' : String(overview.revenue);
  item.append(amount);
  grid.append(item);
  section.append(grid);
  return section;
}

async function paintInstallations(main: HTMLElement, gen: number): Promise<void> {
  const query = readParams() as InstallationQuery;
  const key = location.pathname + location.search;
  if (pageKey !== key) {
    const first = await client.installations(query);
    if (gen !== generation) return;
    pageKey = key;
    pageRows = first.installations;
    pageCursor = first.next_cursor;
  }
  const plans = await client.plans();
  if (gen !== generation) return;
  main.append(choiceNav('Status', 'status', [{ id: '', name: 'Any status' }, ...STATUSES.map((status) => ({ id: status, name: label(status) }))], query, '/installations'));
  main.append(choiceNav('Plan', 'plan', [{ id: '', name: 'Any plan' }, ...plans.plans.map((plan) => ({ id: plan.id, name: plan.name }))], query, '/installations'));
  main.append(searchForm('host-search', '/installations', [{ name: 'q', label: 'Host', value: query.q ?? '', type: 'search' }]));
  main.append(installationTable(pageRows as InstallationSummary[]));
  moreButton();
}

function installationTable(rows: InstallationSummary[]): HTMLElement {
  const body = rows.map((row) => {
    const host = document.createElement('span');
    if (row.primary) host.append(primaryPill());
    for (const route of row.routes) host.append(el('a', { href: `/installations/${encodeURIComponent(row.installation.id)}` }, route.host));
    if (row.routes.length === 0) host.append('No host');
    return [host, code(row.installation.id), statusPill(row.installation.status), row.plan?.name ?? ''];
  });
  return dataTable('cap-installations', 'Installations', [
    { text: 'Host' },
    { text: 'Installation', code: true },
    { text: 'Status' },
    { text: 'Plan' },
  ], body, 'No installation matches.');
}

async function paintInstallation(main: HTMLElement, id: string, gen: number): Promise<void> {
  const detail = await client.installation(id);
  if (gen !== generation) return;
  const fields = detail.installation;
  const title = fields.do_name || fields.id;
  const heading = main.querySelector('#screen-title');
  if (heading) heading.textContent = title;
  document.title = `${title}, Apotheke operator console`;
  main.append(crumb('Installation'), subnav(id, 'installation'));
  if (detail.primary) main.append(primaryPill());
  const section = el('section');
  section.append(el('h2', {}, 'Facts'));
  section.append(facts([
    ['Id', code(fields.id)],
    ['Name', fields.do_name],
    ['Prefix', code(fields.r2_prefix)],
    ['Status', statusPill(fields.status)],
    ['Owner', fields.owner_contact ?? ''],
    ['Notes', fields.notes ?? ''],
    ['Created', when(fields.created_at)],
    ['Updated', when(fields.updated_at)],
  ]));
  const live = writesEnabled && !detail.primary;
  const refresh = (): void => { refreshPage(); };
  if (writesEnabled && detail.primary) section.append(primaryNote());
  else section.append(actions(
    live ? claimButton(detail, client, refresh) : writeControl('Claim link'),
    live ? resetButton(detail, client, refresh) : writeControl('Reset admin'),
  ));
  main.append(section);
  main.append(dataTable('cap-routes', 'Routes', [
    { text: 'Host', code: true },
    { text: 'Created' },
  ], detail.routes.map((route) => [code(route.host), when(route.created_at)]), 'No route.'));
  const subscription = detail.subscription;
  const sub = el('section');
  sub.append(el('h2', {}, 'Subscription'));
  if (subscription) {
    sub.append(facts([
      ['Plan', detail.plan?.name ?? subscription.plan_id],
      ['Status', label(subscription.status)],
      ['Trial end', when(subscription.trial_end)],
      ['Period end', when(subscription.period_end)],
    ]));
  } else sub.append(el('p', {}, 'None'));
  if (live) sub.append(subscriptionForm(detail, client, refresh));
  else if (!writesEnabled) sub.append(writeControl('Change plan or status'));
  main.append(sub);
  const limits = el('section');
  limits.append(el('h2', { id: 'limits-heading' }, 'Plan limits'));
  limits.append(dataTable('cap-limits', 'Plan limits', [
    { text: 'Key' },
    { text: 'Soft', num: true },
    { text: 'Hard', num: true },
  ], detail.limits.map((limit) => [limit.limit_key, text(limit.soft_value), text(limit.hard_value)]), 'No limits on this plan.', 'limits-heading'));
  if (live) limits.append(overrideForm(detail, client, refresh));
  else if (!writesEnabled) limits.append(writeControl('Grant limit override'));
  main.append(limits);
  const usage = el('section');
  usage.append(el('h2', { id: 'usage-heading' }, 'Usage'));
  usage.append(dataTable('cap-usage', 'Usage', [
    { text: 'Period' },
    { text: 'Metric' },
    { text: 'Value', num: true },
  ], detail.usage.map((row) => [row.period, row.metric, String(row.value)]), 'No usage recorded.', 'usage-heading'));
  main.append(usage);
  const jobs = el('section');
  jobs.append(el('h2', { id: 'recent-jobs-heading' }, 'Recent jobs'));
  jobs.append(jobTable('cap-recent-jobs', 'Recent jobs', detail.jobs, true, 'recent-jobs-heading'));
  if (live) jobs.append(jobForm(id, provisioningEnabled, client, refresh));
  else if (!writesEnabled) jobs.append(writeControl('Create job'));
  main.append(jobs);
  watchOpenJobs(detail.jobs.some((job) => job.state === 'queued' || job.state === 'running'), refresh);
  main.append(dataTable('cap-recent-audit', 'Recent audit', [
    { text: 'When' },
    { text: 'Operator' },
    { text: 'Action' },
  ], detail.audit.map((row) => [when(row.at), row.operator, row.action]), 'No audit entry matches.'));
}

async function paintUsers(main: HTMLElement, id: string, gen: number): Promise<void> {
  const list = await client.users(id);
  if (gen !== generation) return;
  main.append(crumb('Users'), subnav(id, 'users'));
  main.append(el('p', {}, 'Accounts on this installation.'));
  if (writesEnabled && id !== 'primary') main.append(usersLaterNote());
  main.append(userTable(list.users, id !== 'primary'));
}

function userTable(users: InstallationUser[], controls: boolean): HTMLElement {
  const rows = users.map((user) => {
    const action = controls ? userAction(user.status === 'active' ? 'Disable' : 'Re-enable') : document.createTextNode('');
    return [
      user.username,
      roles(user.roles),
      statusPill(user.status),
      user.status === 'active' ? 'Yes' : 'No',
      when(user.created_at, ''),
      when(user.last_login_at, 'never'),
      action,
    ];
  });
  return dataTable('cap-users', 'Accounts', [
    { text: 'Username' },
    { text: 'Roles' },
    { text: 'Status' },
    { text: 'Active' },
    { text: 'Created' },
    { text: 'Last login' },
    { text: 'Action' },
  ], rows, 'No account on this installation.');
}

function roles(names: string[]): Node {
  if (names.length === 0) return document.createTextNode('');
  const list = el('ul');
  for (const role of names) list.append(el('li', {}, role));
  return list;
}

async function paintPlans(main: HTMLElement, gen: number): Promise<void> {
  const list = await client.plans();
  if (gen !== generation) return;
  for (const plan of list.plans) {
    const section = el('section');
    const headingId = `plan-${plan.id}-heading`;
    section.append(el('h2', { id: headingId }, plan.name));
    const rows = plan.limits.map((limit) => [limit.limit_key, text(limit.soft_value), text(limit.hard_value)]);
    section.append(dataTable(`cap-plan-${plan.id}`, plan.name, [
      { text: 'Limit' },
      { text: 'Soft', num: true },
      { text: 'Hard', num: true },
    ], rows, 'No limits on this plan.', headingId));
    section.append(writesEnabled ? limitsButton(plan, client, refreshPage) : writeControl('Edit limits'));
    main.append(section);
  }
}

async function paintSubscriptions(main: HTMLElement, gen: number): Promise<void> {
  const due = new URLSearchParams(location.search).get('due') ?? '14d';
  const list = await client.subscriptions(due);
  if (gen !== generation) return;
  const dueLine = el('p');
  dueLine.append(`Due within ${list.due_days} days of `);
  dueLine.append(when(list.now));
  dueLine.append('.');
  main.append(dueLine);
  if (list.truncated) main.append(el('p', {}, 'More subscriptions match than this list shows.'));
  main.append(dataTable('cap-due', 'Subscriptions', [
    { text: 'Host', code: true },
    { text: 'Plan' },
    { text: 'Status' },
    { text: 'Due' },
    { text: 'Overdue' },
  ], list.subscriptions.map((row) => [
    row.hosts.join(', '),
    row.plan_name ?? row.plan_id,
    label(row.status),
    when(row.due_at),
    row.overdue ? 'Yes' : 'No',
  ]), `No subscription is due in ${list.due_days} days.`));
}

async function paintJobs(main: HTMLElement, gen: number): Promise<void> {
  const query = readParams() as JobQuery;
  const key = location.pathname + location.search;
  if (pageKey !== key) {
    const first = await client.jobs(query);
    if (gen !== generation) return;
    pageKey = key;
    pageRows = first.jobs;
    pageCursor = first.next_cursor;
  }
  main.append(choiceNav('Kind', 'kind', [{ id: '', name: 'Any kind' }, ...JOB_KINDS.map((kind) => ({ id: kind, name: label(kind) }))], query, '/jobs'));
  main.append(choiceNav('State', 'state', [{ id: '', name: 'Any state' }, ...JOB_STATES.map((state) => ({ id: state, name: label(state) }))], query, '/jobs'));
  main.append(searchForm('job-search', '/jobs', [{ name: 'installation', label: 'Installation', value: query.installation ?? '', type: 'search' }]));
  const listed = pageRows as LifecycleJob[];
  main.append(jobTable('cap-jobs', 'Jobs', listed, false));
  moreButton();
  watchOpenJobs(listed.some((job) => job.state === 'queued' || job.state === 'running'), refreshPage);
}

function jobTable(id: string, caption: string, jobs: LifecycleJob[], recent: boolean, labelId?: string): HTMLElement {
  const rows = jobs.map((job) => {
    const cells: (Node | string)[] = [
      recent ? label(job.kind) : code(job.installation_id),
      recent ? statusText(job.state) : label(job.kind),
      recent ? when(job.created_at) : statusText(job.state),
    ];
    if (!recent) cells.push(when(job.created_at));
    cells.push(job.state === 'paused'
      ? (writesEnabled ? confirmButton(job, client, refreshPage) : writeControl('Confirm'))
      : document.createTextNode(''));
    return cells;
  });
  const heads: { text: string; code?: boolean }[] = recent
    ? [{ text: 'Kind' }, { text: 'State' }, { text: 'Created' }, { text: 'Action' }]
    : [{ text: 'Installation', code: true }, { text: 'Kind' }, { text: 'State' }, { text: 'Created' }, { text: 'Action' }];
  return dataTable(id, caption, heads, rows, 'No job matches these filters.', labelId);
}

function userAction(name: string): HTMLButtonElement {
  const button = writeControl(name);
  if (writesEnabled) button.setAttribute('aria-describedby', 'users-later');
  return button;
}

function statusText(state: string): string {
  return label(state);
}

async function paintAudit(main: HTMLElement, gen: number): Promise<void> {
  const query = readParams() as AuditQuery;
  const key = location.pathname + location.search;
  if (pageKey !== key) {
    const first = await client.audit(query);
    if (gen !== generation) return;
    pageKey = key;
    pageRows = first.audit;
    pageCursor = first.next_cursor;
  }
  main.append(searchForm('audit-search', '/audit', [
    { name: 'installation', label: 'Installation', value: query.installation ?? '', type: 'search' },
    { name: 'operator', label: 'Operator', value: query.operator ?? '', type: 'search' },
    { name: 'action', label: 'Action', value: query.action ?? '', type: 'search' },
    { name: 'from', label: 'From', value: query.from ?? '', type: 'date' },
    { name: 'to', label: 'To', value: query.to ?? '', type: 'date' },
  ]));
  main.append(quietControl('a', { id: 'audit-csv', href: client.auditExportUrl(query) }, 'Export CSV'));
  main.append(el('p', {}, 'When more than 10,000 rows match, the download\'s X-Truncated header is 1.'));
  const rows = (pageRows as OperatorAudit[]).map((row) => [when(row.at), row.operator, row.action, row.installation_id ?? '', row.before_json ?? '']);
  main.append(dataTable('cap-audit', 'Audit', [
    { text: 'When' },
    { text: 'Operator' },
    { text: 'Action' },
    { text: 'Installation', code: true },
    { text: 'Detail' },
  ], rows, 'No audit entry matches.'));
  moreButton();
}

async function loadMore(): Promise<void> {
  if (!pageCursor) return;
  const route = parseRoute(location.pathname);
  const query = readParams();
  const gen = ++generation;
  let next: { rows: unknown[]; cursor: string | null } | null = null;
  if (route.name === 'installations') {
    const page = await client.installations({ ...(query as InstallationQuery), cursor: pageCursor });
    next = { rows: page.installations, cursor: page.next_cursor };
  } else if (route.name === 'jobs') {
    const page = await client.jobs({ ...(query as JobQuery), cursor: pageCursor });
    next = { rows: page.jobs, cursor: page.next_cursor };
  } else if (route.name === 'audit') {
    const page = await client.audit({ ...(query as AuditQuery), cursor: pageCursor });
    next = { rows: page.audit, cursor: page.next_cursor };
  }
  if (!next || gen !== generation) return;
  pageRows = pageRows.concat(next.rows);
  pageCursor = next.cursor;
  await render(false);
  document.getElementById('load-more')?.focus();
}

function moreButton(): void {
  const main = document.querySelector('#main');
  if (!(main instanceof HTMLElement) || !pageCursor) return;
  main.append(quietControl('button', { id: 'load-more', type: 'button' }, 'Load more'));
}

function choiceNav(legend: string, key: string, options: { id: string; name: string }[], query: object, path: string): HTMLElement {
  const group = el('nav', { 'aria-label': legend, class: 'filters' });
  group.append(el('p', { class: 'filter-label' }, legend));
  const list = el('ul');
  const current = readParams();
  for (const option of options) {
    const next = { ...current, [key]: option.id };
    delete (next as { cursor?: string }).cursor;
    const li = el('li');
    const link = el('a', { href: href(path, next) }, option.name);
    const selected = (query as Record<string, string | undefined>)[key] ?? '';
    if (selected === option.id) link.setAttribute('aria-current', 'true');
    li.append(link);
    list.append(li);
  }
  group.append(list);
  return group;
}

function searchForm(id: string, action: string, fields: { name: string; label: string; value: string; type: string }[]): HTMLFormElement {
  const form = el('form', { id, method: 'get', action }) as HTMLFormElement;
  for (const field of fields) {
    const inputId = `${id}-${field.name}`;
    const labelNode = el('label', { for: inputId }, field.label);
    const input = el('input', { id: inputId, name: field.name, type: field.type, value: field.value }) as HTMLInputElement;
    input.value = field.value;
    labelNode.append(input);
    form.append(labelNode);
  }
  form.append(el('button', { type: 'submit' }, 'Show'));
  return form;
}

function readParams(): Record<string, string> {
  return Object.fromEntries(new URLSearchParams(location.search));
}

function href(path: string, query: object): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (typeof value === 'string' && value !== '') params.set(key, value);
  }
  const search = params.toString();
  return path + (search ? `?${search}` : '');
}

function paintFailure(error: unknown, moveFocus: boolean): void {
  const main = document.querySelector('#main');
  if (!(main instanceof HTMLElement)) return;
  main.replaceChildren();
  const heading = el('h1', {}, 'Operator console');
  heading.tabIndex = -1;
  heading.id = 'screen-title';
  main.append(heading, problem(error));
  document.title = 'Operator console, Apotheke';
  if (moveFocus) heading.focus({ focusVisible: true } as FocusOptions);
}
