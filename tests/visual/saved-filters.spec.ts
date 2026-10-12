import { test, expect } from './fixtures';

test.describe('saved filters', () => {
  test('save, apply, rename and delete a filter combination @smoke', async ({ app, page }) => {
    await app.go('/');
    await expect(page.locator('#savefilter')).toBeHidden();       // nothing to save on the plain list
    const garage = app.sql<{ id: number }>("SELECT id FROM locations WHERE name = 'Garage shelf'")[0];
    const inGarage = Number(app.sql(`SELECT COUNT(*) AS n FROM items WHERE location_id = ${garage.id}`)[0].n);

    await page.locator('#filter-open').click();
    await page.locator('#loc').selectOption(String(garage.id));
    await page.locator('#sort').selectOption('name');
    await page.locator('#filter-apply').click();
    await expect(page).toHaveURL(/loc=\d+/);
    await expect(page.locator('#savefilter')).toBeVisible();
    await page.locator('#savefilter-open').click();
    await expect(page.locator('#sf-name')).toBeFocused();
    await page.locator('#sf-name').fill('Garage A–Z');
    await app.tapTargets();
    await page.locator('#sf-save').click();
    await expect(page.locator('.toast').last()).toContainText('Saved filter “Garage A–Z”');
    const chip = page.locator('#saved-chips a.sfchip', { hasText: 'Garage A–Z' });
    await expect(chip).toHaveClass(/is-on/);
    await app.noOverflow();

    // a duplicate name is refused
    await page.locator('#savefilter-open').click();
    await page.locator('#sf-name').fill('garage a–z');
    await page.locator('#sf-save').click();
    await expect(page.locator('.toast').last()).toContainText('already exists');

    // clearing the filters un-highlights the chip; tapping it applies the combination again
    await page.locator('#filter-open').click();
    await page.locator('#filter-clear').click();
    await expect(chip).not.toHaveClass(/is-on/);
    await expect(page.locator('#savefilter')).toBeHidden();
    await chip.click();
    await expect(page).toHaveURL(new RegExp(`loc=${garage.id}&sort=name`));
    await expect(page.locator('#grid .card')).toHaveCount(inGarage);
    await expect(page.locator('#saved-chips a.sfchip.is-on')).toHaveText('Garage A–Z');

    // manage: rename, then delete
    await page.locator('#saved-chips a.sfchip-manage').click();
    await expect(page).toHaveURL(/\/filters$/);
    await expect(page.locator('#sflist')).toContainText('Garage shelf · sorted by Name');
    await app.noOverflow();
    const row = page.locator('#sflist li').first();
    await row.locator('summary').click();
    await row.locator('input[name=name]').fill('Shelf');
    await row.getByRole('button', { name: 'Rename' }).click();
    await expect(page.locator('.toast')).toContainText('Filter renamed');
    await expect(page.locator('#sflist')).toContainText('Shelf');
    page.once('dialog', d => d.accept());
    await page.locator('#sflist li').first().locator('summary').click();
    await page.locator('#sflist li').first().getByRole('button', { name: 'Delete' }).click();
    await expect(page.locator('.toast')).toContainText('Filter deleted');
    await expect(page.locator('#sflist')).toHaveCount(0);
    await app.go('/');
    await expect(page.locator('#saved-chips a')).toHaveCount(0);
  });
});
