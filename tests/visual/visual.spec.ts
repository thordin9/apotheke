import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * Visual regression baselines (per project = device × colour scheme). Data and server clock are fixed;
 * timestamps are masked anyway, animations/caret are disabled in playwright.config.ts.
 */
const shot = async (page: Page, name: string, fullPage = false) => {
  await page.evaluate(() => document.fonts.ready);
  await page.locator('img').evaluateAll(imgs => Promise.race([
    Promise.all(imgs.map(i => { const im = i as HTMLImageElement; im.loading = 'eager'; return im.decode().catch(() => {}); })),
    new Promise(r => setTimeout(r, 3000)),
  ]));
  await expect(page).toHaveScreenshot(name + '.png', { fullPage, mask: [page.locator('time'), page.locator('.times')] });
};

test.use({ publicUrl: 'http://apotheke.local:8080' });

test.describe('visual', () => {
  test('list with overview', async ({ app, page }) => {
    await app.go('/');
    await shot(page, 'list');
    await page.locator('#filter-open').click();
    await page.locator('#loc').selectOption('1');
    await expect(page.locator('#grid .card')).toHaveCount(2);
    await shot(page, 'filter-sheet');
    await page.locator('#filter-close').click();
    await shot(page, 'list-filtered');
    await app.menu();
    await shot(page, 'menu');
  });

  test('item detail and photo manager', async ({ app, page }) => {
    await app.go('/item/1');
    await shot(page, 'item');
    await page.locator('#photos > summary').click();
    await page.locator('#photos').scrollIntoViewIfNeeded();
    await shot(page, 'item-photos');
  });

  test('forms', async ({ app, page }) => {
    await app.go('/new');
    await shot(page, 'new');
    await app.go('/item/1/edit');
    await page.locator('.more-fields > summary').click();
    await page.locator('#tagbox').scrollIntoViewIfNeeded();
    await shot(page, 'edit-tags-fields');
  });

  test('manage, labels, backup, activity', async ({ app, page }) => {
    await app.go('/locations');
    await shot(page, 'locations');
    await app.go('/labels?loc=1&items=1');
    await shot(page, 'labels');
    await app.go('/backup');
    await shot(page, 'backup');
    await app.go('/activity');
    await shot(page, 'activity');
  });

  test('error page', async ({ page }) => {
    await page.goto('/nope');
    await shot(page, 'not-found');
  });
});

test.describe('visual: empty', () => {
  test.use({ seed: 'empty' });
  test('empty catalog', async ({ app, page }) => {
    await app.go('/');
    await shot(page, 'empty');
  });
});

