import { OpsError } from '../api/client.ts';

export function el(tag: string, attrs: Record<string, string> = {}, textContent?: string): HTMLElement {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'class') node.className = value;
    else node.setAttribute(key, value);
  }
  if (textContent !== undefined) node.textContent = textContent;
  return node;
}

export function label(value: string): string {
  if (!value) return value;
  return value.charAt(0).toUpperCase() + value.slice(1).replaceAll('_', ' ');
}

export function text(value: number | null): string {
  return value === null ? '' : String(value);
}

export function writesNote(enabled: boolean): HTMLElement {
  return el('p', { id: 'writes-off' }, enabled
    ? 'Changes come in a later release.'
    : 'Changes are turned off on this console.');
}

export function writeControl(name: string): HTMLButtonElement {
  const button = el('button', {
    type: 'button',
    class: 'write',
    'aria-disabled': 'true',
    'aria-describedby': 'writes-off',
  }, name);
  return button as HTMLButtonElement;
}

export function quietControl(tag: 'button' | 'a', attrs: Record<string, string>, name: string): HTMLElement {
  return el(tag, { ...attrs, class: attrs.class ? `${attrs.class} quiet` : 'quiet' }, name);
}

const STATUS_CLASS: Record<string, string> = {
  active: 'status-active',
  suspended: 'status-suspended',
  deleting: 'status-deleted',
  deleted: 'status-deleted',
  provisioning: 'status-provisioning',
};

export function statusPill(status: string): HTMLElement {
  const kind = STATUS_CLASS[status];
  return el('span', { class: kind ? `pill ${kind}` : 'pill' }, label(status));
}

export function primaryPill(): HTMLElement {
  return el('span', { class: 'badge' }, 'primary');
}

export interface Head {
  text: string;
  num?: boolean;
  code?: boolean;
}

export function actions(...nodes: Node[]): HTMLElement {
  const row = el('div', { class: 'actions' });
  for (const node of nodes) row.append(node);
  return row;
}

export function dataTable(id: string, caption: string, heads: Head[], rows: (Node | string)[][], empty?: string, labelId?: string): HTMLElement {
  const table = document.createElement('table');
  const showingEmpty = rows.length === 0 && Boolean(empty);
  const title = showingEmpty ? empty! : caption;
  const namedByHeading = Boolean(labelId) && !showingEmpty;
  if (!namedByHeading) table.append(el('caption', { id }, title));
  else table.setAttribute('aria-labelledby', labelId!);
  const head = document.createElement('thead');
  const headRow = document.createElement('tr');
  for (const column of heads) {
    const cell = el('th', { scope: 'col' }, column.text);
    if (column.num) cell.classList.add('num');
    headRow.append(cell);
  }
  head.append(headRow);
  const body = document.createElement('tbody');
  for (const row of rows) {
    const tr = document.createElement('tr');
    row.forEach((value, index) => {
      const cell = document.createElement('td');
      const column = heads[index];
      if (column?.num) cell.classList.add('num');
      if (column?.code) cell.classList.add('code');
      if (typeof value === 'string') cell.textContent = value;
      else cell.append(value);
      tr.append(cell);
    });
    body.append(tr);
  }
  table.append(head, body);
  const region = el('div', { class: 'table-scroll', role: 'region', tabindex: '0' });
  region.setAttribute('aria-labelledby', namedByHeading ? labelId! : id);
  region.append(table);
  return region;
}

export function facts(rows: [string, Node | string][]): HTMLDListElement {
  const list = document.createElement('dl');
  list.className = 'facts';
  for (const [name, value] of rows) {
    list.append(el('dt', {}, name));
    const dd = document.createElement('dd');
    if (typeof value === 'string') dd.textContent = value;
    else dd.append(value);
    list.append(dd);
  }
  return list;
}

export function statGroup(title: string, pairs: [string, string][]): HTMLElement {
  const section = el('section', { class: 'stat-group' });
  section.append(el('h2', {}, title));
  const grid = el('dl', { class: 'stats' });
  for (const [name, value] of pairs) {
    const item = el('div');
    item.append(el('dt', {}, name));
    item.append(el('dd', { class: 'num' }, value));
    grid.append(item);
  }
  section.append(grid);
  return section;
}

export function errorTitle(error: unknown): string | null {
  if (!(error instanceof OpsError)) return 'Could not reach the API';
  if (error.status === 401) return 'Signed out of Access';
  if (error.status === 503 && error.code === 'not_configured') return 'The operator API is not configured';
  if (error.status === 503 || error.kind === 'network') return 'Could not reach the API';
  return null;
}

export function problem(error: unknown): HTMLElement {
  const message = error instanceof Error ? error.message : 'The console could not reach the API.';
  const notice = el('div', { class: 'notice error' });
  const title = errorTitle(error);
  if (title) notice.append(el('p', { class: 'notice-title' }, title));
  notice.append(el('p', { role: 'alert' }, message));
  if (error instanceof OpsError && error.status === 401) {
    notice.append(el('a', { id: 'reload', href: location.pathname + location.search }, 'Reload'));
  } else if (!(error instanceof OpsError) || error.status === 503 || error.kind === 'network') {
    notice.append(el('button', { id: 'try-again', type: 'button', class: 'quiet' }, 'Try again'));
  }
  return notice;
}
