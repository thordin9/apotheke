import { OpsError, createClient, type OpsClient } from './api/client.ts';
import { statsStamp } from './format.ts';
import type {
  AuditQuery,
  InstallationQuery,
  InstallationSummary,
  InstallationUser,
  JobQuery,
  OpsConfig,
  Overview,
} from './api/types.ts';

const STATUSES = ['provisioning', 'active', 'suspended', 'deleting', 'deleted'] as const;
const JOB_KINDS = ['provision', 'suspend', 'resume', 'export', 'delete', 'purge'] as const;
const JOB_STATES = ['paused', 'queued', 'running', 'done', 'failed'] as const;

const NAV: { name: string; label: string; path: (id: string) => string }[] = [
  { name: 'overview', label: 'Overview', path: () => '/overview' },
  { name: 'installations', label: 'Installations', path: () => '/installations' },
  { name: 'installation', label: 'Installation', path: (id) => `/installations/${encodeURIComponent(id)}` },
  { name: 'users', label: 'Users', path: (id) => `/installations/${encodeURIComponent(id)}/users` },
  { name: 'plans', label: 'Plans and limits', path: () => '/plans' },
  { name: 'subscriptions', label: 'Subscriptions', path: () => '/subscriptions' },
  { name: 'jobs', label: 'Jobs', path: () => '/jobs' },
  { name: 'audit', label: 'Audit log', path: () => '/audit' },
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

let generation = 0;
let lastKey = '';
let client: OpsClient = createClient();
let ready = false;
let bootError: unknown = null;
let provisioningEnabled = false;
let limitsEnabled = false;
let pageKey = '';
let pageRows: unknown[] = [];
let pageCursor: string | null = null;

export function appFlags(): { provisioningEnabled: boolean; limitsEnabled: boolean } {
  return { provisioningEnabled, limitsEnabled };
}

export function start(next: OpsClient = createClient()): void {
  client = next;
  if (!document.querySelector('#nav')) return;
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target.closest('#load-more')) {
      event.preventDefault();
      void loadMore();
      return;
    }
    const link = target.closest('a');
    if (!link) return;
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#') || link.hasAttribute('download') || link.id === 'audit-csv') return;
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
      const text = field.value.trim();
      if (text) params.set(field.name, text);
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
  document.body.dataset.provisioningEnabled = String(provisioningEnabled);
  document.body.dataset.limitsEnabled = String(limitsEnabled);
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

function selectedId(route: Route): string {
  if (route.name === 'installation' || route.name === 'users') return route.id;
  return 'primary';
}

async function render(changed: boolean): Promise<void> {
  const gen = ++generation;
  const route = parseRoute(location.pathname);
  const key = routeKey(route);
  const moveFocus = changed || (lastKey !== '' && lastKey !== key);
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
  try {
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
    main.append(route.name === 'users' ? usersProblem(error) : problem(error));
  }
}

function paintNav(route: Route): void {
  const nav = document.querySelector('#nav');
  if (!nav) return;
  const id = selectedId(route);
  nav.replaceChildren();
  for (const item of NAV) {
    const li = el('li');
    const link = el('a', { href: item.path(id) }, item.label);
    if (item.name === route.name) link.setAttribute('aria-current', 'page');
    li.append(link);
    nav.append(li);
  }
}

async function paintOverview(main: HTMLElement, gen: number): Promise<void> {
  const overview = await client.overview();
  if (gen !== generation) return;
  main.append(el('p', {}, 'Every installation, counted by status and plan.'));
  main.append(revenueTable(overview));
  main.append(el('p', { id: 'revenue-note' }, 'Not set'));
  const stamp = statsStamp(overview.as_of);
  if (!stamp) main.append(el('p', {}, 'Stats not computed yet'));
  else {
    const line = el('p');
    line.append('Figures as of ');
    line.append(el('time', { datetime: stamp.datetime }, stamp.text));
    line.append('.');
    main.append(line);
  }
  main.append(countTable('Installations by status', ['Status', 'Installations'], overview.installations_by_status.map((item) => [label(item.status), String(item.count)])));
  main.append(countTable('Installations by plan', ['Plan', 'Installations'], overview.installations_by_plan.map((item) => [item.plan_name, String(item.count)])));
  main.append(countTable('Subscriptions by status', ['Status', 'Subscriptions'], overview.subscriptions_by_status.map((item) => [label(item.status), String(item.count)])));
  main.append(countTable('Open jobs', ['State', 'Jobs'], overview.open_jobs_by_state.map((item) => [label(item.state), String(item.count)])));
  main.append(countTable(`Usage for ${overview.usage_period}`, ['Metric', 'Total'], overview.usage_totals.map((item) => [item.metric, String(item.value)])));
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
  main.append(searchForm('host-search', '/installations', [{ name: 'q', label: 'Host', value: query.q ?? '' }]));
  main.append(installationTable(pageRows as InstallationSummary[]));
  moreButton();
}

async function paintInstallation(main: HTMLElement, id: string, gen: number): Promise<void> {
  const detail = await client.installation(id);
  if (gen !== generation) return;
  if (detail.primary) main.append(el('p', { class: 'badge' }, 'primary'));
  const fields = detail.installation;
  main.append(countTable('Installation', ['Field', 'Value'], [
    ['Id', fields.id],
    ['Name', fields.do_name],
    ['Prefix', fields.r2_prefix],
    ['Status', label(fields.status)],
    ['Owner', fields.owner_contact ?? ''],
    ['Notes', fields.notes ?? ''],
    ['Created', fields.created_at],
    ['Updated', fields.updated_at],
  ]));
  main.append(countTable('Routes', ['Host', 'Created'], detail.routes.map((route) => [route.host, route.created_at])));
  const subscription = detail.subscription;
  main.append(countTable('Subscription', ['Field', 'Value'], subscription ? [
    ['Plan', detail.plan?.name ?? subscription.plan_id],
    ['Status', label(subscription.status)],
    ['Trial end', subscription.trial_end ?? ''],
    ['Period end', subscription.period_end ?? ''],
  ] : [['Subscription', 'None']]));
  main.append(countTable('Plan limits', ['Key', 'Soft', 'Hard'], detail.limits.map((limit) => [limit.limit_key, text(limit.soft_value), text(limit.hard_value)])));
  main.append(countTable('Usage', ['Period', 'Metric', 'Value'], detail.usage.map((row) => [row.period, row.metric, String(row.value)])));
  main.append(countTable('Recent jobs', ['Kind', 'State', 'Created'], detail.jobs.map((job) => [job.kind, job.state, job.created_at])));
  main.append(countTable('Recent audit', ['When', 'Operator', 'Action'], detail.audit.map((row) => [row.at, row.operator, row.action])));
}

async function paintUsers(main: HTMLElement, id: string, gen: number): Promise<void> {
  const list = await client.users(id);
  if (gen !== generation) return;
  main.append(el('p', {}, 'Accounts on this installation.'));
  main.append(userTable(list.users));
}

function userTable(users: InstallationUser[]): HTMLTableElement {
  const table = document.createElement('table');
  table.append(el('caption', {}, users.length === 0 ? 'No account on this installation.' : 'Accounts'));
  const head = document.createElement('thead');
  const headRow = document.createElement('tr');
  for (const name of ['Username', 'Roles', 'Status', 'Active', 'Created', 'Last login']) {
    headRow.append(el('th', { scope: 'col' }, name));
  }
  head.append(headRow);
  const body = document.createElement('tbody');
  for (const user of users) {
    const tr = document.createElement('tr');
    tr.append(el('td', {}, user.username));
    tr.append(rolesCell(user.roles));
    tr.append(el('td', {}, user.status));
    tr.append(el('td', {}, user.status === 'active' ? 'Yes' : 'No'));
    tr.append(whenCell(user.created_at, ''));
    tr.append(whenCell(user.last_login_at, 'never'));
    body.append(tr);
  }
  table.append(head, body);
  return table;
}

function rolesCell(roles: string[]): HTMLTableCellElement {
  const cell = document.createElement('td');
  if (roles.length === 0) return cell;
  const list = el('ul');
  for (const role of roles) list.append(el('li', {}, role));
  cell.append(list);
  return cell;
}

function whenCell(value: string | null, empty: string): HTMLTableCellElement {
  const cell = document.createElement('td');
  const stamp = statsStamp(value);
  if (!stamp) {
    cell.textContent = empty;
    return cell;
  }
  cell.append(el('time', { datetime: stamp.datetime }, stamp.text));
  return cell;
}

async function paintPlans(main: HTMLElement, gen: number): Promise<void> {
  const list = await client.plans();
  if (gen !== generation) return;
  const rows = list.plans.flatMap((plan) => plan.limits.length
    ? plan.limits.map((limit) => [plan.name, limit.limit_key, text(limit.soft_value), text(limit.hard_value)])
    : [[plan.name, '', '', '']]);
  main.append(countTable('Plans', ['Plan', 'Limit', 'Soft', 'Hard'], rows));
}

async function paintSubscriptions(main: HTMLElement, gen: number): Promise<void> {
  const due = new URLSearchParams(location.search).get('due') ?? '14d';
  const list = await client.subscriptions(due);
  if (gen !== generation) return;
  main.append(el('p', {}, `Due within ${list.due_days} days of ${list.now}.`));
  if (list.truncated) main.append(el('p', {}, 'More subscriptions match than this list shows.'));
  main.append(countTable('Subscriptions', ['Host', 'Plan', 'Status', 'Due', 'Overdue'], list.subscriptions.map((row) => [
    row.hosts.join(', '),
    row.plan_name ?? row.plan_id,
    label(row.status),
    row.due_at,
    row.overdue ? 'Yes' : 'No',
  ])));
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
  main.append(searchForm('job-search', '/jobs', [{ name: 'installation', label: 'Installation', value: query.installation ?? '' }]));
  main.append(countTable('Jobs', ['Installation', 'Kind', 'State', 'Created'], (pageRows as JobListRow[]).map((job) => [job.installation_id, job.kind, job.state, job.created_at])));
  moreButton();
}

type JobListRow = { installation_id: string; kind: string; state: string; created_at: string };

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
    { name: 'installation', label: 'Installation', value: query.installation ?? '' },
    { name: 'operator', label: 'Operator', value: query.operator ?? '' },
    { name: 'action', label: 'Action', value: query.action ?? '' },
    { name: 'from', label: 'From', value: query.from ?? '' },
    { name: 'to', label: 'To', value: query.to ?? '' },
  ]));
  const exportLink = el('a', { id: 'audit-csv', href: client.auditExportUrl(query) }, 'Export CSV');
  main.append(exportLink);
  main.append(el('p', {}, 'When more than 10,000 rows match, the download\'s X-Truncated header is 1.'));
  main.append(countTable('Audit', ['When', 'Operator', 'Action', 'Installation', 'Detail'], (pageRows as { at: string; operator: string; action: string; installation_id: string | null; before_json: string | null }[]).map((row) => [row.at, row.operator, row.action, row.installation_id ?? '', row.before_json ?? ''])));
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
  main.append(el('button', { id: 'load-more', type: 'button' }, 'Load more'));
}

