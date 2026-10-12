import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * Visual regression baselines (per project = device × colour scheme). Data and server clock are fixed;
 * timestamps are masked anyway, animations/caret are disabled in playwright.config.ts.
 */
test.use({ appPassword: 'pw' });

const shot = async (page: Page, name: string, fullPage = false) => {
  await page.evaluate(() => document.fonts.ready);
  await page.locator('img').evaluateAll(imgs => Promise.all(imgs.map(i => (i as HTMLImageElement).decode().catch(() => {}))));
  await expect(page).toHaveScreenshot(name + '.png', { fullPage, mask: [page.locator('time'), page.locator('.times')] });
};

test.describe('visual: login', () => {
  test('login page', async ({ page }) => {
    await page.goto('/login');
    await shot(page, 'login');
  });
});
