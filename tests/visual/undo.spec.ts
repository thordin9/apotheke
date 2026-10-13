import { test, expect } from './fixtures';

test.describe('undo', () => {
  test('delete an item, then undo from the bar @smoke', async ({ app, page }) => {
    await app.go('/item/1');
    const name = await page.locator('.item-name').textContent();
    page.once('dialog', d => d.accept());
    await page.locator('#delete-btn, form[action="/item/1/delete"] button').first().click();
    await expect(page).toHaveURL(u => u.pathname === '/' && !u.search.includes('undo='));   // the token is dropped from the address
    const bar = page.locator('#undo-bar');
    await expect(bar).toBeVisible();
    await expect(bar).toContainText(`Deleted “${name}”`);
    await expect(page.locator('.toast')).toBeHidden();
    await app.noOverflow();
    await app.tapTargets();
    await page.locator('#undo-btn').click();
    await expect(page).toHaveURL(/\/item\/1\?undone=1$/);
    await expect(page.locator('.toast')).toHaveText('Undone');
    await expect(page.locator('.item-name')).toHaveText(name!);
    await expect(page.locator('#gal-count')).toHaveText('1 / 3');
    await expect(page.locator('#history')).toContainText('Restored (deleting was undone)');
  });

  test('edit, then undo', async ({ app, page }) => {
    await app.go('/item/2/edit');
    const old = await page.locator('input[name=name]').inputValue();
    await page.locator('input[name=name]').fill('Renamed thing');
    await page.locator('#save').click();
    await expect(page).toHaveURL(u => u.pathname === '/item/2' && !u.search.includes('undo='));
    await expect(page.locator('#undo-bar')).toContainText('Saved “Renamed thing”');
    await page.locator('#undo-btn').click();
    await expect(page.locator('.item-name')).toHaveText(old);
  });

  test('bulk move, then undo; dismiss hides the bar', async ({ app, page }) => {
    const before = app.sql<{ id: number; l: number | null }>('SELECT id, location_id AS l FROM items WHERE id IN (1, 3) ORDER BY id');
    await app.go('/?select=1');
    await page.locator('.card[data-id="1"] .card-title').click();
    await page.locator('.card[data-id="3"] .card-title').click();
    await page.locator('#bulk-open').click();
    await page.locator('#bulk-loc').selectOption({ label: 'Κουτί 5' });
    await page.locator('#bulk-move').click();
    await expect(page.locator('#undo-bar')).toContainText('Moved 2 items');
    await page.locator('#undo-btn').click();
    await expect(page).toHaveURL(/undone=2/);
    expect(app.sql('SELECT id, location_id AS l FROM items WHERE id IN (1, 3) ORDER BY id')).toEqual(before);
    // a second action, dismissed
    await page.locator('.card[data-id="1"] .card-title').click();
    await page.locator('#bulk-open').click();
    await page.locator('#bulk-loc').selectOption({ label: 'Κουτί 5' });
    await page.locator('#bulk-move').click();
    await page.locator('#undo-x').click();
    await expect(page.locator('#undo-bar')).toBeHidden();
  });
});
