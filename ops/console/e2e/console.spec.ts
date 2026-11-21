import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { OPS_CSP } from '../src/csp.ts';

const routes: { path: string; heading: string; shot: string }[] = [
  { path: '/overview', heading: 'Overview', shot: 'overview' },
  { path: '/', heading: 'Overview', shot: '' },
  { path: '/installations', heading: 'Installations', shot: 'installations' },
  { path: '/installations/north', heading: 'Installation', shot: 'installation' },
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
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Installation');
    await expect(page.getByRole('heading', { level: 1 })).toBeFocused();

    const users = page.getByRole('navigation', { name: 'Console' }).getByRole('link', { name: 'Users' });
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
  await expect(page.getByRole('cell', { name: '<img src=x onerror=alert(1)>' })).toBeVisible();
  expect(await page.locator('img').count()).toBe(0);
  await page.goto('/audit');
  await expect(page.getByRole('cell', { name: '<img src=x onerror=alert(1)>' })).toBeVisible();
  await page.goto('/installations/north/users');
  await expect(page.getByRole('cell', { name: '<b>x</b>' })).toBeVisible();
  expect(await page.locator('b').count()).toBe(0);
  expect(await page.locator('img').count()).toBe(0);
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
