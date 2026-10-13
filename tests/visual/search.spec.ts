import { test, expect } from './fixtures';

test.describe('full-text search', () => {
  test('ranked, highlighted, snippets and typo tolerance @smoke', async ({ app, page }) => {
    await app.go('/');
    await page.locator('#q').fill('garage');
    // "Garage" is a tag on three items and part of the location name "Garage shelf"
    await expect(page.locator('#grid .card')).toHaveCount(3);
    await expect(page.locator('#grid .card .snip mark').first()).toHaveText(/garage/i);
    await page.locator('#q').fill('drill');
    await expect(page.locator('#grid .card-title mark')).toHaveText('drill');
    await page.locator('#q').fill('batteries');
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await expect(page.locator('#grid .card .snip')).toContainText('Notes');
    await expect(page.locator('#grid .card .snip mark')).toHaveText('batteries');
    await page.locator('#q').fill('screwdrivr');
    await expect(page.locator('#grid .card-title')).toHaveText(['Screwdriver set']);
    await expect(page.locator('#count')).toContainText('showing “screwdriver” for “screwdrivr”');
    await page.locator('#q').fill('MKT-DHP482');
    await expect(page.locator('#grid .card-title')).toHaveText(['Cordless drill']);
    await expect(page.locator('#grid .snip')).toContainText('Serial');
    await page.locator('#q').fill('<img src=x onerror=alert(1)>');
    await expect(page.locator('#grid .card')).toHaveCount(0);
    await app.noOverflow();
    await app.tapTargets();
  });

  test('works without JavaScript and shows the engine in System', async ({ app, browser }) => {
    const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL: app.base });
    const p = await ctx.newPage();
    await p.goto('/?q=' + encodeURIComponent('κουτι'));
    await expect(p.locator('#grid .card-title')).toHaveText(['Πρίζα πολύπριζο']);
    await expect(p.locator('#grid .snip mark')).toHaveText('Κουτί');
    await p.goto('/settings/system');
    await expect(p.locator('#search-engine')).toHaveText(/^(fts5|pg)$/);
    await ctx.close();
  });
});
