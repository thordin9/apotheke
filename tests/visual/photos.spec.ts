import { test, expect } from './fixtures';

type P = { id: number; file: string; version: number };
const photos = (app: any): P[] => app.sql("SELECT id, file, version FROM item_photos WHERE item_id = 1 ORDER BY position");

test.describe('photo editing', () => {
  test('rotate + drag a crop on the canvas, save a new version @smoke', async ({ app, page }) => {
    const before = photos(app);
    await app.go('/item/1');
    await page.locator('#photos > summary').click();
    await page.locator('#pm-edit-0').click();
    await expect(page).toHaveURL(new RegExp(`/item/1/photos/${before[0].id}/edit$`));
    await expect(page.locator('#pe-tools')).toBeVisible();
    await expect(page.locator('#pe-canvas')).toBeVisible();
    await app.noOverflow();
    await app.tapTargets();
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    const [manual, bar] = await page.evaluate(() => [document.querySelector('#pe-manual')!.getBoundingClientRect().bottom, document.querySelector('.actionbar')!.getBoundingClientRect().top]);
    expect(manual, 'the exact-values box is not covered by the action bar').toBeLessThanOrEqual(bar);
    await page.locator('#pe-rr').click();
    await expect(page.locator('#pe-rotate')).toHaveValue('90');
    await page.locator('#pe-canvas').evaluate(e => e.scrollIntoView({ block: 'center' }));
    const box = (await page.locator('#pe-canvas').boundingBox())!;
    await page.mouse.move(box.x + box.width * 0.2, box.y + box.height * 0.2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.6, box.y + box.height * 0.5, { steps: 6 });
    await page.mouse.up();
    const w = Number(await page.locator('#pe-w').inputValue());
    expect(w).toBeGreaterThan(20);
    expect(w).toBeLessThan(60);
    await expect(page.locator('#pe-info')).toContainText('px');
    await page.locator('#pe-save').click();
    await expect(page).toHaveURL(/\/item\/1\?p=0/);
    await expect(page.locator('.toast')).toContainText('Photo saved');
    const after = photos(app);
    expect(after[0].version).toBe(2);
    expect(after[0].file).not.toBe(before[0].file);
    await expect(page.locator('#hero-img')).toHaveAttribute('src', new RegExp(after[0].file.replace('.jpg', '')));
  });

  test('works without JavaScript: manual fields', async ({ browser, app }) => {
    const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL: app.base });
    const page = await ctx.newPage();
    const id = photos(app)[1].id;
    await page.goto(`/item/1/photos/${id}/edit`);
    await expect(page.locator('#pe-tools')).toBeHidden();
    await expect(page.locator('#pe-img')).toBeVisible();
    await page.locator('#pe-manual summary').click();
    await page.locator('#pe-rotate').selectOption('180');
    await page.locator('#pe-h').fill('50');
    await page.locator('#pe-save').click();
    await expect(page).toHaveURL(/\/item\/1\?p=1&edited=1/);
    expect(photos(app)[1].version).toBe(2);
    await ctx.close();
  });

  test('drag to reorder; first photo becomes the cover', async ({ app, page }) => {
    const before = photos(app).map(p => p.id);
    await app.go('/item/1');
    await page.locator('#photos > summary').click();
    const handles = page.locator('#pm-list .pm-drag');
    await expect(handles).toHaveCount(3);
    await app.tapTargets();
    // keep the dragged handle clear of the sticky action bar on phones
    await page.locator('#pm-list').evaluate(e => window.scrollBy(0, e.getBoundingClientRect().top - 80));
    const first = (await page.locator('#pm-list .pm').nth(0).boundingBox())!;
    const h = (await handles.nth(1).boundingBox())!; // photo 2 → first (on a 375px phone the grid is one column)
    await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
    await page.mouse.down();
    await page.mouse.move(first.x + 10, first.y + first.height / 2, { steps: 10 });
    await page.mouse.up();
    await expect(page.locator('.toast')).toContainText('Photo order saved');
    const after = photos(app).map(p => p.id);
    expect(after).toEqual([before[1], before[0], before[2]]);
    expect(app.sql('SELECT image FROM items WHERE id = 1')[0].image).toBe(photos(app)[0].file);
  });
});
