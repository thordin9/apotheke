import { test, expect, pinVersionLabel } from './fixtures';
import type { Page } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';

/* Visual baselines for batch 5: stocktake, search highlights, CSV import, photo editor, roles & registration (light + dark). */
const shot = async (page: Page, name: string, fullPage = false) => {
  await page.evaluate(() => document.fonts.ready);
  await pinVersionLabel(page);
  await page.locator('img').evaluateAll(imgs => Promise.race([
    Promise.all(imgs.map(i => { const im = i as HTMLImageElement; im.loading = 'eager'; return im.decode().catch(() => {}); })),
    new Promise(r => setTimeout(r, 3000)),
  ]));
  await expect(page).toHaveScreenshot(name + '.png', { fullPage, mask: [page.locator('.app-version'), page.locator('time'), page.locator('.times'),
    page.locator('.user small'), page.locator('.stk-h small'), page.locator('#imp-history small')] });
};

test.use({ publicUrl: 'http://apotheke.local:9091' });

async function accountsOn(page: Page, app: any) {
  await app.go('/settings/security');
  await page.locator('#pw-user').fill('root');
  await page.locator('#pw-new').fill('root-password');
  await page.locator('#pw-confirm').fill('root-password');
  await page.locator('#pw-save').click();
  await expect(page.locator('.toast')).toHaveText('Password saved');
}

test.describe('visual: batch 5 features', () => {
  test('stocktake start and scan', async ({ app, page }) => {
    await app.go('/stocktake');
    await shot(page, 'stocktake-start');
    await page.locator('#stk-go').click();
    await expect(page).toHaveURL(/\/stocktake\/\d+$/);
    await shot(page, 'stocktake-scan');
  });

  test('search with highlights', async ({ app, page }) => {
    await app.go('/?q=drill');
    await expect(page.locator('#grid mark').first()).toBeVisible();
    await shot(page, 'search-highlights');
  });

  test('CSV import preview', async ({ app, page }) => {
    const file = path.join(app.server.filesDir, 'visual.csv');
    fs.writeFileSync(file, 'Item;Where;Qty\nLadder;Shed › Wall;1\nRope;Garage shelf;two\n');
    await app.go('/import');
    await page.locator('#imp-file').setInputFiles(file);
    await page.locator('#imp-go').click();
    await page.locator('#imp-preview').click();
    await expect(page.locator('#imp-sum')).toBeVisible();
    await shot(page, 'import-preview');
  });

  test('photo editor', async ({ app, page }) => {
    await app.go('/item/1/photos/1/edit');
    await expect(page.locator('#pe-tools')).toBeVisible();
    await page.locator('#pe-aspect [data-aspect="1"]').click();
    await shot(page, 'photo-editor');
  });

  test('roles editor and registration', async ({ app, page }) => {
    await accountsOn(page, app);
    await page.locator('#rc-editor summary').click();
    await page.locator('#rc-editor').scrollIntoViewIfNeeded();
    await shot(page, 'roles-editor');
    await page.locator('label[for="s-auth_register_disabled"]').click();
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    const csrf = (await page.locator('meta[name=csrf]').getAttribute('content'))!;
    await page.request.post('/logout', { form: { csrf } });
    await app.go('/register');
    await shot(page, 'register');
  });
});
