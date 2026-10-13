import { test, expect } from './fixtures';

test.describe('favourites and recently viewed', () => {
  test('star on a card, favourites chip and filter @smoke', async ({ app, page }) => {
    await app.go('/');
    await expect(page.locator('#fav-chip')).toHaveCount(0);
    const star = page.locator('.card[data-id="2"] .favbtn');
    await expect(star).toHaveAttribute('aria-pressed', 'false');
    await app.tapTargets();
    await star.click();
    await expect(star).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.toast')).toHaveText('Added to favourites');
    expect(app.sql<{ f: number }>('SELECT favourite AS f FROM items WHERE id = 2')[0].f).toBe(1);
    await expect(page).toHaveURL(/\/$/);
    await app.go('/');
    await expect(page.locator('#fav-chip')).toContainText('Favourites 1');
    await page.locator('#fav-chip').click();
    await expect(page).toHaveURL(/[?&]fav=1/);
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await expect(page.locator('#achips .chip-fav')).toBeVisible();
    await app.noOverflow();
    // un-star on the item page
    await app.go('/item/2');
    const s2 = page.locator('header .favbtn');
    await expect(s2).toHaveAttribute('aria-pressed', 'true');
    await s2.click();
    await expect(s2).toHaveAttribute('aria-pressed', 'false');
    expect(app.sql<{ f: number }>('SELECT favourite AS f FROM items WHERE id = 2')[0].f).toBe(0);
  });

  test('recently viewed strip on the list', async ({ app, page }) => {
    await app.go('/');
    await expect(page.locator('#recent')).toHaveCount(0);
    for (const id of [1, 3, 2]) await app.go(`/item/${id}`);
    await app.go('/');
    const links = page.locator('#recent .recent-it');
    await expect(links).toHaveCount(3);
    await expect(links.first()).toHaveAttribute('href', '/item/2');
    await app.noOverflow();
    await app.tapTargets();
    await links.nth(1).click();
    await expect(page).toHaveURL(/\/item\/3$/);
    await app.go('/');
    await expect(page.locator('#recent .recent-it').first()).toHaveAttribute('href', '/item/3');
    await page.locator('#recent-clear').click();
    await expect(page.locator('#recent')).toHaveCount(0);
  });

  test('star works without JavaScript', async ({ browser, app }) => {
    const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL: app.server.base });
    const p = await ctx.newPage();
    await p.goto('/item/1');
    await p.locator('header .favbtn').click();
    await expect(p).toHaveURL(/\/item\/1$/);
    await expect(p.locator('header .favbtn')).toHaveAttribute('aria-pressed', 'true');
    await ctx.close();
  });
});
