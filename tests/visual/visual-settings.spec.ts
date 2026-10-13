import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';

/* Visual baselines for the Control Panel (light + dark projects), a custom accent and a custom logo. */
const shot = async (page: Page, name: string, fullPage = true) => {
  await page.evaluate(() => document.fonts.ready);
  await page.locator('img').evaluateAll(imgs => Promise.race([
    Promise.all(imgs.map(i => { const im = i as HTMLImageElement; im.loading = 'eager'; return im.decode().catch(() => {}); })),
    new Promise(r => setTimeout(r, 3000)),
  ]));
  await expect(page).toHaveScreenshot(name + '.png', { fullPage, mask: [page.locator('.app-version'), page.locator('time'), page.locator('.times'), page.locator('#systable td')] });
};

test.use({ publicUrl: 'http://apotheke.local:8080' });

test.describe('visual: control panel', () => {
  test('home and sections', async ({ app, page }) => {
    await app.go('/settings');
    await shot(page, 'settings-home');
    for (const s of ['general', 'appearance', 'locale', 'list', 'items', 'labels', 'limits', 'uploads', 'security', 'data', 'system']) {
      await app.go(`/settings/${s}`);
      await shot(page, `settings-${s}`);
    }
  });

  test('custom accent (terracotta)', async ({ app, page }) => {
    await app.go('/settings/appearance');
    await page.locator('.preset[data-accent="#C8502D"]').click();
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    await page.locator('.toast').evaluate(e => e.remove());
    await shot(page, 'accent-appearance');
    await app.go('/');
    await shot(page, 'accent-list', false);
    await app.go('/item/1');
    await shot(page, 'accent-item', false);
  });

  test('custom logo in the header', async ({ app, page }) => {
    await app.go('/settings/appearance');
    await page.locator('#logo-file').setInputFiles(app.image('brand-logo.png', [15, 118, 110], true));
    await page.locator('#logo-upload').click();
    await expect(page.locator('.toast')).toHaveText('Logo updated');
    await app.go('/');
    await expect(page.locator('header h1.brand img.brand-logo')).toHaveAttribute('src', /\/brand\/logo\.png/);
    await expect(page.locator('header')).toHaveScreenshot('logo-header.png');
    await shot(page, 'logo-list', false);
  });
});
