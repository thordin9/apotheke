import { test, expect } from './fixtures';

test.describe('duplicate item', () => {
  test('duplicate with photos opens the edit form of the copy', async ({ app, page }) => {
    await app.go('/item/1');
    await expect(page.locator('#dupform')).toBeVisible();
    await app.tapTargets();
    await page.locator('#dup-photos').check();
    await page.locator('#dup-btn').click();
    await page.waitForURL(/\/item\/\d+\/edit\?copied=1$/);
    const id = Number(new URL(page.url()).pathname.split('/')[2]);
    expect(id).toBeGreaterThan(8);
    await expect(page.locator('input[name=name]')).toHaveValue('Cordless drill (copy)');
    await expect(page.locator('.toast')).toHaveText('Copy created – edit it here');
    await page.locator('input[name=name]').fill('Cordless drill #2');
    await page.locator('#save').click();
    await page.waitForURL(u => !u.pathname.endsWith('/edit'));
    await app.go(`/item/${id}`);
    await expect(page.locator('.item-name')).toHaveText('Cordless drill #2');
    await expect(page.locator('#gal-count')).toHaveText('1 / 3');
    await expect(page.locator('.tags a')).toHaveText(['#Garage', '#Power tools']);
    await expect(page.locator('#facts')).toContainText('189.90 EUR');
    await expect(page.locator('#history')).toContainText('copied from “Cordless drill”');
    const files = (i: number) => app.sql<{ file: string }>(`SELECT file FROM item_photos WHERE item_id = ${i} ORDER BY position`).map(r => r.file);
    expect(files(id)).toHaveLength(3);
    expect(files(id).filter(f => files(1).includes(f))).toEqual([]);

    // without photos
    await app.go('/item/2');
    await page.locator('#dup-btn').click();
    await page.waitForURL(/\/edit\?copied=1$/);
    const id2 = Number(new URL(page.url()).pathname.split('/')[2]);
    expect(files(id2)).toHaveLength(0);
    await app.noOverflow();
  });
});
