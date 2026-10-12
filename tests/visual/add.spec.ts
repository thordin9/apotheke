import { test, expect } from './fixtures';

test.describe('add item', () => {
  test('photo from the camera input, defaults, stepper, tags and custom fields @smoke', async ({ app, page }) => {
    await app.go('/');
    await page.locator('#fab').click();
    await expect(page).toHaveURL(/\/new$/);
    // defaults are preselected (★)
    await expect(page.locator('#locsel')).toHaveValue('1');
    await expect(page.locator('#catsel')).toHaveValue('1');
    await expect(page.locator('#locsel option:checked')).toHaveText('Garage shelf ★');
    // camera input through the native file chooser
    const chooser = page.waitForEvent('filechooser');
    await page.locator('.photo-take').click();
    const fc = await chooser;
    expect(fc.isMultiple()).toBe(false);
    await fc.setFiles(app.image('camera.jpg', [200, 60, 60]));
    await expect(page.locator('#preview')).toBeVisible();
    await expect(page.locator('#pstatus')).toContainText('KB');
    await expect(page.locator('.photo-take span')).toHaveText('Retake photo');
    await page.locator('#name').fill('Angle grinder');
    // quantity stepper
    const qty = page.locator('input[name=quantity]');
    await expect(qty).toHaveValue('1');
    await page.locator('.stepper [data-step="1"]').click();
    await page.locator('.stepper [data-step="1"]').click();
    await page.locator('.stepper [data-step="-1"]').click();
    await expect(qty).toHaveValue('2');
    for (let i = 0; i < 4; i++) await page.locator('.stepper [data-step="-1"]').click();
    await expect(qty, 'never below 0').toHaveValue('0');
    await page.locator('.stepper [data-step="1"]').click();
    // tags as chips with suggestions
    await expect(page.locator('#tag-list option')).toHaveCount(3);
    await page.locator('#tag-entry').fill('Garage');
    await page.keyboard.press('Enter');
    await page.locator('#tag-entry').pressSequentially('Workshop,');
    await expect(page.locator('#tagbox .tagchip')).toHaveText(['#Garage', '#Workshop']);
    await page.locator('#tag-entry').fill('garage');
    await page.keyboard.press('Enter');
    await expect(page.locator('#tagbox .tagchip'), 'duplicates ignored').toHaveCount(2);
    await page.locator('#tagbox .tagchip[data-tag="Workshop"]').click();
    await expect(page.locator('#tags')).toHaveValue('Garage');
    // custom fields
    await page.locator('.more-fields > summary').click();
    await page.locator('#value').fill('79,90');
    await page.locator('#purchase_date').fill('2025-11-02');
    await page.locator('#serial').fill('GA-9020');
    await page.locator('textarea[name=notes]').fill('125 mm disc');
    await app.noOverflow();
    await app.tapTargets();
    await page.locator('#save').click();
    await expect(page).toHaveURL(/\/\?saved=\d+$|\/$/);
    await expect(page.locator('.toast')).toHaveText('Saved “Angle grinder”');
    const card = page.locator('#grid .card').filter({ hasText: 'Angle grinder' });
    await expect(card.locator('img')).toHaveCount(1);
    await expect(card.locator('.chip-location')).toHaveText('Garage shelf');
    await card.locator('.card-title').click();
    await expect(page.locator('.item-name')).toHaveText('Angle grinder');
    await expect(page.locator('.qty-lg .qval')).toHaveText('1');
    await expect(page.locator('.tags')).toHaveText('#Garage');
    await expect(page.locator('#facts')).toContainText('79.90 EUR');
    await expect(page.locator('#facts')).toContainText('2025-11-02');
    await expect(page.locator('#facts')).toContainText('GA-9020');
    await expect(page.locator('.notes')).toHaveText('125 mm disc');
    await expect(page.locator('#gallery img')).toHaveCount(1);
  });

  test('library input, inline new location + category, "Save & new" keeps them', async ({ app, page }) => {
    await app.go('/new');
    await page.locator('input[name=photo2]').setInputFiles(app.image('library.png', [40, 120, 200], true));
    await expect(page.locator('#preview')).toBeVisible();
    await page.locator('#name').fill('Garden hose');
    await page.locator('#locadd').click();
    await expect(page.locator('#newloc')).toBeVisible();
    await page.locator('#newlocname').fill('Garden shed');
    await page.locator('#newlocok').click();
    await expect(page.locator('.toast')).toHaveText('Added “Garden shed”');
    await expect(page.locator('#locsel option:checked')).toHaveText('Garden shed');
    await expect(page.locator('#newloc')).toBeHidden();
    // existing name (case/accent-insensitive) is selected, not duplicated
    await page.locator('#catsel').selectOption('__new');
    await page.locator('#newcatname').fill('camping');
    await page.keyboard.press('Enter');
    await expect(page.locator('.toast')).toHaveText('“Camping” already exists – selected');
    await expect(page.locator('#catsel')).toHaveValue('3');
    await page.locator('#catadd').click();
    await page.locator('#newcatname').fill('Garden');
    await page.locator('#newcatok').click();
    await expect(page.locator('#catsel option:checked')).toHaveText('Garden');
    await page.locator('#save-another').click();
    await expect(page).toHaveURL(/\/new\?loc=\d+&cat=\d+/);
    await expect(page.locator('.toast')).toHaveText('Saved “Garden hose”');
    await expect(page.locator('#locsel option:checked'), 'location kept for the next item').toHaveText('Garden shed');
    await expect(page.locator('#catsel option:checked')).toHaveText('Garden');
    await expect(page.locator('#name')).toHaveValue('');
    await page.locator('#name').fill('Watering can');
    await page.locator('#save').click();
    await expect(page.locator('.toast')).toHaveText('Saved “Watering can”');
    const rows = app.sql<{ name: string; l: string; c: string; image: string | null }>(
      "SELECT i.name, l.name AS l, c.name AS c, i.image FROM items i JOIN locations l ON l.id = i.location_id JOIN categories c ON c.id = i.category_id WHERE l.name = 'Garden shed' ORDER BY i.id");
    expect(rows.map(r => [r.name, r.c, !!r.image])).toEqual([['Garden hose', 'Garden', true], ['Watering can', 'Garden', false]]);
  });

  test('validation errors are shown inline', async ({ app, page }) => {
    await app.go('/new');
    await page.locator('#save').click();
    await expect(page.locator('#err')).toHaveText('Please give the item a name.');
    await page.locator('#name').fill('X');
    await page.locator('#locsel').selectOption('__new');
    await page.locator('#save').click();
    await expect(page.locator('#err')).toContainText('Type a name for the new location');
    await page.locator('#locsel').selectOption('1');
    await page.locator('.more-fields > summary').click();
    await page.locator('#value').fill('abc');
    await page.locator('#save').click();
    await expect(page.locator('#err')).toHaveText('Value must be a number like 49.90.');
    await page.locator('input[name=photo2]').setInputFiles({ name: 'evil.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('<?php echo 1; ?>') });
    await page.locator('#value').fill('');
    await page.locator('#save').click();
    await expect(page.locator('#err')).toContainText('Unsupported image');
    expect(app.sql("SELECT id FROM items WHERE name = 'X'")).toEqual([]);
  });
});
