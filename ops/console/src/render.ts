import { createClient, type OpsClient } from './api/client.ts';
import type { InstallationQuery, InstallationSummary, OpsConfig, Overview } from './api/types.ts';

const STATUSES = ['provisioning', 'active', 'suspended', 'deleting', 'deleted'] as const;

const NAV: { name: string; label: string; path: (id: string) => string }[] = [
  { name: 'fleet', label: 'Fleet', path: () => '/' },
  { name: 'installations', label: 'Installations', path: () => '/installations' },
  { name: 'installation', label: 'Installation', path: (id) => `/installations/${encodeURIComponent(id)}` },
  { name: 'users', label: 'Users', path: (id) => `/installations/${encodeURIComponent(id)}/users` },
  { name: 'plans', label: 'Plans and limits', path: () => '/plans' },
  { name: 'subscriptions', label: 'Subscriptions', path: () => '/subscriptions' },
  { name: 'jobs', label: 'Jobs', path: () => '/jobs' },
  { name: 'audit', label: 'Audit log', path: () => '/audit' },
];

type Route =
  | { name: 'fleet' }
  | { name: 'installations' }
  | { name: 'installation'; id: string }
  | { name: 'users'; id: string }
  | { name: 'plans' }
  | { name: 'subscriptions' }
  | { name: 'jobs' }
  | { name: 'audit' }
  | { name: 'missing' };

const TITLES: Record<Route['name'], string> = {
  fleet: 'Fleet',
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

// Kept for later screens. F1a does not draw a provision action.
let provisioningEnabled = false;
let limitsEnabled = false;

export function appFlags(): { provisioningEnabled: boolean; limitsEnabled: boolean } {
  return { provisioningEnabled, limitsEnabled };
}

export function start(next: OpsClient = createClient()): void {
  client = next;
  const nav = document.querySelector('#nav');
  if (!nav) return;
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const link = target.closest('a');
    if (!link) return;
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#')) return;
    const url = new URL(href, location.origin);
    if (url.origin !== location.origin) return;
    event.preventDefault();
    go(url.pathname + url.search);
  });
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.id !== 'host-search') return;
    event.preventDefault();
    const data = new FormData(form);
    const params = new URLSearchParams(location.search);
    const q = String(data.get('q') ?? '').trim();
    if (q) params.set('q', q);
    else params.delete('q');
    const search = params.toString();
    go('/installations' + (search ? `?${search}` : ''));
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

function parseRoute(pathname: string): Route {
  const parts = pathname.split('/').filter(Boolean).map(decodeURIComponent);
  if (parts.length === 0) return { name: 'fleet' };
  if (parts[0] === 'installations' && parts.length === 1) return { name: 'installations' };
  if (parts[0] === 'installations' && parts.length === 2) return { name: 'installation', id: parts[1] };
  if (parts[0] === 'installations' && parts.length === 3 && parts[2] === 'users') return { name: 'users', id: parts[1] };
  if (parts.length === 1 && ['plans', 'subscriptions', 'jobs', 'audit'].includes(parts[0])) {
    return { name: parts[0] as 'plans' | 'subscriptions' | 'jobs' | 'audit' };
  }
  return { name: 'missing' };
}

function selectedId(route: Route): string {
  if (route.name === 'installation' || route.name === 'users') return route.id;
  return 'primary';
}

function routeKey(route: Route): string {
  return route.name + ('id' in route ? route.id : '');
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
  if (route.name === 'fleet') await paintFleet(client, main, gen);
  else if (route.name === 'installations') await paintList(client, main, gen);
  else {
    main.append(el('p', {}, 'This screen comes later.'));
  }
}

function paintNav(route: Route): void {
  const nav = document.querySelector('#nav');
  if (!nav) return;
  const id = selectedId(route);
  nav.replaceChildren();
  for (const item of NAV) {
    const li = el('li');
    const href = item.path(id);
    const link = el('a', { href }, item.label);
    if (item.name === route.name) link.setAttribute('aria-current', 'page');
    li.append(link);
    nav.append(li);
  }
}

async function paintFleet(client: OpsClient, main: HTMLElement, gen: number): Promise<void> {
  const note = el('p', {}, 'Loading the fleet.');
  main.append(note);
  try {
    const overview = await client.overview();
    if (gen !== generation) return;
    note.remove();
    main.append(el('p', {}, 'Every installation, counted by status and plan.'));
    main.append(revenueTable(overview));
    if (overview.revenue === null) main.append(el('p', { id: 'revenue-note' }, 'No prices set yet.'));
    main.append(el('p', {}, `Figures as of ${overview.as_of}.`));
    main.append(countTable('Installations by status', ['Status', 'Installations'], overview.installations_by_status.map((item) => [labelStatus(item.status), String(item.count)])));
    main.append(countTable('Installations by plan', ['Plan', 'Installations'], overview.installations_by_plan.map((item) => [item.plan_name, String(item.count)])));
    main.append(countTable('Usage', ['Metric', 'Total'], overview.usage_totals.map((item) => [item.metric, String(item.value)])));
  } catch (error) {
    if (gen !== generation) return;
    note.remove();
    main.append(problem(error));
  }
}

