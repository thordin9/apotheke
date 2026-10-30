import { test, expect } from './fixtures';

test.describe('friendly error pages', () => {
  test('404 for unknown pages and items', async ({ app, page }) => {
    // each page's own message, so an unported page (CI4's "Page not found.") does not pass for a ported one
    const cases: [string, string][] = [['/nope', 'Page not found.'], ['/item/999', 'Item not found.'], ['/item/999/edit', 'Item not found.'], ['/activity?item=999', 'Item not found.'],
      ['/labels?loc=999', 'Location not found.']];
    for (const [u, message] of cases) {
      const r = await page.goto(u);
      expect(r!.status(), u).toBe(404);
      await expect(page.locator('.empty p'), u).toHaveText(message);
      await expect(page.locator('.empty a.btn-primary')).toHaveText('Back to catalog');
    }
    await page.locator('.empty a.btn-primary').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);
  });

  test('expired session (CSRF) shows a friendly page and changes nothing', async ({ app, page }) => {
    await app.go('/item/2');
    await page.context().clearCookies({ name: 'apothekesid' });   // the session only: consent stays (docs/deployment-and-operations.md)
    page.once('dialog', d => d.accept());
    await page.getByRole('button', { name: 'Delete item' }).click();
    await expect(page.locator('.empty p')).toHaveText('Your session expired. Reload the page and try again.');
    expect(app.sql('SELECT COUNT(*) AS n FROM items WHERE id = 2')[0]).toEqual({ n: 1 });
  });
});
