import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { OPS_CSP } from '../src/csp.ts';

const routes: { path: string; heading: string; shot: string }[] = [
  { path: '/overview', heading: 'Overview', shot: 'overview' },
  { path: '/', heading: 'Overview', shot: '' },
  { path: '/installations', heading: 'Installations', shot: 'installations' },
  { path: '/installations/north', heading: 'north', shot: 'installation' },
  { path: '/installations/north/users', heading: 'Users', shot: 'users' },
  { path: '/plans', heading: 'Plans and limits', shot: 'plans' },
  { path: '/subscriptions', heading: 'Subscriptions', shot: 'subscriptions' },
  { path: '/jobs', heading: 'Jobs', shot: 'jobs' },
  { path: '/audit', heading: 'Audit log', shot: 'audit' },
];

async function axe(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(results.violations).toEqual([]);
}

async function tabTo(page: Page, locator: Locator): Promise<void> {
  for (let i = 0; i < 60; i += 1) {
    if (await locator.evaluate((node) => node === document.activeElement).catch(() => false)) return;
    await page.keyboard.press('Tab');
  }
  await expect(locator).toBeFocused();
}

async function expectFocusRing(page: Page): Promise<void> {
  const visible = await page.evaluate(() => {
    const node = document.activeElement;
    if (!node) return false;
    const style = getComputedStyle(node);
    return style.outlineStyle !== 'none' && Number.parseFloat(style.outlineWidth) > 0;
  });
  expect(visible).toBe(true);
}

async function noInlineStyle(page: Page): Promise<void> {
  expect(await page.locator('[style]').count()).toBe(0);
  const html = await page.content();
  expect(html).not.toMatch(/<script(?![^>]*\bsrc=)/i);
}