function revenueTable(overview: Overview): HTMLTableElement {
  const table = document.createElement('table');
  table.append(el('caption', {}, 'Revenue'));
  const body = document.createElement('tbody');
  const tr = document.createElement('tr');
  tr.append(el('th', { scope: 'row' }, 'Amount'));
  const amount = el('td', { id: 'revenue-amount' });
  amount.setAttribute('aria-describedby', 'revenue-note');
  amount.textContent = overview.revenue === null ? '' : String(overview.revenue);
  tr.append(amount);
  body.append(tr);
  table.append(body);
  return table;
}

function installationTable(rows: InstallationSummary[]): HTMLTableElement {
  const table = document.createElement('table');
  table.append(el('caption', {}, rows.length === 0 ? 'No installation matches.' : 'Installations'));
  const head = document.createElement('thead');
  const headRow = document.createElement('tr');
  for (const name of ['Host', 'Installation', 'Status', 'Plan']) headRow.append(el('th', { scope: 'col' }, name));
  head.append(headRow);
  const body = document.createElement('tbody');
  for (const row of rows) {
    const tr = document.createElement('tr');
    const hostCell = document.createElement('td');
    if (row.primary) hostCell.append(el('span', { class: 'badge' }, 'primary'));
    for (const route of row.routes) hostCell.append(el('a', { href: `/installations/${encodeURIComponent(row.installation.id)}` }, route.host));
    if (row.routes.length === 0) hostCell.append('No host');
    tr.append(hostCell, el('td', {}, row.installation.id), el('td', {}, label(row.installation.status)), el('td', {}, row.plan?.name ?? ''));
    body.append(tr);
  }
  table.append(head, body);
  return table;
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

function searchForm(id: string, action: string, fields: { name: string; label: string; value: string }[]): HTMLFormElement {
  const form = el('form', { id, method: 'get', action }) as HTMLFormElement;
  for (const field of fields) {
    const inputId = `${id}-${field.name}`;
    form.append(el('label', { for: inputId }, field.label));
    const input = el('input', { id: inputId, name: field.name, type: 'search', value: field.value }) as HTMLInputElement;
    input.value = field.value;
    form.append(input);
  }
  form.append(el('button', { type: 'submit' }, 'Show'));
  return form;
}

function countTable(caption: string, heads: string[], rows: string[][]): HTMLTableElement {
  const table = document.createElement('table');
  table.append(el('caption', {}, caption));
  const head = document.createElement('thead');
  const headRow = document.createElement('tr');
  for (const name of heads) headRow.append(el('th', { scope: 'col' }, name));
  head.append(headRow);
  const body = document.createElement('tbody');
  for (const row of rows) {
    const tr = document.createElement('tr');
    row.forEach((value, index) => {
      const cell = el('td', {}, value);
      if (index > 0 && /^\d+$/.test(value)) cell.className = 'num';
      tr.append(cell);
    });
    body.append(tr);
  }
  table.append(head, body);
  return table;
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

function usersProblem(error: unknown): HTMLElement {
  if (error instanceof OpsError && error.status === 403 && error.code === 'primary') {
    return el('p', { role: 'alert' }, "Users of the primary installation aren't shown here.");
  }
  return problem(error);
}

function problem(error: unknown): HTMLElement {
  const message = error instanceof Error ? error.message : 'The console could not reach the API.';
  return el('p', { role: 'alert' }, message);
}

function label(value: string): string {
  if (!value) return value;
  return value.charAt(0).toUpperCase() + value.slice(1).replaceAll('_', ' ');
}

function text(value: number | null): string {
  return value === null ? '' : String(value);
}

function el(tag: string, attrs: Record<string, string> = {}, textContent?: string): HTMLElement {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'class') node.className = value;
    else node.setAttribute(key, value);
  }
  if (textContent !== undefined) node.textContent = textContent;
  return node;
}
