import { test, expect } from './fixtures';

test.describe('bulk QR labels', () => {
  test('print labels for a selection; sizes from the Control Panel @smoke', async ({ app, page }) => {
    await app.go('/?select=1');
    await page.locator('.card[data-id="1"] .card-title').click();
    await page.locator('.card[data-id="2"] .card-title').click();
    await page.locator('#bulk-open').click();
    await expect(page.locator('#bulk-labels')).toBeEnabled();
    await app.tapTargets();
    await page.locator('#bulk-labels').click();
    await expect(page).toHaveURL(/\/labels\?ids=(1,2|2,1)$/);
    await expect(page.locator('.label')).toHaveCount(2);
    await expect(page.locator('.labels-intro h2')).toHaveText('2 items selected');
    await expect(page.locator('.label').first()).toHaveAttribute('data-url', /\/item\/[12]$/);
    await app.noOverflow();
    await app.tapTargets();
    // print layout uses the configured millimetre grid
    await page.emulateMedia({ media: 'print' });
    const box = await page.locator('.label').first().boundingBox();
    expect(Math.round(box!.width)).toBe(Math.round(60 * 96 / 25.4));
    expect(Math.round(box!.height)).toBe(Math.round(70 * 96 / 25.4));
    await expect(page.locator('header.top')).toBeHidden();
    await page.emulateMedia({ media: 'screen' });

    await app.go('/settings/labels');
    await page.locator('#s-label_width_mm').fill('40');
    await page.locator('#s-label_height_mm').fill('30');
    await page.locator('#s-label_columns').fill('4');
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toContainText('Settings saved');
    await app.go('/labels?ids=1,2');
    await expect(page.locator('.labels-size')).toContainText('40 × 30 mm, 4 per row');
    await page.emulateMedia({ media: 'print' });
    const b2 = await page.locator('.label').first().boundingBox();
    expect(Math.round(b2!.width)).toBe(Math.round(40 * 96 / 25.4));
  });

  test('a location with its sub-locations', async ({ app, page }) => {
    await app.go('/locations');
    const csrf = await page.locator('input[name=csrf]').first().inputValue();
    const mk = async (name: string, parent = '') => (await (await page.request.post('/locations', { headers: { Accept: 'application/json' }, form: { csrf, name, parent } })).json()).id as number;
    const parent = await mk('Outer shed');
    await mk('Inner box', String(parent));
    await app.go(`/labels?loc=${parent}`);
    await page.locator('.label-opts a', { hasText: 'Location + sub-locations' }).click();
    await expect(page).toHaveURL(/sub=1/);
    await expect(page.locator('.label')).toHaveCount(2);
    await expect(page.locator('.label figcaption b').last()).toHaveText('Inner box');
  });
});
