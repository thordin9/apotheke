import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';

const routes: { path: string; heading: string }[] = [
  { path: '/', heading: 'Fleet' },
  { path: '/installations', heading: 'Installations' },
  { path: '/installations/north', heading: 'Installation' },
  { path: '/installations/north/users', heading: 'Users' },
  { path: '/plans', heading: 'Plans and limits' },
  { path: '/subscriptions', heading: 'Subscriptions' },
  { path: '/jobs', heading: 'Jobs' },
  { path: '/audit', heading: 'Audit log' },
];

async function axe(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(results.violations).toEqual([]);
}

async function tabTo(page: Page, locator: Locator): Promise<void> {
  for (let i = 0; i < 40; i += 1) {
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

test.describe('operator console', () => {
  test.beforeEach(async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
  });

  for (const route of routes) {
    test(`axe on ${route.path}`, async ({ page }) => {
      await page.goto(route.path);
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(route.heading);
      await axe(page);
    });
  }

  test('fleet shows a blank revenue, the prices note and the freshness stamp', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#revenue-amount')).toBeEmpty();
    await expect(page.locator('#revenue-note')).toHaveText('No prices set yet.');
    await expect(page.getByText('Figures as of 2026-11-20T00:00:00Z.')).toBeVisible();
    await expect(page.locator('body')).toHaveAttribute('data-provisioning-enabled', 'false');
    await expect(page.locator('body')).toHaveAttribute('data-limits-enabled', 'false');
    await page.screenshot({ path: 'test-results/fleet.png', fullPage: true });
  });

  test('installations list screenshot', async ({ page }) => {
    await page.goto('/installations');
    await expect(page.getByRole('link', { name: 'north.example.test' })).toBeVisible();
    await page.screenshot({ path: 'test-results/installations.png', fullPage: true });
  });

  test('keyboard: skip link, nav, filters, search and opening an installation', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
    await expectFocusRing(page);
    await page.keyboard.press('Enter');
    await expect(page.locator('#main')).toBeFocused();

    await page.goto('/');
    await tabTo(page, page.getByRole('link', { name: 'Installations', exact: true }));
    await expectFocusRing(page);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/installations$/);
    await expect(page.getByRole('heading', { level: 1 })).toBeFocused();

    await tabTo(page, page.getByRole('link', { name: 'Suspended' }));
    await expectFocusRing(page);
    await page.keyboard.press('Enter');
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
    await expect(page.getByRole('link', { name: 'south.example.test' })).toHaveCount(0);
    await tabTo(page, north);
    await expectFocusRing(page);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/installations\/north$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Installation');
    await expect(page.getByRole('heading', { level: 1 })).toBeFocused();
    await expect(page.getByText('This screen comes later.')).toBeVisible();
  });
});

const faults: { code: string; message: string }[] = [
  { code: '401', message: 'Your Access session ended, reload to sign in' },
  { code: '503', message: 'The operator API is not configured, or the container cannot be reached.' },
  { code: '410', message: 'This installation has been deleted.' },
];

for (const fault of faults) {
  test(`startup ${fault.code} is the typed error and passes axe`, async ({ context, page }) => {
    await context.addCookies([{
      name: 'ops_preview_fault',
      value: fault.code,
      url: 'http://127.0.0.1:4181',
    }]);
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    await page.goto('/');
    await expect(page.getByRole('alert')).toHaveText(fault.message);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Operator console');
    await axe(page);
  });
}
