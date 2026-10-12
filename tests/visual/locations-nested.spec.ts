import { test, expect } from './fixtures';

const id = (app: { sql: (q: string) => Record<string, unknown>[] }, name: string) =>
  Number(app.sql(`SELECT id FROM locations WHERE name = '${name}'`)[0].id);

test.describe('nested locations', () => {
  test('build a tree, filter by a parent, trail on the item page, move and delete @smoke', async ({ app, page }) => {
    await app.go('/locations');
    await page.locator('#addloc input[name=name]').fill('Garage');
    await page.locator('#addloc button').click();
    await expect(page.locator('.toast')).toContainText('Location added');
    const garage = id(app, 'Garage');
    const shelf = id(app, 'Garage shelf');

    // put "Garage shelf" inside "Garage"
    await page.locator(`#loc-${shelf} details.loc-more > summary`).click();
    await page.locator(`#loc-${shelf} .loc-parent select`).selectOption(String(garage));
    await page.locator(`#loc-${shelf} .loc-parent button`).click();
    await expect(page.locator('.toast')).toContainText('Location moved');
    await expect(page.locator(`#loc-${shelf}`)).toHaveAttribute('data-depth', '1');

    // add a box straight into the shelf
    await page.locator('#addloc input[name=name]').fill('Toolbox');
    await page.locator('#addloc-parent').selectOption(String(shelf));
    await page.locator('#addloc button').click();
    const box = id(app, 'Toolbox');
    await expect(page.locator(`#loc-${box}`)).toHaveAttribute('data-depth', '2');
    // tree order: Garage, then its shelf, then the box
    const order = await page.locator('.loclist > li').evaluateAll(els => els.map(e => e.id));
    expect(order.indexOf(`loc-${garage}`)).toBe(order.indexOf(`loc-${shelf}`) - 1);
    expect(order.indexOf(`loc-${box}`)).toBe(order.indexOf(`loc-${shelf}`) + 1);
    // a location can't be moved into its own branch: those options aren't offered
    await page.locator(`#loc-${garage} details.loc-more > summary`).click();
    const opts = await page.locator(`#loc-${garage} .loc-parent option`).evaluateAll(els => els.map(e => (e as HTMLOptionElement).value));
    expect(opts).not.toContain(String(shelf));
    expect(opts).not.toContain(String(box));
    await app.noOverflow();
    await app.tapTargets();

    // the parent's count and filter include everything below it
    const inShelf = Number(app.sql(`SELECT COUNT(*) AS n FROM items WHERE location_id = ${shelf}`)[0].n);
    expect(inShelf).toBeGreaterThan(0);
    await expect(page.locator(`#loc-${garage} .loc-count`)).toHaveText(`${inShelf} item${inShelf === 1 ? '' : 's'}`);
    await app.go('/');
    await page.locator('#filter-open').click();
    await expect(page.locator('#loc option', { hasText: '↳ Garage shelf' })).toHaveCount(1);
    await page.locator('#loc').selectOption(String(garage));
    await page.locator('#filter-apply').click();
    await expect(page.locator('#grid .card')).toHaveCount(inShelf);
    await expect(page.locator('#achips')).toContainText('Garage');

    // item page shows the path
    await page.locator('#grid .card a').first().click();
    await expect(page.locator('#loctrail a')).toHaveText(['Garage', 'Garage shelf']);
    await app.noOverflow();
    await app.tapTargets();
    await page.locator('#loctrail a').first().click();
    await expect(page).toHaveURL(new RegExp(`/\\?loc=${garage}$`));

    // deleting the shelf moves the box up into the garage
    await app.go(`/locations/${shelf}/delete`);
    await expect(page.locator('#del-children')).toContainText('move up one level into “Garage”');
    await page.locator('input[name=action][value=move]').check();
    await page.locator('select[name=target]').selectOption(String(box));
    await page.getByRole('button', { name: 'Delete' }).click();
    await expect(page.locator(`#loc-${box}`)).toHaveAttribute('data-depth', '1');
    expect(Number(app.sql(`SELECT COUNT(*) AS n FROM items WHERE location_id = ${box}`)[0].n)).toBe(inShelf);
  });

  test('search finds items in sub-locations; overview rolls counts up', async ({ app, page }) => {
    app.sql("UPDATE locations SET parent_id = (SELECT id FROM locations WHERE name = 'Attic') WHERE name IN ('box 2', 'box 10')");
    const attic = id(app, 'Attic');
    const expected = Number(app.sql(`SELECT COUNT(*) AS n FROM items i JOIN locations l ON l.id = i.location_id
      WHERE l.name IN ('Attic', 'box 2', 'box 10')`)[0].n);
    const direct = Number(app.sql(`SELECT COUNT(*) AS n FROM items WHERE location_id = ${attic}`)[0].n);
    expect(expected).toBeGreaterThan(direct);
    await app.go('/');
    await page.locator('#q').fill('attic');
    await expect(page.locator('#grid .card')).toHaveCount(expected);
    await page.locator('#q').fill('');
    await expect(page.locator('#grid .card')).toHaveCount(8);
    // overview: Attic's bar includes box 2 and box 10, which are not listed on their own
    await expect(page.locator(`#stats .hbars a[data-loc="${attic}"]`)).toBeVisible();
    const bar = page.locator('#stats .st-location .hbars li', { has: page.locator(`a[data-loc="${attic}"]`) });
    await expect(bar.locator('.hb-n')).toHaveText(String(expected));
    await expect(page.locator('#stats .st-location .hbars a', { hasText: /^box (2|10)$/ })).toHaveCount(0);
    await expect(page.locator('#st-rollup')).toBeVisible();
    await app.noOverflow();
    await bar.locator('a').click();
    await expect(page.locator('#grid .card')).toHaveCount(expected);
  });
});
