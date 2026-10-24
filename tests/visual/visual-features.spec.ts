import { test, expect, pinVersionLabel } from './fixtures';
import type { Page } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';

/* Visual baselines for notes/attachments, bulk labels, favourites + recently viewed, merge and undo (light + dark). */
const shot = async (page: Page, name: string, fullPage = false) => {
  await page.evaluate(() => document.fonts.ready);
  await pinVersionLabel(page);
  await page.locator('img').evaluateAll(imgs => Promise.race([
    Promise.all(imgs.map(i => { const im = i as HTMLImageElement; im.loading = 'eager'; return im.decode().catch(() => {}); })),
    new Promise(r => setTimeout(r, 3000)),
  ]));
  await expect(page).toHaveScreenshot(name + '.png', { fullPage, mask: [page.locator('.app-version'), page.locator('time'), page.locator('.times')] });
};

test.use({ publicUrl: 'http://apotheke.local:9091' });

const csrfOf = async (page: Page) => (await page.locator('meta[name=csrf]').getAttribute('content'))!;

test.describe('visual: batch 4 features', () => {
  test('notes (Markdown) and attachments', async ({ app, page }) => {
    await app.go('/item/1');
    const csrf = await csrfOf(page);
    await page.request.post('/save', { headers: { Accept: 'application/json' }, form: { csrf, id: '1', name: 'Cordless drill', quantity: '1', location_id: '1',
      notes: '## Charging\n**Always** charge the battery *before* storing it.\n\n1. Remove the bit\n2. Charge for `90 min`\n\nManual: [makita.example](https://makita.example/manual)' } });
    const pdf = path.join(app.server.filesDir, 'drill-manual.pdf');
    fs.writeFileSync(pdf, '%PDF-1.4\n%visual\n');
    await page.request.post('/item/1/attachments', { headers: { Accept: 'application/json' }, multipart: { csrf, 'files[]': { name: 'drill-manual.pdf', mimeType: 'application/pdf', buffer: fs.readFileSync(pdf) } } });
    await app.go('/item/1');
    await page.locator('#notes').scrollIntoViewIfNeeded();
    await shot(page, 'item-notes-attachments');
  });

  test('labels for a selection', async ({ app, page }) => {
    await app.go('/labels?ids=1,2,3');
    await shot(page, 'labels-selection');
  });

  test('favourites and recently viewed', async ({ app, page }) => {
    await app.go('/');
    const csrf = await csrfOf(page);
    for (const id of [1, 3]) await page.request.post(`/item/${id}/fav`, { headers: { Accept: 'application/json' }, form: { csrf, on: '1' } });
    for (const id of [2, 3, 1]) await app.go(`/item/${id}`);
    await app.go('/');
    await shot(page, 'list-favourites-recent');
    await app.go('/?fav=1');
    await shot(page, 'list-favourites-filter');
  });

  test('merge confirm', async ({ app, page }) => {
    await app.go('/new');
    const csrf = await csrfOf(page);
    const r = await page.request.post('/save', { headers: { Accept: 'application/json' }, form: { csrf, name: 'cordless Drill', quantity: '2', serial: 'MK-123', tags: 'power tools' } });
    const id = Number(/saved=(\d+)/.exec((await r.json()).redirect)![1]);
    await app.go(`/item/${id}/merge`);
    await shot(page, 'merge-pick');
    await app.go(`/item/${id}/merge?into=1`);
    await shot(page, 'merge-confirm');
  });

  test('undo bar', async ({ app, page }) => {
    await app.go('/item/2');
    page.once('dialog', d => d.accept());
    await page.locator('form[action="/item/2/delete"] button').click();
    await expect(page.locator('#undo-bar')).toBeVisible();
    await shot(page, 'undo-bar');
  });
});
