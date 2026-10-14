import { test, expect } from './fixtures';

const isPhone = (w?: number) => (w ?? 1280) <= 640;

test.describe('QA v2.0.0 follow-ups', () => {
  test('overview: collapsed on phones, open on wider screens', async ({ app, page }) => {
    await app.go('/');
    const stats = page.locator('#stats');
    if (isPhone(page.viewportSize()?.width)) {
      await expect(stats).not.toHaveAttribute('open', '');
      await expect(page.locator('#grid .card').first()).toBeInViewport();
      await page.reload({ waitUntil: 'networkidle' });
      await expect(stats, 'still collapsed: auto-collapse does not save a choice').not.toHaveAttribute('open', '');
      await stats.locator('summary').click();
      await expect(stats).toHaveAttribute('open', '');
      await page.reload({ waitUntil: 'networkidle' });
      await expect(stats, 'an explicit choice wins').toHaveAttribute('open', '');
    } else {
      await expect(stats).toHaveAttribute('open', '');
    }
  });

  test('new item: ★ explained, two columns on desktop, Save bar never covers a field', async ({ app, page }) => {
    await app.go('/new');
    await expect(page.locator('#locsel option:checked')).toContainText('★');
    await expect(page.locator('#locstar')).toHaveText('★ marks the default location, preselected for new items.');
    await expect(page.locator('#catstar')).toContainText('default category');
    const w = page.viewportSize()?.width ?? 1280;
    const loc = (await page.locator('.picker[data-kind=location]').boundingBox())!;
    const cat = (await page.locator('.picker[data-kind=category]').boundingBox())!;
    if (w >= 900) {
      expect(Math.abs(loc.y - cat.y), 'location and category side by side').toBeLessThan(2);
      expect(cat.x).toBeGreaterThan(loc.x + loc.width);
      await page.locator('.more-fields summary').click();
      const a = (await page.locator('#purchase_date').boundingBox())!;
      const v = (await page.locator('#value').boundingBox())!;
      expect(Math.abs(a.y - v.y) < 2 || Math.abs(a.x - v.x) > 100, 'optional fields in two columns').toBeTruthy();
    } else {
      expect(cat.y).toBeGreaterThan(loc.y + loc.height - 1);
    }
    await app.noOverflow();
    // focus the last field of the form: it must end up above the sticky Save bar
    if (!(await page.locator('.more-fields').getAttribute('open') !== null)) await page.locator('.more-fields summary').click();
    await page.locator('#barcode').focus();
    await page.locator('#barcode').scrollIntoViewIfNeeded();
    const bar = (await page.locator('.actionbar-form').boundingBox())!;
    const f = (await page.locator('#barcode').boundingBox())!;
    expect(f.y + f.height, 'focused field above the Save bar').toBeLessThanOrEqual(bar.y + 1);
    // scrolled to the very end, nothing is hidden under the bar
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    const bar2 = (await page.locator('.actionbar-form').boundingBox())!;
    const last = (await page.locator('.more-fields').boundingBox())!;
    expect(last.y + last.height).toBeLessThanOrEqual(bar2.y + 1);
  });

  test('stocktake of an empty location offers to add items there', async ({ app, page }) => {
    await app.go('/locations');
    await page.locator('#addloc input[name=name]').fill('Empty drawer');
    await page.locator('#addloc button.btn-primary').click();
    await app.go('/stocktake');
    await page.locator('#stk-loc').selectOption({ label: 'Empty drawer (0)' });
    await page.locator('#stk-go').click();
    await expect(page.locator('#stk-none')).toContainText('add what is here as new items');
    const id = app.sql<{ id: number }>("SELECT id FROM locations WHERE name = 'Empty drawer'")[0].id;
    await expect(page.locator('#stk-addhere')).toHaveAttribute('href', `/new?loc=${id}`);
    await page.locator('#stk-addhere').click();
    await expect(page.locator('#locsel')).toHaveValue(String(id));
  });
});