async function paintList(client: OpsClient, main: HTMLElement, gen: number): Promise<void> {
  const query = readQuery();
  const note = el('p', {}, 'Loading installations.');
  main.append(note);
  try {
    const [overview, list] = await Promise.all([client.overview(), client.installations(query)]);
    if (gen !== generation) return;
    note.remove();
    main.append(filters(overview, query));
    main.append(installationTable(list.installations));
  } catch (error) {
    if (gen !== generation) return;
    note.remove();
    main.append(problem(error));
  }
}

function readQuery(): InstallationQuery {
  const params = new URLSearchParams(location.search);
  return {
    status: params.get('status') ?? '',
    plan: params.get('plan') ?? '',
    q: params.get('q') ?? '',
  };
}

function filters(overview: Overview, query: InstallationQuery): HTMLElement {
  const wrap = el('div', { class: 'filters' });
  wrap.append(filterNav('Status', 'status', [{ id: '', name: 'Any status' }, ...STATUSES.map((status) => ({ id: status, name: labelStatus(status) }))], query));
  wrap.append(filterNav('Plan', 'plan', [{ id: '', name: 'Any plan' }, ...overview.installations_by_plan.map((plan) => ({ id: plan.plan_id, name: plan.plan_name }))], query));
  const form = el('form', { id: 'host-search', method: 'get', action: '/installations' }) as HTMLFormElement;
  const label = el('label', { for: 'host-q' }, 'Host');
  const input = el('input', { id: 'host-q', name: 'q', type: 'search', value: query.q ?? '' }) as HTMLInputElement;
  input.value = query.q ?? '';
  const button = el('button', { type: 'submit' }, 'Show');
  form.append(label, input, button);
  wrap.append(form);
  return wrap;
}

function filterNav(legend: string, key: 'status' | 'plan', options: { id: string; name: string }[], query: InstallationQuery): HTMLElement {
  const group = el('nav', { 'aria-label': legend });
  const title = el('p', { class: 'filter-label' }, legend);
  const list = el('ul');
  for (const option of options) {
    const next: InstallationQuery = { status: query.status, plan: query.plan, q: query.q };
    next[key] = option.id;
    const li = el('li');
    const link = el('a', { href: listHref(next) }, option.name);
    const current = (query[key] ?? '') === option.id;
    if (current) link.setAttribute('aria-current', 'true');
    li.append(link);
    list.append(li);
  }
  group.append(title, list);
  return group;
}

function listHref(query: InstallationQuery): string {
  const params = new URLSearchParams();
  if (query.status) params.set('status', query.status);
  if (query.plan) params.set('plan', query.plan);
  if (query.q) params.set('q', query.q);
  const search = params.toString();
  return '/installations' + (search ? `?${search}` : '');
}

function installationTable(rows: InstallationSummary[]): HTMLTableElement {
  const table = document.createElement('table');
  const caption = el('caption', {}, rows.length === 0 ? 'No installation matches.' : 'Installations');
  const head = document.createElement('thead');
  const headRow = document.createElement('tr');
  for (const name of ['Host', 'Installation', 'Status', 'Plan']) {
    const cell = el('th', { scope: 'col' }, name);
    headRow.append(cell);
  }
  head.append(headRow);
  const body = document.createElement('tbody');
  for (const row of rows) {
    const tr = document.createElement('tr');
    const hostCell = document.createElement('td');
    const hosts = row.routes.map((route) => route.host);
    if (hosts.length === 0) hostCell.textContent = 'No host';
    for (const host of hosts) {
      const link = el('a', { href: `/installations/${encodeURIComponent(row.installation.id)}` }, host);
      hostCell.append(link);
    }
    tr.append(
      hostCell,
      el('td', {}, row.installation.id),
      el('td', {}, labelStatus(row.installation.status)),
      el('td', {}, row.plan.name),
    );
    body.append(tr);
  }
  table.append(caption, head, body);
  return table;
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
      if (index === 1) cell.className = 'num';
      tr.append(cell);
    });
    body.append(tr);
  }
  table.append(head, body);
  return table;
}

function revenueTable(overview: Overview): HTMLTableElement {
  const table = document.createElement('table');
  table.append(el('caption', {}, 'Revenue'));
  const body = document.createElement('tbody');
  const tr = document.createElement('tr');
  tr.append(el('th', { scope: 'row' }, 'Amount'));
  const amount = el('td', { id: 'revenue-amount' });
  if (overview.revenue === null) amount.setAttribute('aria-describedby', 'revenue-note');
  amount.textContent = overview.revenue === null ? '' : String(overview.revenue);
  tr.append(amount);
  body.append(tr);
  table.append(body);
  return table;
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

function problem(error: unknown): HTMLElement {
  const message = error instanceof Error ? error.message : 'The console could not reach the API.';
  return el('p', { role: 'alert' }, message);
}

function labelStatus(status: string): string {
  if (status.length === 0) return status;
  return status.charAt(0).toUpperCase() + status.slice(1);
}

function el(tag: string, attrs: Record<string, string> = {}, text?: string): HTMLElement {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'class') node.className = value;
    else node.setAttribute(key, value);
  }
  if (text !== undefined) node.textContent = text;
  return node;
}
