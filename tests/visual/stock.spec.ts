import { test, expect } from './fixtures';

test.describe('low-stock alerts', () => {
  test('set a level, badge on card + detail, +/- toggles it, tile and filter', async ({ app, page }) => {
    await app.go('/item/8/edit');
    await page.locator('#min_qty').fill('4');
    await page.locator('#save').click();
    await page.waitForURL(u => !u.pathname.endsWith('/edit'));
    await expect.poll(() => app.sql<{ min_qty: number }>('SELECT min_qty FROM items WHERE id = 8')[0].min_qty).toBe(4);

    await app.go('/item/8');
    const box = page.locator('#lowbox');
    await expect(box).not.toHaveClass(/is-low/);
    await expect(box.locator('.lowbadge')).toBeHidden();
    await page.locator('.qty-lg .qbtn[data-delta="-1"]').click();
    await expect(page.locator('.qty-lg output')).toHaveText('4');
    await expect(box, '+/- toggles the badge live').toHaveClass(/is-low/);
    await expect(box.locator('.lowbadge')).toHaveText('Running low');
    await expect.poll(() => app.sql<{ quantity: number }>('SELECT quantity FROM items WHERE id = 8')[0].quantity).toBe(4);
    await expect(page.locator('#history')).toContainText('Running low at');
    await app.noOverflow();
    await app.tapTargets();

    await app.go('/');
    const card = page.locator('.card[data-id="8"]');
    await expect(card).toHaveClass(/is-low/);
    await expect(card.locator('.lowbadge')).toBeVisible();
    await expect(page.locator('.card.is-low')).toHaveCount(1);
    await app.openOverview();
    const tile = page.locator('#stats .tile-low');
    await expect(tile).toContainText('1');
    await tile.click();
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await expect(page.locator('#achips a[data-clear="stock"]')).toBeVisible();
    await page.locator('#achips a[data-clear="stock"]').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);

    await page.locator('#filter-open').click();
    await page.locator('#stock').selectOption('low');
    await page.locator('#filter-apply').click();
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await expect(page).toHaveURL(/stock=low/);
    await page.reload();
    await expect(page.locator('#stock'), 'filter lives in the URL').toHaveValue('low');
  });
});
