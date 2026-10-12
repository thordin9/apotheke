import { test, expect } from './fixtures';

test.describe('barcodes', () => {
  test('manual lookup: unknown code → prefilled new item, then → the item @smoke', async ({ app, page }) => {
    const code = '4006381333931';
    await app.go('/barcode');
    // the Scan button only appears where the BarcodeDetector API exists; otherwise the code is typed
    const hasDetector = await page.evaluate(() => 'BarcodeDetector' in window);
    await expect(page.locator('button[data-scan="#code"]')).toBeVisible({ visible: hasDetector });
    await app.noOverflow();
    await app.tapTargets();
    await page.locator('#code').fill(` ${code} `);
    await page.locator('#code-go').click();
    await expect(page).toHaveURL(new RegExp(`/new\\?barcode=${code}$`));
    await expect(page.locator('#barcode')).toHaveValue(code);
    await expect(page.locator('.more-fields')).toHaveAttribute('open', '');
    await page.locator('#name').fill('Pencils HB');
    await page.locator('#save').click();
    await expect(page.locator('.toast').last()).toContainText('Saved “Pencils HB”');
    const id = app.sql<{ id: number }>(`SELECT id FROM items WHERE barcode = '${code}'`)[0].id;

    // the same code now opens the item
    await app.go('/barcode');
    await page.locator('#code').fill(code);
    await page.locator('#code').press('Enter');
    await expect(page).toHaveURL(new RegExp(`/item/${id}$`));
    await expect(page.locator('#facts')).toContainText(`Barcode${code}`);

    // and search finds it by (part of) the barcode
    await app.go('/');
    await page.locator('#q').fill('333931');
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await expect(page.locator('#grid .card').first()).toHaveAttribute('data-id', String(id));
  });

  test('barcode field on the edit form', async ({ app, page }) => {
    const it = app.sql<{ id: number }>('SELECT id FROM items ORDER BY id LIMIT 1')[0];
    await app.go(`/item/${it.id}/edit`);
    await page.locator('.more-fields').evaluate(d => { (d as HTMLDetailsElement).open = true; });
    await page.locator('#barcode').fill('ABC 123');
    await page.locator('#save').click();
    await expect(page.locator('#facts')).toContainText('BarcodeABC123');
    await app.go('/barcode/ABC123');
    await expect(page).toHaveURL(new RegExp(`/item/${it.id}$`));
  });
});
