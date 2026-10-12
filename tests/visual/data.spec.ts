import { test, expect } from './fixtures';
import * as fs from 'node:fs';

test.describe('labels, export, backup', () => {
  test('QR labels: all locations, one location, per item, item label, print', async ({ app, page }) => {
    await app.go('/');
    await app.menu();
    await page.locator('#menu a[href="/labels"]').click();
    await expect(page.locator('figure.label')).toHaveCount(5);
    await expect(page.locator('figure.label svg.qr')).toHaveCount(5);
    await expect(page.locator('figure.label').first()).toHaveAttribute('data-url', /\/\?loc=\d+$/);
    await app.go('/labels?loc=1&items=1');
    await expect(page.locator('figure.label')).toHaveCount(2);
    await expect(page.locator('figure.label').first()).toHaveAttribute('data-url', /\/item\/\d+$/);
    await app.go('/item/1');
    await page.locator('.item-links a[href="/item/1/label"]').click();
    await expect(page.locator('figure.label')).toHaveCount(1);
    await expect(page.locator('figure.label figcaption b')).toHaveText('Cordless drill');
    await page.evaluate(() => { (window as any).__printed = 0; window.print = () => { (window as any).__printed++; }; });
    await page.locator('#print').click();
    expect(await page.evaluate(() => (window as any).__printed)).toBe(1);
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('header.top')).toBeHidden();
    await page.emulateMedia({ media: 'screen' });
    await app.noOverflow();
  });

  test('CSV export downloads the full catalog @smoke', async ({ app, page, browserName }) => {
    await app.go('/');
    await app.menu();
    const link = page.locator('#menu a[href="/export.csv"]');
    let csv: string;
    if (browserName === 'webkit') {
      // Playwright's Linux WebKit doesn't emit "download" for text/* attachments; check the same response directly.
      await expect(link).toBeVisible();
      const r = await page.request.get(await link.getAttribute('href') as string);
      expect(r.headers()['content-disposition']).toBe('attachment; filename="apotheke-2026-06-15.csv"');
      csv = (await r.text()).replace(/^\uFEFF/, '');
    } else {
      const [dl] = await Promise.all([page.waitForEvent('download'), link.click()]);
      expect(dl.suggestedFilename()).toBe('apotheke-2026-06-15.csv');
      csv = fs.readFileSync(await dl.path(), 'utf8').replace(/^\uFEFF/, '');
    }
    const lines = csv.trim().split('\n');
    expect(lines[0]).toBe('id,name,location,category,quantity,notes,image,created_at,updated_at,tags,value,currency,purchase_date,serial,min_qty');
    expect(lines).toHaveLength(9);
    expect(csv).toContain('"Garage, Power tools",189.90,EUR,2024-03-14,MKT-DHP482');
    expect(csv).toContain('Πρίζα πολύπριζο');
  });

  test('backup download, restore dry run, confirm @smoke', async ({ app, page }, info) => {
    await app.go('/');
    await app.menu();
    await page.locator('#menu a[href="/backup"]').click();
    await expect(page.locator('.btable td').first()).toHaveText('8');
    const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('#backup-dl').click()]);
    expect(dl.suggestedFilename()).toMatch(/^apotheke-backup-2026-06-15-\d{6}\.zip$/);
    const zip = info.outputPath('apotheke-backup.zip');
    await dl.saveAs(zip);
    expect(fs.readFileSync(zip).subarray(0, 2).toString()).toBe('PK');
    // change the catalog, then restore
    await app.go('/item/1');
    page.once('dialog', d => d.accept());
    await page.getByRole('button', { name: 'Delete item' }).click();
    await expect(page.locator('#grid .card')).toHaveCount(7);
    await app.go('/backup');
    await page.locator('#restore-file').setInputFiles(zip);
    await expect(page.locator('#restore-name')).toHaveText(/\.zip$/);
    await page.locator('#restore-check').click();
    await expect(page.locator('#restore-preview')).toBeVisible();
    await expect(page.locator('#restore-preview td[data-t=items]')).toHaveText('8');
    await expect(page.locator('#restore-preview')).toContainText('8 items, 5 locations, 4 categories');
    await app.noOverflow();
    await app.tapTargets();
    expect(app.sql('SELECT COUNT(*) AS n FROM items')[0]).toEqual({ n: 7 });   // dry run changes nothing
    // the confirm checkbox is required
    await page.locator('#restore-go').click();
    await expect(page).toHaveURL(/\/backup\/restore$/);
    await page.locator('#restore-ok').check();
    await page.locator('#restore-go').click();
    await expect(page).toHaveURL(/\/backup\?restored=1$/);
    await expect(page.locator('.toast')).toHaveText('Backup restored');
    await app.go('/item/1');
    await expect(page.locator('#gal-count')).toHaveText('1 / 3');
  });

  test('restore rejects files that are not backups', async ({ app, page }) => {
    await app.go('/backup');
    await page.locator('#restore-file').setInputFiles({ name: 'x.zip', mimeType: 'application/zip', buffer: Buffer.from('not a zip') });
    await page.locator('#restore-check').click();
    await expect(page.locator('#backup-err')).toHaveText('That file is not a zip archive.');
    await expect(page.locator('#restore-preview')).toHaveCount(0);
  });
});
