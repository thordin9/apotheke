import { test, expect } from './fixtures';

// Findings of the QA review (2026-10-13): duplicate quick-add field names, truncated tiles/placeholder at 375px,
// floating camera button covering the last content.
test.describe('QA fixes', () => {
  test('/new has one field per name; the no-JS "new location" field is saved', async ({ app, page, browser }) => {
    const html = await (await page.request.get(`${app.base}/new`)).text();
    for (const n of ['new_location', 'new_category', 'location_id', 'category_id', 'name']) {
      expect(html.match(new RegExp(`name="${n}"`, 'g'))?.length ?? 0, n).toBe(1);
    }
    const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL: app.base });
    const p = await ctx.newPage();
    await p.goto('/new');
    await p.locator('#name').fill('Wheelbarrow');
    await p.locator('#locsel').selectOption('__new');
    await p.locator('input[name=new_location_ns]').fill('Garden shed');
    await p.locator('#catsel').selectOption('__new');
    await p.locator('input[name=new_category_ns]').fill('Garden');
    await p.locator('#save').click();
    await expect(p.locator('main')).toContainText('Garden shed');
    await ctx.close();
    expect(app.sql("SELECT l.name AS loc, c.name AS cat FROM items i JOIN locations l ON l.id = i.location_id JOIN categories c ON c.id = i.category_id WHERE i.name = 'Wheelbarrow'"))
      .toEqual([{ loc: 'Garden shed', cat: 'Garden' }]);
  });

  test('375px: tile labels and the search placeholder are not cut off', async ({ app, page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await app.go('/');
    await expect(page.locator('#q')).toHaveAttribute('placeholder', 'Search items…');
    expect(await page.locator('#q').evaluate((el: HTMLInputElement) => {
      const c = document.createElement('canvas').getContext('2d')!;
      c.font = getComputedStyle(el).font;
      return c.measureText(el.placeholder).width <= el.clientWidth;
    })).toBe(true);
    await app.openOverview();
    const spans = page.locator('#stats .tile span');
    await expect(spans).toHaveCount(9);
    const cut = await spans.evaluateAll(els => els.filter(e => e.scrollWidth > e.clientWidth + 1).map(e => e.textContent));
    expect(cut).toEqual([]);
    await expect(page.locator('#stats .tile', { hasText: 'Running low' })).toBeVisible();
    await app.noOverflow();
  });

  test('the camera button never covers the end of the page', async ({ app, page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await app.go('/');
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.waitForTimeout(200);
    const fab = (await page.locator('#fab').boundingBox())!;
    const by = (await page.locator('main footer').boundingBox())!;
    expect(by.y + by.height).toBeLessThanOrEqual(fab.y);
    // nothing in the page (e.g. the "By category" card and its "All" link) ends under the button
    const under = await page.evaluate(([top, left]) => [...document.querySelectorAll('main a, main h4, main button')]
      .filter(e => { const r = e.getBoundingClientRect(); return r.height > 0 && r.bottom > top && r.top < innerHeight && r.right > left; }).length, [fab.y, fab.x]);
    expect(under).toBe(0);
  });
});
