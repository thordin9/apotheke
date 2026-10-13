import { test, expect } from './fixtures';

test.describe('stocktake', () => {
  test('tick, scan a misplaced item, finish with moves @smoke', async ({ app, page }) => {
    await app.go('/');
    await app.menu();
    await page.locator('#menu-stocktake').click();
    await expect(page).toHaveURL(/\/stocktake$/);
    await page.locator('#stk-loc').selectOption({ label: 'Garage shelf (2)' });
    await app.tapTargets();
    await page.locator('#stk-go').click();
    await expect(page).toHaveURL(/\/stocktake\/\d+$/);
    await expect(page.locator('#stk-count')).toHaveText('0 of 2');
    await app.noOverflow();
    await app.tapTargets();
    // tick in the list (in place, no reload)
    await page.locator('.stk-tick', { hasText: 'Cordless drill' }).click();
    await expect(page.locator('.toast')).toContainText('Found “Cordless drill”');
    await expect(page.locator('#stk-count')).toHaveText('1 of 2');
    await expect(page.locator('.stk-tick', { hasText: 'Cordless drill' })).toHaveAttribute('aria-pressed', 'true');
    // a QR label link of an item that lives in the attic
    await page.locator('#stk-code').fill(`${app.base}/item/3`);
    await page.locator('#stk-code').press('Enter');
    await expect(page.locator('.toast')).toContainText('belongs in Attic');
    await expect(page.locator('#stk-misplaced')).toContainText('Camping stove');
    await expect(page.locator('#stk-code')).toHaveValue('');
    // unknown code: error toast, field kept
    await page.locator('#stk-code').fill('000000');
    await page.locator('#stk-add').click();
    await expect(page.locator('.toast-err')).toContainText('No item has the barcode');
    await page.locator('#stk-finish').click();
    await expect(page.locator('#rep-missing')).toContainText('Screwdriver set');
    await expect(page.locator('#rep-misplaced')).toContainText('Camping stove');
    await expect(page.locator('#stk-moves')).toBeChecked();
    await app.noOverflow();
    await app.tapTargets();
    await page.locator('#stk-done').click();
    await expect(page.locator('.toast')).toContainText('Stocktake finished · 1 item moved');
    await expect(page.locator('#stk-summary')).toContainText('1 of 2 found · 1 missing · 1 misplaced · 1 moved');
    expect(app.sql('SELECT location_id FROM items WHERE id = 3')[0]).toEqual({ location_id: 1 });
    await app.go('/stocktake');
    await expect(page.locator('#stk-history')).toContainText('1 of 2 found');
  });

  test('works without JavaScript', async ({ browser, app }) => {
    const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL: app.base });
    const p = await ctx.newPage();
    await p.goto('/stocktake');
    await p.locator('#stk-loc').selectOption({ label: 'Attic (2)' });
    await p.locator('#stk-go').click();
    await p.locator('.stk-tick', { hasText: 'Tent' }).click();
    await expect(p.locator('#stk-count')).toHaveText('1 of 2');
    await p.locator('#stk-code').fill('#1');
    await p.locator('#stk-add').click();
    await expect(p.locator('#stk-misplaced')).toContainText('Cordless drill');
    await p.locator('#stk-cancel').click();
    await expect(p).toHaveURL(/\/stocktake\?done=cancelled$/);
    await ctx.close();
  });
});
