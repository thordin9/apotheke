import { test, expect } from './fixtures';

test.describe('friendly error pages', () => {
  test('404 for unknown pages and items', async ({ app, page }) => {
    for (const u of ['/nope', '/item/999', '/labels?loc=999']) {
      const r = await page.goto(u);
      expect(r!.status()).toBe(404);
      await expect(page.locator('.empty a.btn-primary')).toHaveText('Back to catalog');
    }
    await page.locator('.empty a.btn-primary').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);
  });

  test('expired session (CSRF) shows a friendly page and changes nothing', async ({ app, page }) => {
    await app.go('/item/2');
    await page.context().clearCookies();
    page.once('dialog', d => d.accept());
    await page.getByRole('button', { name: 'Delete item' }).click();
    await expect(page.locator('.empty p')).toHaveText('Your session expired. Reload the page and try again.');
    expect(app.sql('SELECT COUNT(*) AS n FROM items WHERE id = 2')[0]).toEqual({ n: 1 });
  });
});
