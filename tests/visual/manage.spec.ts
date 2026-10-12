import { test, expect } from './fixtures';

for (const kind of ['locations', 'categories'] as const) {
  test.describe(`manage ${kind}`, () => {
    const param = kind === 'locations' ? 'loc' : 'cat';
    test(`add, rename, default, delete-with-move${kind === 'locations' ? ' @smoke' : ''}`, async ({ app, page }) => {
      await app.go('/');
      await app.menu();
      await page.locator(`#menu a[href="/${kind}"]`).click();
      await expect(page).toHaveURL(new RegExp(`/${kind}$`));
      await expect(page.locator('.tabs a[aria-current=page]')).toContainText(kind === 'locations' ? 'Locations' : 'Categories');
      const count = await page.locator('.loclist > li').count();
      // add
      await page.locator('#addloc input[name=name]').fill('  Basement   rack ');
      await page.locator('#addloc button').click();
      await expect(page.locator('.loclist > li')).toHaveCount(count + 1);
      const row = page.locator('.loclist > li').filter({ has: page.locator('.loc-name', { hasText: 'Basement rack' }) });
      await expect(row.locator('.loc-count')).toHaveText('0 items');
      // duplicate (case-insensitive) is rejected
      await page.locator('#addloc input[name=name]').fill('BASEMENT RACK');
      await page.locator('#addloc button').click();
      await expect(page.locator('.loclist > li')).toHaveCount(count + 1);
      // rename
      await row.locator('.loc-more > summary').click();
      await row.locator('.loc-rename input[name=name]').fill('Basement shelf');
      await row.locator('.loc-rename button').click();
      const renamed = page.locator('.loclist > li').filter({ has: page.locator('.loc-name', { hasText: 'Basement shelf' }) });
      await expect(renamed).toHaveCount(1);
      // set as default with the star; only one default
      await renamed.locator('.loc-star').click();
      await expect(page.locator('.loclist > li.is-default')).toHaveCount(1);
      await expect(page.locator('.loclist > li.is-default .loc-name')).toContainText('Basement shelf');
      await app.go('/new');
      await expect(page.locator(`#${param}sel option:checked`), 'new default preselected on the form').toHaveText('Basement shelf ★');
      await app.go(`/${kind}`);
      // delete an entry with items: move them
      const busy = page.locator('.loclist > li').filter({ has: page.locator('.loc-name', { hasText: kind === 'locations' ? 'Attic' : 'Camping' }) });
      await busy.locator('.loc-more > summary').click();
      await busy.locator('a.btn-danger').click();
      await expect(page.locator('#locdel')).toBeVisible();
      await page.locator('#locdel select[name=target]').selectOption({ label: 'Basement shelf (0)' });
      await page.locator('#locdel button.btn-danger-solid').click();
      await expect(page).toHaveURL(new RegExp(`/${kind}`));
      await expect(page.locator('.loclist > li').filter({ has: page.locator('.loc-name', { hasText: 'Basement shelf' }) }).locator('.loc-count')).toHaveText('2 items');
      // empty entry: delete with confirm
      await app.go(`/${kind}`);
      await page.locator('#addloc input[name=name]').fill('Temporary');
      await page.locator('#addloc button').click();
      const empty = page.locator('.loclist > li').filter({ has: page.locator('.loc-count', { hasText: '0 items' }) }).first();
      const name = (await empty.locator('.loc-name').innerText()).trim();
      await empty.locator('.loc-more > summary').click();
      page.once('dialog', d => d.accept());
      await empty.locator('form[data-confirm] button').click();
      await expect(page.locator('.loclist > li .loc-name', { hasText: name })).toHaveCount(0);
      // filter link
      await page.locator('.loclist > li').filter({ has: page.locator('.loc-name', { hasText: 'Basement shelf' }) }).locator('.loc-count').click();
      await expect(page).toHaveURL(new RegExp(`[?&]${param}=\\d+`));
      await expect(page.locator('#grid .card')).toHaveCount(2);
      await app.go(`/${kind}`);
      await app.noOverflow();
      await app.tapTargets();
    });
  });
}

test('delete a location and leave its items unassigned', async ({ app, page }) => {
  await app.go('/locations/1/delete');
  await page.locator('#locdel input[value=unassign]').check();
  await page.locator('#locdel button.btn-danger-solid').click();
  await expect(page.locator('.hint a[href="/?loc=none"]')).toContainText('3 items without a location');
});
