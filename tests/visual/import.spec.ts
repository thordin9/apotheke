import { test, expect } from './fixtures';
import fs from 'fs';
import path from 'path';

test.describe('CSV import', () => {
  test('upload, map, preview with errors, import @smoke', async ({ app, page }) => {
    const file = path.join(app.server.filesDir, 'items.csv');
    fs.writeFileSync(file, 'Item;Where;Qty;Colour\nLadder;Shed › Wall;1;grey\nRope;Garage shelf;two;red\nBucket;Shed;3;blue\n');
    await app.go('/');
    await app.menu();
    await page.locator('#menu-import').click();
    await expect(page).toHaveURL(/\/import$/);
    await page.locator('#imp-file').setInputFiles(file);
    await page.locator('#imp-go').click();
    await expect(page).toHaveURL(/\/import\/\d+$/);
    await expect(page.locator('#imp-col-0')).toHaveValue('name');
    await expect(page.locator('#imp-col-1')).toHaveValue('location');
    await expect(page.locator('#imp-col-3')).toHaveValue('');
    await page.locator('#imp-col-3').selectOption('notes');
    await app.noOverflow();
    await app.tapTargets();
    await page.locator('#imp-preview').click();
    await expect(page.locator('#imp-sum')).toContainText('2 new');
    await expect(page.locator('#imp-errors')).toContainText('Row 3 “Rope”: Quantity “two”');
    await expect(page.locator('#imp-made')).toContainText('locations: Shed, Wall');
    await expect(page.locator('#imp-commit')).toBeDisabled();
    await expect(page.locator('#imp-col-3')).toHaveValue('notes');
    await page.locator('#imp-skip').check();
    await page.locator('#imp-preview').click();
    await expect(page.locator('#imp-commit')).toBeEnabled();
    await app.noOverflow();
    await page.locator('#imp-commit').click();
    await expect(page.locator('.toast')).toContainText('Imported · 2 added · 0 updated · 1 skipped · 2 new locations');
    await expect(page.locator('#imp-history')).toContainText('items.csv');
    expect(app.sql("SELECT i.notes, l.name AS loc FROM items i JOIN locations l ON l.id = i.location_id WHERE i.name = 'Ladder'")).toEqual([{ notes: 'grey', loc: 'Wall' }]);
    await app.go('/?q=ladder');
    await expect(page.locator('#grid .card-title')).toHaveText(['Ladder']);
  });

  test('round-trips the CSV export', async ({ app, page }) => {
    const csv = await (await page.request.get(`${app.base}/export.csv`)).text();
    const file = path.join(app.server.filesDir, 'export.csv');
    fs.writeFileSync(file, csv);
    await app.go('/import');
    await page.locator('#imp-file').setInputFiles(file);
    await page.locator('#imp-go').click();
    await page.locator('#imp-preview').click();
    await expect(page.locator('#imp-sum')).toContainText('0 new');
    await expect(page.locator('#imp-sum')).toContainText('8 unchanged');
    await expect(page.locator('#imp-sum')).toContainText('0 errors');
    await page.locator('#imp-commit').click();
    await expect(page.locator('.toast')).toContainText('0 added · 0 updated · 8 unchanged');
    expect(await (await page.request.get(`${app.base}/export.csv`)).text()).toBe(csv);
  });
});
