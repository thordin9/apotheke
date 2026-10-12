import { test, expect } from './fixtures';

test.describe('item page', () => {
  test('detail page: photo gallery with arrows, counter and thumbnails @smoke', async ({ app, page }) => {
    await app.go('/item/1');
    await expect(page.locator('.item-name')).toHaveText('Cordless drill');
    await expect(page.locator('.item-meta .chip-location')).toContainText('Garage shelf');
    await expect(page.locator('#gal-count')).toHaveText('1 / 3');
    const src0 = await page.locator('#hero-img').getAttribute('src');
    await page.locator('#gal-next').click();
    await expect(page.locator('#gal-count')).toHaveText('2 / 3');
    await expect(page).toHaveURL(/\?p=1$/);
    expect(await page.locator('#hero-img').getAttribute('src')).not.toBe(src0);
    await page.locator('#gal-prev').click();
    await page.locator('#gal-prev').click();
    await expect(page.locator('#gal-count'), 'wraps around').toHaveText('3 / 3');
    await page.locator('.thumbs a[data-i="0"]').click();
    await expect(page.locator('#gal-count')).toHaveText('1 / 3');
    if (!app.mobile) {
      await page.keyboard.press('ArrowRight');
      await expect(page.locator('#gal-count')).toHaveText('2 / 3');
    }
    await app.go('/item/1?p=2');
    await expect(page.locator('#gal-count'), '?p= works without JS').toHaveText('3 / 3');
    await expect(page.locator('#facts')).toContainText('189.90 EUR');
    await expect(page.locator('.tags a')).toHaveText(['#Garage', '#Power tools']);
    await page.locator('.tags a', { hasText: '#Garage' }).click();
    await expect(page).toHaveURL(/[?&]tag=\d+/);
    await expect(page.locator('#grid .card')).toHaveCount(3);
    await app.go('/item/1');
    await app.noOverflow();
    await app.tapTargets();
  });

  test('photos: add several, reorder, set cover, delete', async ({ app, page }) => {
    await app.go('/item/2');
    await expect(page.locator('#gal-count')).toHaveCount(0);   // one photo: no arrows
    await page.locator('#photos > summary').click();
    await expect(page.locator('.pm')).toHaveCount(1);
    await Promise.all([page.waitForURL(/\?p=\d+#photos$/), page.locator('#photoadd-lib').setInputFiles([app.image('a.jpg', [10, 160, 90]), app.image('b.png', [230, 200, 20], true)])]);
    await expect(page.locator('#gal-count')).toHaveText('3 / 3');
    const files = () => app.sql<{ file: string }>('SELECT file FROM item_photos WHERE item_id = 2 ORDER BY position').map(r => r.file);
    const [f0, f1, f2] = files();
    await page.locator('#photos > summary').click();
    await expect(page.locator('.pm')).toHaveCount(3);
    await expect(page.locator('.pm').first()).toHaveClass(/is-cover/);
    // move the last photo left, then make it the cover
    await page.getByRole('button', { name: 'Move photo 3 left' }).click();
    await expect.poll(files).toEqual([f0, f2, f1]);
    await page.locator('#photos > summary').click();
    await page.getByRole('button', { name: 'Make photo 2 the cover' }).click();
    await expect.poll(files).toEqual([f2, f0, f1]);
    expect(app.sql<{ image: string }>('SELECT image FROM items WHERE id = 2')[0].image, 'cover = list thumbnail').toBe(f2);
    await app.go('/');
    await expect(page.locator('.card[data-id="2"] img')).toHaveAttribute('src', `/media/thumb/${f2}`);
    await app.go('/item/2');
    await page.locator('#photos > summary').click();
    await page.getByRole('button', { name: 'Move photo 2 right' }).click();
    await expect.poll(files).toEqual([f2, f1, f0]);
    // delete with confirm (dismiss first, then accept)
    await page.locator('#photos > summary').click();
    page.once('dialog', d => d.dismiss());
    await page.getByRole('button', { name: 'Delete photo 3' }).click();
    expect(files()).toHaveLength(3);
    page.once('dialog', d => { expect(d.message()).toBe('Delete this photo?'); d.accept(); });
    await page.getByRole('button', { name: 'Delete photo 3' }).click();
    await expect.poll(files).toEqual([f2, f1]);
    await expect(page.locator('#gal-count')).toHaveText(/\/ 2$/);
    const img = await page.request.get(`/media/full/${f0}`);
    expect(img.status(), 'deleted file is gone').toBe(404);
  });

  test('edit: change fields, replace and remove the cover photo', async ({ app, page }) => {
    await app.go('/item/3');
    await page.locator('.bar a[href="/item/3/edit"]').click();
    await expect(page.locator('#name')).toHaveValue('Camping stove');
    await expect(page.locator('#locsel')).toHaveValue('3');
    await expect(page.locator('#value')).toHaveValue('45.00');
    const old = app.sql<{ image: string }>('SELECT image FROM items WHERE id = 3')[0].image;
    await page.locator('input[name=photo2]').setInputFiles(app.image('new.jpg', [120, 40, 160]));
    await page.locator('#name').fill('Camping stove (gas)');
    await page.locator('#locsel').selectOption('4');
    await page.locator('#save').click();
    await expect(page).toHaveURL(/\/item\/3$/);
    await expect(page.locator('.item-name')).toHaveText('Camping stove (gas)');
    await expect(page.locator('.item-meta .chip-location')).toContainText('box 2');
    const now = app.sql<{ image: string }>('SELECT image FROM items WHERE id = 3')[0].image;
    expect(now).not.toBe(old);
    expect((await page.request.get(`/media/thumb/${old}`)).status(), 'replaced photo deleted').toBe(404);
    await app.go('/item/3/edit');
    await page.locator('#rmimg').check();
    await page.locator('#save').click();
    await expect(page).toHaveURL(/\/item\/3$/);
    await expect(page.locator('.hero-empty')).toBeVisible();
    expect(app.sql('SELECT image FROM items WHERE id = 3')[0]).toEqual({ image: null });
  });

  test('quantity +/- on the item page', async ({ app, page }) => {
    await app.go('/item/6');
    const q = page.locator('.qty-lg .qval');
    const tap = (d: string) => Promise.all([page.waitForResponse(r => r.url().endsWith('/item/6/qty')), page.locator(`.qty-lg [data-delta="${d}"]`).click()]);
    await tap('1'); await tap('1');
    await expect(q).toHaveText('3');
    await tap('-1'); await tap('-1'); await tap('-1');
    await expect(q).toHaveText('0');
    await expect(page.locator('.qty-lg [data-delta="-1"]'), 'cannot go below 0').toBeDisabled();
    expect(app.sql('SELECT quantity FROM items WHERE id = 6')[0]).toEqual({ quantity: 0 });
  });

  test('delete asks for confirmation @smoke', async ({ app, page }) => {
    await app.go('/item/7');
    page.once('dialog', d => d.dismiss());
    await page.getByRole('button', { name: 'Delete item' }).click();
    await expect(page).toHaveURL(/\/item\/7$/);
    page.once('dialog', d => { expect(d.message()).toContain('Delete “Screwdriver set”?'); d.accept(); });
    await page.getByRole('button', { name: 'Delete item' }).click();
    await expect(page).toHaveURL(/\/\?deleted=1$|\/$/);
    await expect(page.locator('.toast')).toHaveText('Item deleted');
    await expect(page.locator('#grid .card')).toHaveCount(7);
    expect((await page.request.get('/item/7')).status()).toBe(404);
  });
});