test.describe('operator console', () => {
  test.beforeEach(async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
  });

  for (const route of routes) {
    test(`axe on ${route.path}`, async ({ page }) => {
      await page.goto(route.path);
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(route.heading);
      await noInlineStyle(page);
      await axe(page);
      if (route.shot) await page.screenshot({ path: `test-results/${route.shot}.png`, fullPage: true });
    });
  }

  test('overview leaves revenue blank and says stats are not computed', async ({ page }) => {
    await page.goto('/overview');
    await expect(page.locator('#revenue-amount')).toBeEmpty();
    await expect(page.locator('#revenue-note')).toHaveText('Not set');
    await expect(page.getByText('Stats not computed yet')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Overview' })).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('body')).toHaveAttribute('data-provisioning-enabled', 'false');
  });

  test('a stamp is a time element', async ({ page }) => {
    await page.route('**/api/ops/v1/overview', async (route) => {
      const response = await route.fetch();
      const body = await response.json() as { as_of: string | null };
      body.as_of = '2026-11-20T00:00:00Z';
      await route.fulfill({ response, json: body });
    });
    await page.goto('/overview');
    const stamp = page.locator('time');
    await expect(stamp).toHaveAttribute('datetime', '2026-11-20T00:00:00Z');
    await expect(stamp).toContainText('2026');
  });

  test('primary is badged and its users are not listed', async ({ page }) => {
    const users: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/api/ops/v1/') && request.url().includes('/users')) users.push(request.url());
    });
    await page.goto('/installations/primary');
    await expect(page.locator('.badge', { hasText: 'primary' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Claim' })).toHaveCount(0);
    await page.goto('/installations/primary/users');
    await expect(page.getByRole('alert')).toHaveText("Users of the primary installation aren't shown here.");
    expect(users.some((url) => url.includes('/installations/primary/users'))).toBe(true);
  });

  test('load more pages the installation list', async ({ page }) => {
    await page.goto('/installations?limit=2');
    await expect(page.getByRole('link', { name: 'north.example.test' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'south.example.test' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Load more' }).click();
    await expect(page.getByRole('link', { name: 'south.example.test' })).toBeVisible();
    await page.getByRole('button', { name: 'Load more' }).click();
    await expect(page.getByRole('link', { name: 'yard.example.test' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Load more' })).toHaveCount(0);
  });

  test('audit export keeps the filters', async ({ page }) => {
    await page.goto('/audit?installation=north');
    await expect(page.locator('#audit-csv')).toHaveAttribute('href', /\/api\/ops\/v1\/audit\.csv\?installation=north/);
    await expect(page.getByText('X-Truncated')).toBeVisible();
  });

  test('keyboard: skip link, nav, filters, search and opening an installation', async ({ page }) => {
    await page.goto('/overview');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
    await expectFocusRing(page);
    await page.keyboard.press('Enter');
    await expect(page.locator('#main')).toBeFocused();

    await page.goto('/overview');
    const installations = page.getByRole('navigation', { name: 'Console' }).getByRole('link', { name: 'Installations' });
    await tabTo(page, installations);
    await expectFocusRing(page);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/installations$/);
    await expect(page.getByRole('heading', { level: 1 })).toBeFocused();
    await expect(installations).toHaveAttribute('aria-current', 'page');

    const suspended = page.getByRole('link', { name: 'Suspended' });
    await tabTo(page, suspended);
    await expectFocusRing(page);
    await page.keyboard.press('Enter');
    await expect(suspended).toHaveAttribute('aria-current', 'true');
    await expect(page.getByRole('link', { name: 'south.example.test' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'north.example.test' })).toHaveCount(0);

    await tabTo(page, page.getByRole('link', { name: 'Any status' }));
    await page.keyboard.press('Enter');
    await tabTo(page, page.getByLabel('Host'));
    await expectFocusRing(page);
    await page.keyboard.type('north.example.test');
    await page.keyboard.press('Enter');
    const north = page.getByRole('link', { name: 'north.example.test' });
    await expect(north).toBeVisible();
    await tabTo(page, north);
    await expectFocusRing(page);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/installations\/north$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('north');
    await expect(page.getByRole('heading', { level: 1 })).toBeFocused();

    const users = page.getByRole('navigation', { name: 'Installation' }).getByRole('link', { name: 'Users' });
    await tabTo(page, users);
    await expectFocusRing(page);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/installations\/north\/users$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Users');
    await expect(page.getByText('ada')).toBeVisible();
    await expect(page.getByText('never')).toBeVisible();
  });

  test('the preview CSP reports no violations', async ({ page }) => {
    await page.addInitScript(() => {
      document.addEventListener('securitypolicyviolation', (event) => {
        const list = (window as unknown as { __csp?: string[] });
        list.__csp = list.__csp ?? [];
        list.__csp.push(`${event.violatedDirective} ${event.blockedURI}`);
      });
    });
    const response = await page.goto('/overview');
    expect(response?.headers()['content-security-policy']).toBe(OPS_CSP);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Overview');
    const reported = await page.evaluate(() => (window as unknown as { __csp?: string[] }).__csp ?? []);
    expect(reported).toEqual([]);
  });
});

test('hostile markup is text and creates no element', async ({ page }) => {
  await page.goto('/installations');
  await expect(page.getByRole('link', { name: '<b>x</b>' })).toBeVisible();
  expect(await page.locator('b').count()).toBe(0);
  await page.goto('/installations/north');
  await expect(page.getByText('<img src=x onerror=alert(1)>')).toBeVisible();
  expect(await page.locator('#main img, #main b').count()).toBe(0);
  await page.goto('/audit');
  await expect(page.getByRole('cell', { name: '<img src=x onerror=alert(1)>' })).toBeVisible();
  await page.goto('/installations/north/users');
  await expect(page.getByRole('cell', { name: '<b>x</b>' })).toBeVisible();
  expect(await page.locator('#main b, #main img').count()).toBe(0);
});

test('the header is paper in light and dark only in the dark scheme', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await page.goto('/overview');
  await expect(page.locator('.top')).toHaveCSS('background-color', 'rgb(253, 252, 250)');
  await expect(page.locator('.mark')).toHaveCSS('color', 'rgb(4, 48, 61)');
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.reload();
  await expect(page.locator('.top')).toHaveCSS('background-color', 'rgb(7, 22, 28)');
  await expect(page.locator('.mark')).toHaveCSS('color', 'rgb(238, 243, 243)');
});

const userErrors: { path: string; message: string }[] = [
  { path: '/installations/missing/users', message: 'That record was not found.' },
  { path: '/installations/primary/users', message: "Users of the primary installation aren't shown here." },
  { path: '/installations/old/users', message: 'This installation has been deleted.' },
  { path: '/installations/south/users', message: "This installation isn't running or has no host, so its users can't be read." },
  { path: '/installations/nohost/users', message: "This installation isn't running or has no host, so its users can't be read." },
  { path: '/installations/unread/users', message: 'The container cannot be reached: read_failed.' },
  { path: '/installations/unconfigured/users', message: 'The operator API is not configured.' },
];

for (const item of userErrors) {
  test(`users ${item.path} is the typed error and passes axe`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    await page.goto(item.path);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Users');
    await expect(page.getByRole('alert')).toHaveText(item.message);
    await axe(page);
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
  });
}

test('users 405 is the typed error and passes axe', async ({ page }) => {
  await page.route('**/api/ops/v1/installations/north/users', async (route) => {
    await route.fulfill({
      status: 405,
      contentType: 'application/json',
      body: JSON.stringify({ ok: false, code: 'method_not_allowed' }),
    });
  });
  await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
  await page.goto('/installations/north/users');
  await expect(page.getByRole('alert')).toHaveText('That method is not allowed.');
  await axe(page);
});

const faults: { code: string; message: string }[] = [
  { code: '401', message: 'Your Access session ended, reload to sign in' },
  { code: '503', message: 'The operator API is not configured.' },
  { code: '410', message: 'This installation has been deleted.' },
];

for (const fault of faults) {
  test(`startup ${fault.code} is the typed error and passes axe`, async ({ context, page }) => {
    await context.addCookies([{ name: 'ops_preview_fault', value: fault.code, url: 'http://127.0.0.1:4181' }]);
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    await page.goto('/');
    await expect(page.getByRole('alert')).toHaveText(fault.message);
    await axe(page);
  });
}

test('writes off keeps every control inert', async ({ page }) => {
  const methods: string[] = [];
  page.on('request', (request) => {
    if (request.method() !== 'GET' && request.method() !== 'HEAD') methods.push(request.method());
  });
  await page.goto('/installations/north');
  await expect(page.locator('#read-only')).toHaveText('Read-only');
  await expect(page.locator('#writes-off')).toHaveText('Changes are turned off on this console.');
  await expect(page.locator('body')).toHaveAttribute('data-writes-enabled', 'false');
  for (const name of ['Change plan or status', 'Grant limit override', 'Create job', 'Claim link', 'Reset admin']) {
    const button = page.getByRole('button', { name, exact: true });
    await expect(button).toHaveAttribute('aria-disabled', 'true');
    await expect(button).toHaveAttribute('aria-describedby', 'writes-off');
    await button.click({ force: true });
    await button.focus();
    await page.keyboard.press('Enter');
    await page.keyboard.press(' ');
  }
  await page.goto('/plans');
  await expect(page.getByRole('button', { name: 'Edit limits' })).toHaveCount(3);
  await page.goto('/jobs');
  const confirm = page.getByRole('button', { name: 'Confirm' }).first();
  await expect(confirm).toHaveAttribute('aria-disabled', 'true');
  await confirm.click({ force: true });
  await page.goto('/installations/north/users');
  await expect(page.getByRole('cell', { name: 'Yes', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'No', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Disable' }).click({ force: true });
  await page.getByRole('button', { name: 'Re-enable' }).click({ force: true });
  expect(methods).toEqual([]);
});

test('the page sends only GET and HEAD, including load more and csv', async ({ page }) => {
  const methods: string[] = [];
  page.on('request', (request) => {
    if (request.method() !== 'GET' && request.method() !== 'HEAD') methods.push(`${request.method()} ${request.url()}`);
  });
  for (const route of routes) {
    await page.goto(route.path);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(route.heading);
  }
  await page.goto('/installations?limit=2');
  await page.getByRole('button', { name: 'Load more' }).click();
  await page.goto('/audit?installation=north');
  const csv = page.locator('#audit-csv');
  await expect(csv).toHaveAttribute('href', /\/api\/ops\/v1\/audit\.csv\?installation=north/);
  await csv.click();
  expect(methods).toEqual([]);
});

test('writes on hides the read-only pill and keeps user actions unavailable', async ({ context, page }) => {
  const methods: string[] = [];
  page.on('request', (request) => {
    if (request.method() !== 'GET' && request.method() !== 'HEAD') methods.push(`${request.method()} ${request.url()}`);
  });
  await context.addCookies([{ name: 'ops_preview_writes', value: '1', url: 'http://127.0.0.1:4181' }]);
  await page.goto('/installations/north');
  await expect(page.locator('#read-only')).toBeHidden();
  await expect(page.locator('#writes-off')).toHaveCount(0);
  await expect(page.locator('body')).toHaveAttribute('data-writes-enabled', 'true');
  await expect(page.getByRole('button', { name: 'Claim link' })).not.toHaveAttribute('aria-disabled', 'true');
  await page.goto('/installations/north/users');
  await expect(page.locator('#users-later')).toHaveText('Not available yet.');
  const disable = page.getByRole('button', { name: 'Disable' });
  await expect(disable).toHaveAttribute('aria-disabled', 'true');
  await expect(disable).toHaveAttribute('aria-describedby', 'users-later');
  await disable.click({ force: true });
  expect(methods).toEqual([]);
});

test('a config without writes_enabled stays read-only', async ({ page }) => {
  await page.route('**/api/ops/v1/config', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, provisioning_enabled: false, limits_enabled: false }),
    });
  });
  await page.goto('/installations/north');
  await expect(page.locator('#read-only')).toBeVisible();
  await expect(page.locator('#read-only')).toHaveText('Read-only');
  await expect(page.locator('#writes-off')).toHaveText('Changes are turned off on this console.');
  await expect(page.locator('body')).toHaveAttribute('data-writes-enabled', 'false');
  await expect(page.getByRole('button', { name: 'Claim link' })).toHaveAttribute('aria-disabled', 'true');
});

const viewports: { width: number; height: number; scheme: 'light' | 'dark' }[] = [
  { width: 1440, height: 900, scheme: 'light' },
  { width: 1440, height: 900, scheme: 'dark' },
  { width: 320, height: 640, scheme: 'light' },
  { width: 768, height: 900, scheme: 'light' },
];

for (const viewport of viewports) {
  test(`axe and fit at ${viewport.width} ${viewport.scheme}`, async ({ page }) => {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.emulateMedia({ colorScheme: viewport.scheme, reducedMotion: 'reduce' });
    for (const route of routes) {
      if (!route.shot) continue;
      await page.goto(route.path);
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(route.heading);
      const fit = await page.evaluate(() => document.documentElement.scrollWidth === window.innerWidth);
      expect(fit).toBe(true);
      const loose = await page.locator('table').evaluateAll((tables) => tables.filter((table) => {
        const region = table.parentElement;
        return region?.classList.contains('table-scroll') !== true || region.getAttribute('tabindex') !== '0';
      }).length);
      expect(loose).toBe(0);
      const iso = await page.locator('#main').innerText();
      expect(iso).not.toMatch(/T\d{2}:\d{2}:\d{2}Z/);
      if (route.path === '/installations/north') {
        await expect(page.getByRole('heading', { name: 'Plan limits' })).toHaveCount(1);
        await expect(page.locator('caption', { hasText: 'Plan limits' })).toHaveCount(0);
        await expect(page.locator('caption', { hasText: 'Usage' })).toHaveCount(0);
        await expect(page.locator('caption', { hasText: 'Recent jobs' })).toHaveCount(0);
        const trail = page.getByRole('navigation', { name: 'Breadcrumb' });
        await expect(trail.getByRole('link', { name: 'Installations' })).toBeVisible();
        await expect(trail.locator('[aria-current="page"]')).toHaveText('Installation');
        await expect(trail.locator('.crumb-sep')).toHaveAttribute('aria-hidden', 'true');
      }
      if (viewport.width === 320 && route.path === '/audit') {
        const nowrap = await page.locator('.table-scroll time').first().evaluate((node) => getComputedStyle(node).whiteSpace);
        expect(nowrap).toBe('nowrap');
      }
      await axe(page);
    }
    if (viewport.width === 320 && viewport.scheme === 'light') {
      await page.goto('/overview');
      const top = await page.locator('.stats').first().evaluate((node) => node.getBoundingClientRect().top);
      expect(top).toBeLessThan(640);
      const header = await page.locator('.bar').evaluate((bar) => {
        const where = bar.querySelector('.where');
        const pill = bar.querySelector('#read-only');
        return {
          height: bar.getBoundingClientRect().height,
          whereHidden: where ? getComputedStyle(where).position === 'absolute' : false,
          pillNowrap: pill ? getComputedStyle(pill).whiteSpace : '',
        };
      });
      expect(header.height).toBeLessThanOrEqual(56);
      expect(header.whereHidden).toBe(true);
      expect(header.pillNowrap).toBe('nowrap');
    }
  });
}

test('empty lists name what is missing', async ({ page }) => {
  await page.goto('/jobs?state=failed');
  await expect(page.getByText('No job matches these filters.')).toBeVisible();
  await page.goto('/audit?action=not-real');
  await expect(page.getByText('No audit entry matches.')).toBeVisible();
  await page.goto('/installations/yard/users');
  await expect(page.getByText('No account on this installation.')).toBeVisible();
  await page.goto('/installations/yard');
  await expect(page.getByText('No usage recorded.')).toBeVisible();
  await expect(page.getByText('No limits on this plan.')).toBeVisible();
  await page.goto('/plans');
  await expect(page.getByText('No limits on this plan.')).toBeVisible();
  await page.route('**/api/ops/v1/installations/north', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.routes = [];
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/installations/north');
  await expect(page.getByText('No route.')).toBeVisible();
  await page.unroute('**/api/ops/v1/installations/north');
  await page.route('**/api/ops/v1/subscriptions**', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.subscriptions = [];
    body.due_days = 2;
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/subscriptions');
  await expect(page.getByText('No subscription is due in 2 days.')).toBeVisible();
  await axe(page);
});

test('loading announces itself and then clears', async ({ page }) => {
  await page.goto('/jobs');
  await page.route('**/api/ops/v1/overview', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 1500));
    await route.continue();
  });
  await page.getByRole('navigation', { name: 'Console' }).getByRole('link', { name: 'Overview' }).click();
  await expect(page.locator('#main')).toHaveAttribute('aria-busy', 'true');
  await expect(page.getByRole('status')).toHaveText('Loading…');
  await expect(page.getByRole('heading', { level: 1 })).toBeFocused();
  await expect(page.getByRole('status')).toHaveCount(0);
  await expect(page.locator('#main')).not.toHaveAttribute('aria-busy');
});

test('401 offers reload and 503 try again fetches again', async ({ context, page }) => {
  await context.addCookies([{ name: 'ops_preview_fault', value: '401', url: 'http://127.0.0.1:4181' }]);
  await page.goto('/');
  await expect(page.getByText('Signed out of Access')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Reload' })).toHaveAttribute('href', '/');
  await context.clearCookies();
  await context.addCookies([{ name: 'ops_preview_fault', value: '503', url: 'http://127.0.0.1:4181' }]);
  await page.goto('/');
  await expect(page.getByText('The operator API is not configured', { exact: true })).toBeVisible();
  const again = page.getByRole('button', { name: 'Try again' });
  await again.focus();
  await expectFocusRing(page);
  await context.clearCookies();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Overview');
});

test('keyboard reaches the installation tabs and a scroll region', async ({ page }) => {
  await page.goto('/installations/north');
  const users = page.getByRole('navigation', { name: 'Installation' }).getByRole('link', { name: 'Overview' });
  await tabTo(page, users);
  await expect(users).toHaveAttribute('aria-current', 'page');
  const region = page.locator('.table-scroll').first();
  await tabTo(page, region);
  await expectFocusRing(page);
});
