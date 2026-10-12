import { test, expect } from './fixtures';

test.describe('bulk actions', () => {
  test('select mode: pick cards, move, tag, select all, delete with confirm', async ({ app, page }) => {
    await app.go('/');
    await page.locator('#select-toggle').click();
    await expect(page).toHaveURL(/[?&]select=1/);
    await expect(page.locator('#fab')).toHaveCount(0);
    await expect(page.locator('#selcount')).toHaveText('Select items');
    await expect(page.locator('#bulk-move')).toBeDisabled();
    // tapping the photo selects instead of navigating
    await page.locator('.card[data-id="1"] .card-img').click();
    await page.locator('.card[data-id="3"] .card-title').click();
    await expect(page).toHaveURL(/[?&]select=1/);
    await expect(page.locator('#selcount')).toHaveText('2 selected');
    await expect(page.locator('.card.is-picked')).toHaveCount(2);
    await app.noOverflow();
    await app.tapTargets();

    await page.locator('#bulk-open').click();
    await expect(page.locator('#bulk-move')).toBeVisible();
    await app.tapTargets();
    await page.locator('#bulk-loc').selectOption({ label: 'Κουτί 5' });
    await page.locator('#bulk-move').click();
    await expect(page.locator('.toast')).toHaveText('Moved 2 items');
    await expect(page).toHaveURL(/[?&]select=1/);
    const loc = (id: number) => app.sql<{ name: string }>(`SELECT l.name FROM items i JOIN locations l ON l.id = i.location_id WHERE i.id = ${id}`)[0]?.name;
    expect([loc(1), loc(3)]).toEqual(['Κουτί 5', 'Κουτί 5']);
    expect(app.sql<{ n: number }>("SELECT COUNT(*) AS n FROM activity WHERE action = 'moved' AND item_id IN (1, 3)")[0].n).toBe(2);

    // selection survives an instant search; tags are added to everything picked
    const sel5 = page.locator('.card[data-id="5"] .sel');
    await sel5.evaluate(e => e.scrollIntoView({ block: 'center', behavior: 'instant' }));
    await sel5.click();
    await page.locator('#q').fill('pan');
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await expect(page.locator('.card[data-id="5"]')).toHaveClass(/is-picked/);
    await page.locator('#q').fill('');
    await expect(page.locator('#grid .card')).toHaveCount(8);
    await page.locator('.card[data-id="1"] .card-img').click();
    await expect(page.locator('#selcount')).toHaveText('2 selected');   // 3 and 5
    await page.locator('#bulk-open').click();
    await page.locator('#bulk-tags').fill('Winter');
    await page.locator('#bulk-tag-add').click();
    await expect(page.locator('.toast')).toHaveText('Tagged 2 items');
    expect(app.sql<{ n: number }>("SELECT COUNT(*) AS n FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE t.name = 'Winter'")[0].n).toBe(2);

    await page.locator('#sel-all').click();
    await expect(page.locator('#selcount')).toHaveText('8 selected');
    await expect(page.locator('#sel-all')).toHaveText('None');
    await page.locator('#sel-all').click();
    await expect(page.locator('#selcount')).toHaveText('Select items');

    await page.locator('.card[data-id="6"] .card-img').click();
    await page.locator('.card[data-id="7"] .card-img').click();
    await page.locator('#bulk-open').click();
    await page.locator('#bulk-delete').click();
    await expect(page.locator('.item-name')).toHaveText('Delete 2 items?');
    await expect(page.locator('#bulklist li')).toHaveCount(2);
    expect(app.sql<{ n: number }>('SELECT COUNT(*) AS n FROM items')[0].n, 'nothing deleted yet').toBe(8);
    await app.noOverflow();
    await page.locator('#bulk-confirm').click();
    await expect(page.locator('.toast')).toHaveText('Deleted 2 items');
    await expect(page.locator('#grid .card')).toHaveCount(6);
    await page.locator('#select-toggle').click();
    await expect(page).not.toHaveURL(/select=1/);
    await expect(page.locator('#fab')).toBeVisible();
  });
});
